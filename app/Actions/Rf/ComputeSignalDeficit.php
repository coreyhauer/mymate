<?php

namespace App\Actions\Rf;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

/**
 * How far BELOW its expected receive signal each BACKHAUL radio is running, stored on
 * rf_link_state. Complements chain imbalance: that finds ASYMMETRY between antenna chains, this
 * finds ABSOLUTE underperformance - a link can be perfectly balanced and still terrible.
 * "A three-mile link with modulation of 2x with -78 RSSI is not what we expect if the link is
 * LOS" (Corey 2026-08-12).
 *
 * SCOPE = links, not devices. It walks site_links whose two endpoint devices are resolved, so
 * each end's expectation uses the OTHER end's actual antenna. Computing per-device and assuming
 * the same antenna at both ends overestimates expected signal by ~9 dB on a sector->CPE hop
 * (LiteAP 16 dBi vs PowerBeam 25), which would manufacture deficits. It also means LiteAP GPS
 * sector APs are naturally out of scope, per Corey: "you can skip LAPGPS devices for our p2p
 * analysis... if there is a LAPGPS in use, it's almost certainly a temp setup" - though a
 * backhaul landing on one IS reported, because that is a temp install someone may have forgotten.
 *
 * ⚠️ UNIT TRAP: `rf_link_state.distance_mi` IS NOT MILES. The puller maps LibreNMS's `distance`
 * sensor straight through and that sensor is KILOMETRES - verified on marshall2codyP, which
 * stores 1.895 and is 1.890 km by GPS haversine. Treating it as miles adds 4.1 dB of phantom
 * path loss to every link.
 *
 * ⚠️ TX POWER DIFFERS BY PLATFORM: airOS `power/airos-tx` is RADIO power, so both antenna gains
 * are added. AF-LTU `airos-af-ltu-tx-eirp` is EIRP and ALREADY includes the near antenna -
 * adding it again double-counts ~27 dB.
 *
 * VALIDATION: run against the 848 AF-LTU links where the firmware reports its own `ideal`, this
 * budget back-computes an implied antenna gain of 26.6 dBi median vs the 27 dBi StarterDish they
 * actually carry - 0.4 dB. DETECTION FLOOR ~6 dB (the implied-gain IQR is 5.8 dB, i.e. the
 * method's own scatter). Real faults run 25-55 dB.
 */
class ComputeSignalDeficit
{
    /** A radio with nothing associated parks at the floor; that is not a fault. */
    private const NOISE_FLOOR_DBM = -95.0;

    public const MIN_MEANINGFUL_DB = 6.0;

    /** @return array<string,int|array> */
    public function handle(): array
    {
        $gains = (array) config('mymate.antenna_gain_dbi', []);

        // The radio MODEL is the one input MyMate does not hold: devices.model is empty for 3,375
        // of the 3,418 link endpoints, and where set it carries MikroTik part numbers, never the
        // Ubiquiti model the gain table is keyed on. LibreNMS has it as devices.hardware, so read
        // it over the existing _librenms connection and match on management IP.
        // The '_librenms' connection is registered lazily by LibreNmsMysqlSource, so it does not
        // exist unless that class has already run this request. Register it the same way.
        $c = (array) config('mymate.librenms_rf', []);
        if (($c['host'] ?? null) === null) {
            return ['links' => 0, 'computed' => 0, 'skipped_floor' => 0, 'skipped_no_gain' => 0,
                'skipped_no_data' => 0, 'flagged' => 0, 'lapgps_backhauls' => [],
                'note' => 'librenms_rf not configured'];
        }
        Config::set('database.connections._librenms', [
            'driver' => 'mysql', 'host' => $c['host'], 'port' => $c['port'] ?? 3306,
            'database' => $c['database'] ?? 'librenms', 'username' => $c['username'],
            'password' => $c['password'], 'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
        ]);
        DB::purge('_librenms');

        $hw = [];
        foreach (DB::connection('_librenms')->select(
            "SELECT hostname, hardware FROM devices WHERE hardware IS NOT NULL AND hardware <> ''") as $r) {
            $hw[(string) $r->hostname] = (string) $r->hardware;
        }

        $links = DB::select('
            SELECT sl.id,
                   sl.device_a_id AS a, sl.device_b_id AS b,
                   da.name AS a_name, db.name AS b_name,
                   da.mgmt_ip AS a_ip, db.mgmt_ip AS b_ip,
                   sa.rssi_dbm AS a_rssi, sb.rssi_dbm AS b_rssi,
                   sa.tx_power_dbm AS a_tx, sb.tx_power_dbm AS b_tx,
                   sa.distance_mi AS a_km,  sb.distance_mi AS b_km,
                   sa.freq_mhz AS a_f,      sb.freq_mhz AS b_f,
                   sa.rssi_sensor_type AS a_st, sb.rssi_sensor_type AS b_st
              FROM site_links sl
              JOIN devices da ON da.id = sl.device_a_id
              JOIN devices db ON db.id = sl.device_b_id
              LEFT JOIN rf_link_state sa ON sa.device_id = sl.device_a_id
              LEFT JOIN rf_link_state sb ON sb.device_id = sl.device_b_id
             WHERE sl.device_a_id IS NOT NULL AND sl.device_b_id IS NOT NULL');

        $out = ['links' => 0, 'computed' => 0, 'skipped_floor' => 0, 'skipped_no_gain' => 0,
            'skipped_no_data' => 0, 'flagged' => 0, 'lapgps_backhauls' => []];
        $updates = [];

        foreach ($links as $l) {
            $out['links']++;
            $mA = $hw[(string) $l->a_ip] ?? null;
            $mB = $hw[(string) $l->b_ip] ?? null;
            $gA = $this->gainFor($mA, $gains);
            $gB = $this->gainFor($mB, $gains);

            // A backhaul terminating on a sector AP is almost certainly a forgotten temp setup -
            // surface it rather than silently modelling it.
            foreach ([[$mA, $l->a_name], [$mB, $l->b_name]] as [$m, $n]) {
                if (stripos((string) $m, 'liteap') !== false) {
                    $out['lapgps_backhauls'][] = $n;
                }
            }

            foreach ([['a', 'b', $gA, $gB], ['b', 'a', $gB, $gA]] as [$near, $far, $gNear, $gFar]) {
                $rssi = $l->{$near.'_rssi'};
                $tx = $l->{$near.'_tx'};
                $km = $l->{$near.'_km'};
                $f = $l->{$near.'_f'};
                if ($rssi === null || $tx === null || $km === null || $km <= 0.05 || $f === null || $f < 400) {
                    $out['skipped_no_data']++;

                    continue;
                }
                if ((float) $rssi <= self::NOISE_FLOOR_DBM) {
                    $out['skipped_floor']++;

                    continue;
                }
                if ($gNear === null || $gFar === null) {
                    $out['skipped_no_gain']++;

                    continue;
                }

                $fspl = 32.44 + 20 * log10((float) $km) + 20 * log10((float) $f);
                $isEirp = str_contains((string) $l->{$near.'_st'}, 'af-ltu');
                // EIRP already contains the NEAR antenna; radio tx power contains neither.
                $expected = $isEirp
                    ? (float) $tx + $gFar - $fspl
                    : (float) $tx + $gNear + $gFar - $fspl;

                $deficit = $expected - (float) $rssi;
                if ($deficit >= self::MIN_MEANINGFUL_DB) {
                    $out['flagged']++;
                }
                $out['computed']++;
                $updates[] = ['id' => (int) $l->{$near}, 'exp' => round($expected, 1),
                    'raw' => $deficit, 'g' => $gNear, 'model' => (string) ($near === 'a' ? $mA : $mB)];
            }
        }

        $out['lapgps_backhauls'] = array_values(array_unique($out['lapgps_backhauls']));

        // ---------------------------------------------------------------------------------
        // PER-MODEL CALIBRATION - the difference between a usable detector and a wall of noise.
        //
        // The raw budget's fleet median deficit is +8.0 dB, and it tracks the ASSUMED gain
        // (27 dBi -> 9.3, 25 -> 6.0, 29 -> 11.6). A healthy fleet cannot really be 8 dB down
        // everywhere; that offset is model error - nominal dish gain is optimistic once cable,
        // connector, radome and pointing losses are real, and FSPL ignores excess path loss.
        // Flagging on the raw number would have flagged 1,044 of 1,722 ends (61%).
        //
        // So the stored deficit is measured against WHAT THIS MODEL ACTUALLY ACHIEVES on this
        // network: subtract the per-model median. Most links are fine, so that median IS the
        // healthy baseline, and it silently absorbs a wrong nominal gain - which is what makes
        // the "mostly safe" 27 dBi StarterDish assumption on Rockets/ISO Stations safe to carry.
        // Same lesson as chain imbalance: compare against peers/history, never against theory.
        // ---------------------------------------------------------------------------------
        $byModel = [];
        foreach ($updates as $u) {
            $byModel[$u['model']][] = $u['raw'];
        }
        $offset = [];
        foreach ($byModel as $m => $vals) {
            sort($vals);
            $n = count($vals);
            // Too few samples to trust a median -> no calibration rather than a bad one.
            $offset[$m] = $n >= 8 ? $vals[intdiv($n, 2)] : 0.0;
            $out['calibration'][$m] = ['n' => $n, 'offset_db' => round($offset[$m], 1)];
        }

        $out['flagged'] = 0;
        foreach ($updates as $i => $u) {
            $cal = $u['raw'] - ($offset[$u['model']] ?? 0.0);
            $updates[$i]['def'] = round($cal, 1);
            if ($cal >= self::MIN_MEANINGFUL_DB) {
                $out['flagged']++;
            }
        }

        foreach (array_chunk($updates, 400) as $chunk) {
            $ids = implode(',', array_column($chunk, 'id'));
            $e = $d = $g = '';
            foreach ($chunk as $u) {
                $e .= " WHEN {$u['id']} THEN {$u['exp']}";
                $d .= " WHEN {$u['id']} THEN {$u['def']}";
                $g .= " WHEN {$u['id']} THEN {$u['g']}";
            }
            DB::statement("UPDATE rf_link_state SET
                expected_rssi_dbm = CASE device_id{$e} END,
                signal_deficit_db = CASE device_id{$d} END,
                deficit_method    = 'link_budget',
                deficit_gain_dbi  = CASE device_id{$g} END,
                deficit_at        = now()
              WHERE device_id IN ({$ids})");
        }

        return $out;
    }

    /** Longest matching model key wins, so "PowerBeam 5AC 620" beats "PowerBeam 5AC". */
    private function gainFor(?string $model, array $gains): ?float
    {
        $model = trim((string) $model);
        if ($model === '') {
            return null;
        }
        if (isset($gains[$model])) {
            return (float) $gains[$model];
        }
        $best = null;
        $bestLen = 0;
        foreach ($gains as $k => $v) {
            if (stripos($model, (string) $k) !== false && strlen((string) $k) > $bestLen) {
                $best = (float) $v;
                $bestLen = strlen((string) $k);
            }
        }

        return $best;
    }
}
