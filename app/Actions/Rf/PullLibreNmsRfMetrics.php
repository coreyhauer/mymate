<?php

namespace App\Actions\Rf;

use App\Models\Device;
use App\Models\Setting;
use App\Services\Import\LibreNms\LibreNmsMysqlSource;
use App\Services\Rf\LibreNmsRfSource;
use App\Support\EngineLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Pull RF/link-health metrics from LibreNMS into rf_link_samples (raw, ~5-min resolution) and
 * rf_link_state (latest-known, one row per device) - see
 * scratchpad/link-health-design/backhaul-link-health-design.md §2. Matches LibreNMS devices to
 * My Mate devices by management IP (LibreNMS stores it directly in `hostname` for this
 * fleet). Never throws: an unconfigured install or an unreachable LibreNMS both return a
 * "skipped" summary rather than an exception, so the scheduled job (App\Jobs\
 * PullLibreNmsRfMetricsJob) never fills the log with a retry storm.
 *
 * `capacity` and `ccq` are never pulled. `capacity` is a dead airOS OID - 98% of readings are
 * exactly 0 (design §1a); `rate` (negotiated PHY rate) is used instead, which is what the
 * design calls "the real capacity number". `ccq` is unreliable for airOS - 43% of readings
 * are the exact value 33, a parsing artifact, not real signal. The design doc's compromise was
 * to store ccq anyway and just exclude it from scoring; this implementation instead follows
 * the brief's stronger instruction to skip it outright - storing an admittedly-fake number
 * with no plan to ever populate it accurately just costs disk for nothing.
 *
 * ONE ROW PER DEVICE PER PULL, not per sensor_index - a real inspection of live
 * wireless_sensors data (2026-07-30) found `sensor_index` does NOT line up across sensor
 * classes for standard airOS: "Overall RSSI" reports under index '0', its per-chain siblings
 * under '1.1'/'1.2', while "Noise Floor" on the SAME device reports under index '1' - three
 * different index values on one physical radio. Grouping by (device_id, sensor_index) as
 * originally designed silently prevented rssi and noise-floor from EVER landing in the same
 * group, which meant derived SNR was always null in practice - caught in production
 * (2026-07-31) when a live dry-run showed 0 derived-SNR rows despite ~30k rssi rows. The fix:
 * resolve each metric class to one representative value across ALL of a device's sensor rows
 * for that class (pickReading() already does exactly this kind of same-class disambiguation -
 * "Overall" preferred, "Remote" dropped, worst-of-remaining-ties as a fallback), then combine
 * the per-class values into a single row per device. This also means a genuinely multi-carrier
 * device (Aviat WTM 1+1 diversity, ~10 devices, sensor_index like "Carrier1/1"/"Carrier1/2")
 * collapses its two carriers into one row via the same worst-of-ties fallback - which is
 * actually the right behaviour for alerting ("take the worse carrier", design §1a); the
 * schema still carries a `sensor_index` column for a future revision that wants to keep
 * carriers separate for display, but this ingestion stage always writes it blank.
 *
 * ============================================================================================
 * SIGNAL/dBm CORRECTNESS (2026-07-31 incident fix - see git history for the full postmortem):
 * ============================================================================================
 *
 * The very first version of this pull (8027571/afe3307) mapped `sensor_class='rssi'` straight
 * onto `rssi_dbm` for every platform. That is wrong: on plain airOS (sensor_type='airos'),
 * `rssi` is a UNITLESS 0-90ish index (airOS's own "signal quality" figure - LibreNMS just
 * calls its class "rssi"), NOT dBm. The real received-signal-in-dBm reading for plain airOS is
 * sensor_class='power', sensor_type='airos-rx', descr 'Signal Level'. Storing the unitless
 * index as if it were dBm, then deriving SNR as index-minus-noise-floor, produced values that
 * cannot exist in reality: rssi_dbm up to +71, snr_db up to 162. Proven live:
 *
 *   device 10.100.6.138 (plain airOS): rssi/airos 'Overall RSSI' = 38 (unitless, WRONG to use)
 *                                       power/airos-rx 'Signal Level' = -58 (real dBm, correct)
 *                                       mca-status ground truth: signal=-58 (agrees with power)
 *
 * The trap is platform-specific and does NOT generalise - on Wave/AirFiber-60
 * (sensor_type='airos-af60-l'/'-r'), sensor_class='rssi' genuinely IS dBm ('Local RSSI'/'Remote
 * RSSI', -76..-28 live). So the fix cannot just "use power instead of rssi everywhere" - it has
 * to key off sensor_type, not sensor_class alone, because LibreNMS overloads sensor_class
 * across platforms with different real-world units. RECEIVED_SIGNAL_TYPES/NOISE_FLOOR_TYPES/
 * NATIVE_SNR_TYPES below are the derived mapping (from a full GROUP BY sensor_class,
 * sensor_type, sensor_descr count/min/max/avg sweep of live LibreNMS data, 2026-07-31):
 *
 *   Platform (sensor_type)              class   descr                    dBm?  Notes
 *   -----------------------------------  ------  -----------------------  ----  --------------------------------
 *   airos                                power   Signal Level             YES   plain airOS RX - use this
 *   airos                                rssi    Overall/Chain 1/Chain 2  NO    unitless 0-90 index - NEVER use
 *   airos                                noise-floor  Noise Floor         YES   real dBm noise floor
 *   airos-af60-l                         rssi    Local RSSI               YES   Wave/AF60 - "Local" = this end
 *   airos-af60-r                         rssi    Remote RSSI              YES   far end's own RX - drop (remote)
 *   airos-af60-l                         snr     Local SNR                YES   native SNR, dB
 *   airos-af-rx                          power   Rx Chain 0/1 Power       YES   plain airFiber (non-LTU, non-60)
 *   airos-af-tx                          power   Tx Power                 --    TX, never use as signal
 *   airos-af-ltu-rx-chain-0/1            power   RX Power Chain 0/1       YES   AF-LTU actual RX per chain
 *   airos-af-ltu-ideal-rx-chain-0/1      power   RX Ideal Power Chain N   NO    IDEAL (theoretical), not actual
 *   airos-af-ltu-tx-eirp                 power   TX EIRP                  --    TX, never use as signal
 *   mikrotik                             rssi    "60G: <link>"            YES   ONLY the 60G wireless-wire line;
 *                                                                                every live row is 60G-prefixed
 *   mikrotik                             noise-floor  "<band>: <ssid>"    YES   real dBm noise floor
 *   mikrotik                             (none)  --                       NO   regular 2.4/5/9G MikroTik wifi has
 *                                                                                NO dBm signal sensor in LibreNMS
 *                                                                                at all (~4,600 devices) - GAP,
 *                                                                                documented, not guessed at
 *   mimosa-ptp-rx                        power   Rx Power: Horiz/Vert     YES   Mimosa PtP
 *   mimosa-ptp-tx                        power   Tx Power: Horiz/Vert     --    TX, never use as signal
 *   mimosa-rx                            power   Min Rx Power: MIMOSA-*   YES   Mimosa non-PtP
 *   mimosa-tx                            power   Tx Power: MIMOSA-*       --    TX, never use as signal
 *   mimosa (either)                      noise-floor  Rx Noise: Horiz/Vert     YES   real dBm
 *   mimosa (either)                      snr     SNR: Horiz/Vert Chain    YES   native, dB (clamp - live max 97.9)
 *   aviat-wtm-carrier-rsl                rssi    RSL (CarrierX/Y)         YES   Aviat WTM - RSL = received level
 *   aviat-wtm-carrier-txpower            power   TX Power (CarrierX/Y)    --    TX, never use as signal
 *   aviat-wtm-carrier-snr                snr     SNR (CarrierX/Y)         YES   native, dB
 *   saf-integrax-a/b-rx-level            power   Radio-A/B Rx Level       YES   SAF Integrax microwave
 *   saf-integrax-a/b-tx-power            power   Radio-A/B Tx Power       --    TX, never use as signal
 *   pmp-h / pmp-v                        snr     Cambium SNR Horiz/Vert   YES   native, dB - Cambium's only
 *                                                                                signal-adjacent sensor
 *   pmp                                  ssr     Cambium Signal Strength  NO    a proprietary ratio (-12..20),
 *                                               Ratio                           NOT dBm - never use as signal
 *   pmp                                  (none)  --                       NO   Cambium PMP has NO received-signal
 *                                                                                -dBm sensor in LibreNMS at all
 *                                                                                (~45 devices) - GAP, documented
 *   unifi-tx                             power   Tx Power (ng/na)         --   TX only; UniFi has no RX dBm
 *                                                                                sensor either (~3 devices) - GAP,
 *                                                                                out of the requested platform
 *                                                                                list, noted for completeness
 *
 * Two platforms (Cambium PMP, standard MikroTik 2.4/5/9G wifi) have NO usable dBm signal
 * sensor in this LibreNMS install at all - rssi_dbm/rssi_sensor_type simply stay null for
 * those devices rather than falling back to something plausible-looking but wrong (the exact
 * mistake this fix corrects). Native SNR (Cambium's snr-h/snr-v) is still stored where present.
 *
 * SANITY GUARDS: resolved signal/noise-floor/SNR values are clamped to physically-plausible
 * ranges (SIGNAL_MIN/MAX_DBM, NOISE_FLOOR_MIN/MAX_DBM, SNR_MIN/MAX_DB) before being written -
 * an out-of-range value is dropped (null), never stored. This is a second, independent line of
 * defence against the exact failure mode of this incident (a wrong-unit value being silently
 * accepted as real): even if a future LibreNMS schema change or a new platform's sensor_type
 * slips past the allowlists above, an implausible value can no longer reach rf_link_state. All
 * rejections in one run are counted and logged as a SINGLE warning (never per-device - this
 * app has a log-flood history, see EngineLog docblock).
 */
class PullLibreNmsRfMetrics
{
    private const WATERMARK_KEY = 'librenms_rf.watermark';

    /** LibreNMS sensor_class => the rf_link_samples column it feeds, for the classes that are
     *  NOT signal/noise-floor/SNR (those three are resolved via the sensor_type-aware maps
     *  below instead - see the class docblock for why sensor_class alone is unsafe for them).
     *  This map is also used as the row-eligibility gate in groupSensors(): any sensor_class
     *  not listed here (and not one of the three signal classes) is dropped immediately. */
    private const CLASS_TO_FIELD = [
        'rssi' => null, // resolved via RECEIVED_SIGNAL_TYPES, not a direct column
        'noise-floor' => null, // resolved via NOISE_FLOOR_TYPES
        'snr' => null, // resolved via NATIVE_SNR_TYPES
        'rate' => 'rate_mbps',
        'utilization' => 'channel_util_pct',
        'power' => 'tx_power_dbm', // see pickReading(): also feeds RECEIVED_SIGNAL_TYPES for the
        // platforms whose real RX dBm sensor happens to live under sensor_class='power'
        'distance' => 'distance_mi',
        'frequency' => 'freq_mhz',
    ];

    /**
     * sensor_type values whose sensor_current is genuine received-signal-in-dBm - see the class
     * docblock table for the live evidence behind each entry. Deliberately an allowlist (not "everything
     * except a denylist of known-bad types") so a sensor_type this fleet has never seen before is
     * excluded by default rather than silently trusted.
     */
    private const RECEIVED_SIGNAL_TYPES = [
        'airos-rx',
        'airos-af-rx',
        'airos-af-ltu-rx-chain-0',
        'airos-af-ltu-rx-chain-1',
        'airos-af60-l',
        'aviat-wtm-carrier-rsl',
        'mimosa-ptp-rx',
        'mimosa-rx',
        'saf-integrax-a-rx-level',
        'saf-integrax-b-rx-level',
    ];

    /** MikroTik's sensor_class='rssi' only ever carries real dBm for 60G wireless-wire links
     *  (live descr always "60G: <link name>"); regular 2.4/5/9G MikroTik wifi reports no rssi
     *  sensor at all in this LibreNMS install. Gated on the descr prefix (not just
     *  sensor_type='mikrotik', which 60G shares with nothing else observed) so a future
     *  non-60G mikrotik rssi row can't silently start being trusted as dBm. */
    private const MIKROTIK_SIGNAL_TYPE = 'mikrotik';

    private const MIKROTIK_SIGNAL_DESCR_PREFIX = '60g';

    /** sensor_type values whose sensor_class='noise-floor' reading is genuine dBm. Live data
     *  (2026-07-31 sweep) shows both Mimosa product lines (PtP and non-PtP) report their
     *  noise floor under the plain sensor_type='mimosa', not the -rx/-tx/-ptp-rx variants used
     *  for power - so only 'mimosa' is listed here, deliberately narrower than
     *  RECEIVED_SIGNAL_TYPES above. */
    private const NOISE_FLOOR_TYPES = ['airos', 'mikrotik', 'mimosa'];

    /** sensor_type values whose sensor_class='snr' reading is a genuine native dB SNR figure
     *  (as opposed to plain airOS, which has no native snr sensor at all and always derives).
     *  Same 'mimosa' (not -rx/-tx/-ptp-rx) note as NOISE_FLOOR_TYPES applies. */
    private const NATIVE_SNR_TYPES = ['airos-af60-l', 'aviat-wtm-carrier-snr', 'mimosa', 'pmp-h', 'pmp-v'];

    /** Physically-plausible ranges - see class docblock "SANITY GUARDS". A value outside its
     *  range is dropped (never stored), and counted for the single end-of-run warning. */
    private const SIGNAL_MIN_DBM = -95.0;

    private const SIGNAL_MAX_DBM = -20.0;

    private const NOISE_FLOOR_MIN_DBM = -110.0;

    private const NOISE_FLOOR_MAX_DBM = -60.0;

    private const SNR_MIN_DB = 0.0;

    private const SNR_MAX_DB = 60.0;

    public function __invoke(?LibreNmsRfSource $source = null): array
    {
        $startedAt = microtime(true);

        if (! config('mymate.librenms_rf.enabled', false)) {
            return $this->summary($startedAt, ['skipped' => 'unconfigured']);
        }

        $source ??= new LibreNmsMysqlSource(
            (string) config('mymate.librenms_rf.host'),
            (int) config('mymate.librenms_rf.port', 3306),
            (string) config('mymate.librenms_rf.database', 'librenms'),
            (string) config('mymate.librenms_rf.username'),
            (string) config('mymate.librenms_rf.password'),
        );

        $since = $this->watermark();

        try {
            $sensors = $source->wirelessSensors($since);
            $errors = $source->portErrorCounters();
        } catch (Throwable $e) {
            EngineLog::warning('rf: librenms pull failed', ['error' => $e->getMessage()]);

            return $this->summary($startedAt, ['skipped' => 'unreachable']);
        }

        if ($sensors === [] && $errors === []) {
            return $this->summary($startedAt, [
                'pulled_sensor_rows' => 0, 'pulled_port_rows' => 0,
                'matched_devices' => 0, 'samples_written' => 0, 'state_written' => 0,
            ]);
        }

        // Full ip -> My Mate device_id map, loaded once (no giant IN(...) - the fleet is
        // ~25k devices, trivial to hold in memory, and avoids the bind-parameter ceiling a
        // whereIn() over every pulled IP would risk on a full/initial pull).
        $deviceIdByIp = Device::query()->pluck('id', 'mgmt_ip')->all();

        [$candidates, $signalCandidates, $lastUpdateByDevice, $maxSourceLastUpdate] = $this->groupSensors($sensors, $deviceIdByIp);
        $errorsByDevice = $this->indexErrors($errors, $deviceIdByIp);

        $ts = now();
        $sampleRows = [];
        $rejections = [];

        $chainRows = [];
        foreach ($candidates as $deviceId => $byClass) {
            $sampleRows[] = $this->buildRow(
                $deviceId,
                $byClass,
                $signalCandidates[$deviceId] ?? [],
                $lastUpdateByDevice[$deviceId] ?? null,
                $errorsByDevice[$deviceId] ?? null,
                $ts,
                $rejections,
            );

            foreach ($this->buildChainRows($deviceId, $signalCandidates[$deviceId] ?? [], $ts, $rejections) as $cr) {
                $chainRows[] = $cr;
            }
        }
        // A device can have signal candidates but nothing in $candidates only if it had zero
        // eligible sensor rows at all, which can't happen (signalCandidates is a subset of
        // candidates' rows) - no extra pass needed here.

        // Chain rows go to HISTORY ONLY. rf_link_state is keyed one-row-per-device, so writing
        // them there would have the chains fight each other for the same primary key.
        $samplesWritten = $this->writeSamples(array_merge($sampleRows, $chainRows));
        $stateWritten = $this->writeState($sampleRows);

        if ($maxSourceLastUpdate !== null) {
            // Small safety margin, not the exact max: LibreNMS's poll cadence means most
            // sensors land within the same second across a run, and a strict `> max` next
            // time could race a sensor that updates a moment later in that same second.
            $this->saveWatermark(Carbon::parse($maxSourceLastUpdate)->subSeconds(60));
        }

        if ($rejections !== []) {
            // ONE warning for the whole run, never per-device - see class docblock.
            EngineLog::warning('rf: sanity guard rejected implausible readings', [
                'run_devices' => count($candidates),
                'rejected' => $rejections,
            ]);
        }

        return $this->summary($startedAt, [
            'pulled_sensor_rows' => count($sensors),
            'pulled_port_rows' => count($errors),
            'matched_devices' => count($candidates),
            'samples_written' => $samplesWritten,
            'state_written' => $stateWritten,
            'rejected' => $rejections === [] ? null : array_sum($rejections),
        ]);
    }

    /**
     * Classifies one raw LibreNMS sensor row into an internal signal metric key - 'signal'
     * (received signal, real dBm), 'noise_floor' (real dBm) or 'snr_native' (real dB) - or null
     * if it isn't a trustworthy reading for any of those (wrong unit, TX not RX, ideal not
     * actual, remote/far-end, or simply not one of the sensor_types this fleet is known to
     * report real values for). See the class docblock table for the evidence behind every
     * branch.
     */
    private function classifySignalMetric(string $class, ?string $type, ?string $descr): ?string
    {
        if ($class === 'rssi') {
            if ($type !== null && in_array($type, self::RECEIVED_SIGNAL_TYPES, true)) {
                return 'signal';
            }
            if ($type === self::MIKROTIK_SIGNAL_TYPE
                && str_starts_with(strtolower((string) $descr), self::MIKROTIK_SIGNAL_DESCR_PREFIX)) {
                return 'signal';
            }

            // sensor_type='airos' (unitless index) and 'airos-af60-r' (far end's own reading,
            // not this device's) are deliberately excluded here - the core of the 2026-07-31
            // incident was exactly this class being trusted without a sensor_type check.
            return null;
        }

        if ($class === 'power' && $type !== null && in_array($type, self::RECEIVED_SIGNAL_TYPES, true)) {
            return 'signal';
        }

        if ($class === 'noise-floor' && $type !== null && in_array($type, self::NOISE_FLOOR_TYPES, true)) {
            return 'noise_floor';
        }

        if ($class === 'snr' && $type !== null && in_array($type, self::NATIVE_SNR_TYPES, true)) {
            return 'snr_native';
        }

        return null;
    }

    /**
     * Group raw LibreNMS sensor rows two ways:
     *  - $candidates: [device_id][sensor_class] => list of {descr, value} - unchanged from the
     *    original design, still used for rate/utilization/tx-power/distance/frequency (classes
     *    where sensor_class alone IS a safe key - no cross-platform unit ambiguity found there).
     *  - $signalCandidates: [device_id][metric] => list of {descr, value, type} - the
     *    sensor_type-aware classification for signal/noise_floor/snr_native (see
     *    classifySignalMetric() and the class docblock).
     * sensor_index deliberately NOT part of either grouping key - see class docblock.
     *
     * Rows for a LibreNMS device with no matching My Mate device (by mgmt_ip) are dropped here
     * - that's the 16% ceiling the design doc calls out (§7).
     *
     * @param  list<array{device_id:int, ip:string, sensor_class:string, sensor_type:?string, sensor_index:?string, sensor_descr:?string, sensor_current:?float, lastupdate:?string}>  $sensors
     * @param  array<string, int>  $deviceIdByIp
     * @return array{0: array<int, array<string, list<array{descr:?string, value:float}>>>, 1: array<int, array<string, list<array{descr:?string, value:float, type:?string}>>>, 2: array<int, string>, 3: ?string}
     */
    private function groupSensors(array $sensors, array $deviceIdByIp): array
    {
        $candidates = [];
        $signalCandidates = [];
        $lastUpdateByDevice = [];
        $maxSourceLastUpdate = null;

        foreach ($sensors as $s) {
            $deviceId = $deviceIdByIp[$s['ip']] ?? null;
            if ($deviceId === null || ! array_key_exists($s['sensor_class'], self::CLASS_TO_FIELD) || $s['sensor_current'] === null) {
                continue;
            }

            $candidates[$deviceId][$s['sensor_class']][] = ['descr' => $s['sensor_descr'], 'value' => $s['sensor_current']];

            $metric = $this->classifySignalMetric($s['sensor_class'], $s['sensor_type'] ?? null, $s['sensor_descr']);
            if ($metric !== null) {
                $signalCandidates[$deviceId][$metric][] = [
                    'descr' => $s['sensor_descr'], 'value' => $s['sensor_current'], 'type' => $s['sensor_type'] ?? null,
                ];
            }

            if ($s['lastupdate'] !== null) {
                $cur = $lastUpdateByDevice[$deviceId] ?? null;
                if ($cur === null || $s['lastupdate'] > $cur) {
                    $lastUpdateByDevice[$deviceId] = $s['lastupdate'];
                }
                if ($maxSourceLastUpdate === null || $s['lastupdate'] > $maxSourceLastUpdate) {
                    $maxSourceLastUpdate = $s['lastupdate'];
                }
            }
        }

        return [$candidates, $signalCandidates, $lastUpdateByDevice, $maxSourceLastUpdate];
    }

    /**
     * @param  list<array{device_id:int, ip:string, if_errors_in:?int, if_errors_out:?int}>  $errors
     * @param  array<string, int>  $deviceIdByIp
     * @return array<int, array{if_errors_in:?int, if_errors_out:?int}>
     */
    private function indexErrors(array $errors, array $deviceIdByIp): array
    {
        $out = [];
        foreach ($errors as $e) {
            $deviceId = $deviceIdByIp[$e['ip']] ?? null;
            if ($deviceId === null) {
                continue;
            }
            $out[$deviceId] = ['if_errors_in' => $e['if_errors_in'], 'if_errors_out' => $e['if_errors_out']];
        }

        return $out;
    }

    /**
     * Build one rf_link_samples row for a device. Signal/noise-floor/native-SNR are resolved
     * from the sensor_type-aware $signalMetrics pool (see classifySignalMetric()), each run
     * through the physical-plausibility guard() before being trusted. SNR is native where
     * LibreNMS has a trustworthy native sensor (NATIVE_SNR_TYPES), else derived as
     * signal - noise_floor when both are present and individually valid - the derived value is
     * ALSO guarded (a valid-looking signal minus a valid-looking noise floor can still land
     * outside a plausible SNR range if they're actually two different antennas/chains).
     *
     * @param  array<string, list<array{descr:?string, value:float}>>  $byClass
     * @param  array<string, list<array{descr:?string, value:float, type:?string}>>  $signalMetrics
     * @param  array{if_errors_in:?int, if_errors_out:?int}|null  $errors
     * @param  array<string, int>  $rejections
     * @return array<string, mixed>
     */
    /**
     * PER-ANTENNA-CHAIN RSSI rows, in addition to the collapsed device row.
     *
     * WHY: chain imbalance is the signature of water in an RPSMA pigtail - one chain degrades
     * while the other holds, which is invisible in a device-level average and is exactly the
     * failure Corey asked to be able to find. The collapsed row exists because rssi and noise
     * floor use DIFFERENT sensor_index values on airOS, so grouping by index kills SNR
     * derivation fleet-wide (see class docblock); that reasoning applies to SNR, not to RSSI,
     * so the chains can be kept alongside it rather than instead of it.
     *
     * These rows carry ONLY rssi_dbm - deriving SNR per chain would need a matching per-chain
     * noise floor, which airOS does not index compatibly. `sensor_index` is the chain's own
     * descr ("Chain 1"), which is what makes them distinguishable from the device row's ''.
     *
     * Only genuinely per-chain readings qualify: a descr like "Overall RSSI" is the device-level
     * figure again and would double-count, and single-chain radios produce nothing here.
     *
     * @param  array<string, list<array{descr:?string, value:float, type:?string}>>  $signalMetrics
     * @return list<array<string, mixed>>
     */
    private function buildChainRows(int $deviceId, array $signalMetrics, Carbon $ts, array &$rejections): array
    {
        $rows = [];

        foreach ($signalMetrics['signal'] ?? [] as $reading) {
            $descr = trim((string) ($reading['descr'] ?? ''));
            if ($descr === '' || preg_match('/\bchain\s*\d+/i', $descr) !== 1) {
                continue; // device-level ("Overall RSSI") or unlabelled - already in the main row
            }

            $rssi = $this->guard($reading['value'] ?? null, self::SIGNAL_MIN_DBM, self::SIGNAL_MAX_DBM, 'signal_chain', $rejections);
            if ($rssi === null) {
                continue;
            }

            $rows[] = [
                'device_id' => $deviceId,
                'ts' => $ts,
                'sensor_index' => mb_substr($descr, 0, 64),
                'rssi_dbm' => $rssi,
                'rssi_sensor_type' => $reading['type'] ?? null,
                'noise_floor_dbm' => null,
                'snr_db' => null,
                'snr_source' => null,
                'rate_mbps' => null,
                'channel_util_pct' => null,
                'tx_power_dbm' => null,
                'distance_mi' => null,
                'freq_mhz' => null,
                'if_errors_in' => null,
                'if_errors_out' => null,
                'source_lastupdate' => null,
            ];
        }

        return $rows;
    }

    private function buildRow(int $deviceId, array $byClass, array $signalMetrics, ?string $sourceLastupdate, ?array $errors, Carbon $ts, array &$rejections): array
    {
        $signalPick = $this->pickSignalReading($signalMetrics['signal'] ?? []);
        $noisePick = $this->pickSignalReading($signalMetrics['noise_floor'] ?? [], worseIsHigher: true);
        $snrNativePick = $this->pickSignalReading($signalMetrics['snr_native'] ?? []);

        $rssi = $this->guard($signalPick['value'] ?? null, self::SIGNAL_MIN_DBM, self::SIGNAL_MAX_DBM, 'signal', $rejections);
        $noise = $this->guard($noisePick['value'] ?? null, self::NOISE_FLOOR_MIN_DBM, self::NOISE_FLOOR_MAX_DBM, 'noise_floor', $rejections);
        $snrNative = $this->guard($snrNativePick['value'] ?? null, self::SNR_MIN_DB, self::SNR_MAX_DB, 'snr_native', $rejections);

        $snr = $snrNative;
        $snrSource = $snrNative !== null ? 'native' : null;
        if ($snr === null && $rssi !== null && $noise !== null) {
            $snr = $this->guard($rssi - $noise, self::SNR_MIN_DB, self::SNR_MAX_DB, 'snr_derived', $rejections);
            $snrSource = $snr !== null ? 'derived' : null;
        }

        // Provenance: which LibreNMS sensor_type the surviving rssi_dbm actually came from, so
        // a future audit never has to guess (see class docblock "SANITY GUARDS"). Null when
        // rssi was rejected by the guard or there was no candidate at all.
        $rssiSensorType = $rssi !== null ? ($signalPick['type'] ?? null) : null;

        $rateRaw = $this->pickReading('rate', $byClass['rate'] ?? []); // bps

        return [
            'device_id' => $deviceId,
            'ts' => $ts,
            'sensor_index' => '', // see class docblock - not meaningfully populated by this stage
            'rssi_dbm' => $rssi,
            'rssi_sensor_type' => $rssiSensorType,
            'noise_floor_dbm' => $noise,
            'snr_db' => $snr,
            'snr_source' => $snrSource,
            'rate_mbps' => $rateRaw !== null ? $rateRaw / 1_000_000 : null,
            'channel_util_pct' => $this->pickReading('utilization', $byClass['utilization'] ?? []),
            'tx_power_dbm' => $this->pickReading('power', $byClass['power'] ?? []),
            'distance_mi' => $this->pickReading('distance', $byClass['distance'] ?? []),
            'freq_mhz' => $this->pickReading('frequency', $byClass['frequency'] ?? []),
            'if_errors_in' => $errors['if_errors_in'] ?? null,
            'if_errors_out' => $errors['if_errors_out'] ?? null,
            'source_lastupdate' => $sourceLastupdate,
        ];
    }

    /**
     * Reject (null out) a resolved value that falls outside a physically-plausible range - see
     * class docblock "SANITY GUARDS". Counts the rejection (by $metric) into $rejections for
     * the single end-of-run warning; never logs per-value/per-device.
     *
     * @param  array<string, int>  $rejections
     */
    private function guard(?float $value, float $min, float $max, string $metric, array &$rejections): ?float
    {
        if ($value === null) {
            return null;
        }
        if ($value < $min || $value > $max) {
            $rejections[$metric] = ($rejections[$metric] ?? 0) + 1;

            return null;
        }

        return $value;
    }

    /**
     * Resolve one representative {value, type} from possibly-multiple sensor_type-aware signal
     * candidates sharing a (device_id, metric) - e.g. AF-LTU's two RX chains, or a genuinely
     * multi-carrier Aviat WTM. Preference order mirrors pickReading() below: an "overall" descr
     * wins outright, a "remote" descr is dropped when a non-remote candidate exists, and any
     * remaining tie resolves to the worse reading (most negative for signal/SNR, or the least
     * negative/"closest to the noise floor being bad" for noise-floor when $worseIsHigher).
     *
     * @param  list<array{descr:?string, value:float, type:?string}>  $candidates
     * @return array{descr:?string, value:float, type:?string}|null
     */
    private function pickSignalReading(array $candidates, bool $worseIsHigher = false): ?array
    {
        if ($candidates === []) {
            return null;
        }
        if (count($candidates) === 1) {
            return $candidates[0];
        }

        $descrOf = static fn (array $c): string => strtolower((string) ($c['descr'] ?? ''));

        $overall = array_values(array_filter($candidates, static fn ($c) => str_contains($descrOf($c), 'overall')));
        if ($overall !== []) {
            return $overall[0];
        }

        $nonRemote = array_values(array_filter($candidates, static fn ($c) => ! str_contains($descrOf($c), 'remote')));
        $pool = $nonRemote !== [] ? $nonRemote : $candidates;

        usort($pool, static fn ($a, $b) => $worseIsHigher ? $b['value'] <=> $a['value'] : $a['value'] <=> $b['value']);

        return $pool[0];
    }

    /**
     * Resolve one representative value from possibly-multiple LibreNMS sensor rows sharing a
     * (device_id, sensor_class) for the non-signal classes (rate/utilization/tx-power/
     * distance/frequency) - see the class docblock for why signal/noise-floor/snr use
     * pickSignalReading() instead. Preference order:
     *
     *  1. A descr containing "overall" wins outright (airOS's own chain-combined figure).
     *  2. Otherwise, drop any descr containing "remote" (keep this device's own/"local"/
     *     unlabelled reading - a device reporting the far end's number too must not be
     *     treated as this end's reading).
     *  3. `power` additionally REQUIRES a "tx" descr after that filtering (an RX chain power
     *     reading must never be stored as tx_power_dbm); `rate` PREFERS (not requires) "rx"
     *     over "tx", matching the rssi convention of "what this device receives".
     *  4. Any remaining ties (e.g. unlabelled per-antenna "Chain 1"/"Chain 2" rows with no
     *     "Overall" present, or a genuinely multi-carrier device's two carriers) resolve to
     *     the conservative/worse reading for rssi/snr (MIN) and to the higher reading for
     *     noise-floor/power/rate/utilization (MAX) - mirrors the "take the worse carrier for
     *     alerting" principle from the design doc §1a.
     *
     * @param  list<array{descr:?string, value:float}>  $candidates
     */
    private function pickReading(string $sensorClass, array $candidates): ?float
    {
        if ($candidates === []) {
            return null;
        }
        if (count($candidates) === 1) {
            return $candidates[0]['value'];
        }

        $descrOf = static fn (array $c): string => strtolower((string) ($c['descr'] ?? ''));

        $overall = array_values(array_filter($candidates, static fn ($c) => str_contains($descrOf($c), 'overall')));
        if ($overall !== []) {
            return $overall[0]['value'];
        }

        $nonRemote = array_values(array_filter($candidates, static fn ($c) => ! str_contains($descrOf($c), 'remote')));
        $pool = $nonRemote !== [] ? $nonRemote : $candidates;

        if ($sensorClass === 'power') {
            // Required, not preferred: an RX-chain power reading is not TX power.
            $pool = array_values(array_filter($pool, static fn ($c) => str_contains($descrOf($c), 'tx')));
        } elseif ($sensorClass === 'rate') {
            $rx = array_values(array_filter($pool, static fn ($c) => str_contains($descrOf($c), 'rx')));
            if ($rx !== []) {
                $pool = $rx;
            }
        }

        if ($pool === []) {
            return null;
        }
        if (count($pool) === 1) {
            return $pool[0]['value'];
        }

        $values = array_column($pool, 'value');

        return match ($sensorClass) {
            'rssi', 'snr' => min($values),
            default => max($values),
        };
    }

    /** @param  list<array<string, mixed>>  $rows */
    private function writeSamples(array $rows): int
    {
        if ($rows === []) {
            return 0;
        }

        $written = 0;
        try {
            foreach (array_chunk($rows, 1000) as $chunk) {
                DB::table('rf_link_samples')->insert($chunk);
                $written += count($chunk);
            }
        } catch (Throwable $e) {
            EngineLog::warning('rf: sample write failed', ['rows' => count($rows), 'error' => $e->getMessage()]);
        }

        return $written;
    }

    /**
     * Upsert rf_link_state, one row per device - each sample row already IS the one row for
     * its device (see class docblock: this stage collapses to one row per device per pull),
     * so this is a straight upsert, no further picking needed.
     *
     * @param  list<array<string, mixed>>  $sampleRows
     */
    private function writeState(array $sampleRows): int
    {
        if ($sampleRows === []) {
            return 0;
        }

        $now = now();
        $rows = array_map(static fn (array $r): array => [
            'device_id' => $r['device_id'],
            'sensor_index' => $r['sensor_index'],
            'rssi_dbm' => $r['rssi_dbm'],
            'rssi_sensor_type' => $r['rssi_sensor_type'],
            'noise_floor_dbm' => $r['noise_floor_dbm'],
            'snr_db' => $r['snr_db'],
            'snr_source' => $r['snr_source'],
            'rate_mbps' => $r['rate_mbps'],
            'channel_util_pct' => $r['channel_util_pct'],
            'tx_power_dbm' => $r['tx_power_dbm'],
            'distance_mi' => $r['distance_mi'],
            'freq_mhz' => $r['freq_mhz'],
            'if_errors_in' => $r['if_errors_in'],
            'if_errors_out' => $r['if_errors_out'],
            'source_lastupdate' => $r['source_lastupdate'],
            'synced_at' => $now,
        ], $sampleRows);

        $written = 0;
        try {
            $updateCols = ['sensor_index', 'rssi_dbm', 'rssi_sensor_type', 'noise_floor_dbm', 'snr_db', 'snr_source', 'rate_mbps', 'channel_util_pct', 'tx_power_dbm', 'distance_mi', 'freq_mhz', 'if_errors_in', 'if_errors_out', 'source_lastupdate', 'synced_at'];
            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('rf_link_state')->upsert($chunk, ['device_id'], $updateCols);
                $written += count($chunk);
            }
        } catch (Throwable $e) {
            EngineLog::warning('rf: state upsert failed', ['rows' => count($rows), 'error' => $e->getMessage()]);
        }

        return $written;
    }

    private function watermark(): ?Carbon
    {
        // Eloquent hydration (not the query builder's ->value()), so the `value` column's
        // json cast actually runs - a raw query-builder read would return the still-quoted
        // JSON string ("2026-...") rather than the decoded PHP string.
        $value = Setting::where('key', self::WATERMARK_KEY)->first()?->value;

        return $value ? Carbon::parse($value) : null;
    }

    private function saveWatermark(Carbon $at): void
    {
        Setting::updateOrCreate(['key' => self::WATERMARK_KEY], ['value' => $at->toIso8601String()]);
    }

    /** @param  array<string, mixed>  $extra */
    private function summary(float $startedAt, array $extra): array
    {
        return $extra + ['ms' => (int) round((microtime(true) - $startedAt) * 1000)];
    }
}
