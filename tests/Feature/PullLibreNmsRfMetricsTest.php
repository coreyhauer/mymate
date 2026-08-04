<?php

namespace Tests\Feature;

use App\Actions\Rf\PullLibreNmsRfMetrics;
use App\Models\Device;
use App\Models\RfLinkState;
use App\Services\Rf\LibreNmsRfSource;
use DateTimeInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class PullLibreNmsRfMetricsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['mymate.librenms_rf.enabled' => true]);
    }

    /** @param  list<array{device_id:int, ip:string, sensor_class:string, sensor_type:?string, sensor_index:?string, sensor_descr:?string, sensor_current:?float, lastupdate:?string}>  $sensors */
    private function fakeSource(array $sensors, array $portErrors = []): LibreNmsRfSource
    {
        return new class($sensors, $portErrors) implements LibreNmsRfSource
        {
            public function __construct(private array $sensors, private array $portErrors) {}

            public function wirelessSensors(?DateTimeInterface $since = null): array
            {
                return $this->sensors;
            }

            public function portErrorCounters(): array
            {
                return $this->portErrors;
            }
        };
    }

    private function sensor(
        int $deviceId,
        string $ip,
        string $class,
        ?string $type,
        ?string $descr,
        float $value,
        ?string $index = null,
        ?string $lastupdate = '2026-07-30 12:00:00',
    ): array {
        return [
            'device_id' => $deviceId, 'ip' => $ip, 'sensor_class' => $class, 'sensor_type' => $type,
            'sensor_index' => $index, 'sensor_descr' => $descr, 'sensor_current' => $value,
            'lastupdate' => $lastupdate,
        ];
    }

    public function test_no_op_when_librenms_is_not_configured(): void
    {
        config(['mymate.librenms_rf.enabled' => false]);
        $device = Device::factory()->create(['mgmt_ip' => '10.0.0.1']);

        $result = app(PullLibreNmsRfMetrics::class)($this->fakeSource([
            $this->sensor(1, '10.0.0.1', 'power', 'airos-rx', 'Signal Level', -55.0),
        ]));

        $this->assertSame('unconfigured', $result['skipped']);
        $this->assertSame(0, DB::table('rf_link_samples')->count());
        $this->assertNull(RfLinkState::find($device->id));
    }

    public function test_maps_sensor_rows_onto_the_matching_mymate_device_by_ip(): void
    {
        $matched = Device::factory()->create(['mgmt_ip' => '10.0.0.1']);
        Device::factory()->create(['mgmt_ip' => '10.0.0.99']); // unrelated device, must stay untouched

        $result = app(PullLibreNmsRfMetrics::class)($this->fakeSource([
            $this->sensor(1, '10.0.0.1', 'power', 'airos-rx', 'Signal Level', -55.0),
            // No My Mate device has this ip - must be silently dropped, not fatal.
            $this->sensor(2, '10.0.0.250', 'power', 'airos-rx', 'Signal Level', -70.0),
        ]));

        $this->assertSame(1, $result['matched_devices']);
        $sample = DB::table('rf_link_samples')->where('device_id', $matched->id)->first();
        $this->assertNotNull($sample);
        $this->assertEqualsWithDelta(-55.0, $sample->rssi_dbm, 0.001);

        $this->assertSame(0, DB::table('rf_link_samples')
            ->whereNotIn('device_id', [$matched->id])
            ->count());
    }

    // ================================================================================
    // Per-platform received-signal sensor selection (see PullLibreNmsRfMetrics class
    // docblock mapping table - derived from a live 2026-07-31 LibreNMS sweep).
    // ================================================================================

    public function test_plain_airos_uses_power_signal_level_not_the_unitless_rssi_index(): void
    {
        $device = Device::factory()->create(['mgmt_ip' => '10.0.0.1']);

        app(PullLibreNmsRfMetrics::class)($this->fakeSource([
            // The unitless airOS "quality" index - proven live to run 0-90, NOT dBm.
            $this->sensor(1, '10.0.0.1', 'rssi', 'airos', 'Overall RSSI', 38.0),
            $this->sensor(1, '10.0.0.1', 'rssi', 'airos', 'RSSI: Chain 1', 36.0),
            $this->sensor(1, '10.0.0.1', 'rssi', 'airos', 'RSSI: Chain 2', 37.0),
            // The real received signal, in dBm.
            $this->sensor(1, '10.0.0.1', 'power', 'airos-rx', 'Signal Level', -58.0),
            $this->sensor(1, '10.0.0.1', 'power', 'airos-tx', 'Tx Power', 27.0),
        ]));

        $state = RfLinkState::find($device->id);
        $this->assertEqualsWithDelta(-58.0, $state->rssi_dbm, 0.001);
        $this->assertSame('airos-rx', $state->rssi_sensor_type);
    }

    public function test_airos_unitless_rssi_index_is_never_used_as_dbm_even_with_no_power_sensor(): void
    {
        // A device that (for whatever reason) only reports the class='rssi' unitless index and
        // no class='power' sensor must end up with NO rssi_dbm at all - never the index value
        // reinterpreted as dBm. This is the exact 2026-07-31 incident shape.
        $device = Device::factory()->create(['mgmt_ip' => '10.0.0.1']);

        app(PullLibreNmsRfMetrics::class)($this->fakeSource([
            $this->sensor(1, '10.0.0.1', 'rssi', 'airos', 'Overall RSSI', 71.0),
        ]));

        $sample = DB::table('rf_link_samples')->where('device_id', $device->id)->first();
        $this->assertNotNull($sample); // device still gets a row - just with no signal data
        $this->assertNull($sample->rssi_dbm);
        $this->assertNull($sample->rssi_sensor_type);
    }

    public function test_af_ltu_uses_actual_rx_chain_power_not_ideal_or_tx_eirp(): void
    {
        // TX-vs-RX and ideal-vs-actual traps in one device: TX EIRP contains "tx" (like a
        // genuine TX-power descr) and "ideal" chains look identical to actual ones except for
        // the sensor_type - both must be excluded from signal.
        $device = Device::factory()->create(['mgmt_ip' => '10.0.0.1']);

        app(PullLibreNmsRfMetrics::class)($this->fakeSource([
            $this->sensor(1, '10.0.0.1', 'power', 'airos-af-ltu-rx-chain-0', 'RX Power Chain 0', -55.0),
            $this->sensor(1, '10.0.0.1', 'power', 'airos-af-ltu-rx-chain-1', 'RX Power Chain 1', -60.0),
            $this->sensor(1, '10.0.0.1', 'power', 'airos-af-ltu-ideal-rx-chain-0', 'RX Ideal Power Chain 0', -40.0),
            $this->sensor(1, '10.0.0.1', 'power', 'airos-af-ltu-ideal-rx-chain-1', 'RX Ideal Power Chain 1', -41.0),
            $this->sensor(1, '10.0.0.1', 'power', 'airos-af-ltu-tx-eirp', 'TX EIRP', 47.0),
        ]));

        $state = RfLinkState::find($device->id);
        // Worse of the two genuinely-actual RX chains, never the ideal (-40/-41) or EIRP (47) values.
        $this->assertEqualsWithDelta(-60.0, $state->rssi_dbm, 0.001);
        $this->assertSame('airos-af-ltu-rx-chain-1', $state->rssi_sensor_type);
    }

    public function test_plain_airfiber_uses_rx_chain_power(): void
    {
        $device = Device::factory()->create(['mgmt_ip' => '10.0.0.1']);

        app(PullLibreNmsRfMetrics::class)($this->fakeSource([
            $this->sensor(1, '10.0.0.1', 'power', 'airos-af-rx', 'Rx Chain 0 Power', -50.0),
            $this->sensor(1, '10.0.0.1', 'power', 'airos-af-tx', 'Tx Power', 55.0),
        ]));

        $state = RfLinkState::find($device->id);
        $this->assertEqualsWithDelta(-50.0, $state->rssi_dbm, 0.001);
        $this->assertSame('airos-af-rx', $state->rssi_sensor_type);
    }

    public function test_wave_af60_uses_local_rssi_which_is_genuinely_dbm_on_this_platform(): void
    {
        // Unlike plain airOS, class='rssi' on Wave/AF60 genuinely IS dBm - the mapping is keyed
        // on sensor_type, not sensor_class, precisely so this platform isn't wrongly excluded
        // by the same rule that excludes plain airOS.
        $device = Device::factory()->create(['mgmt_ip' => '10.0.0.1']);

        app(PullLibreNmsRfMetrics::class)($this->fakeSource([
            $this->sensor(1, '10.0.0.1', 'rssi', 'airos-af60-l', 'Local RSSI', -48.0),
            $this->sensor(1, '10.0.0.1', 'rssi', 'airos-af60-r', 'Remote RSSI', -61.0),
            $this->sensor(1, '10.0.0.1', 'snr', 'airos-af60-l', 'Local SNR', 18.0),
            $this->sensor(1, '10.0.0.1', 'snr', 'airos-af60-r', 'Remote SNR', 12.0),
        ]));

        $sample = DB::table('rf_link_samples')->where('device_id', $device->id)->first();
        $this->assertEqualsWithDelta(-48.0, $sample->rssi_dbm, 0.001); // Local, not Remote
        $this->assertSame('airos-af60-l', $sample->rssi_sensor_type);
        $this->assertEqualsWithDelta(18.0, $sample->snr_db, 0.001); // native, not derived
        $this->assertSame('native', $sample->snr_source);
    }

    public function test_mikrotik_60g_wireless_wire_rssi_is_real_dbm(): void
    {
        $device = Device::factory()->create(['mgmt_ip' => '10.0.0.1']);

        app(PullLibreNmsRfMetrics::class)($this->fakeSource([
            $this->sensor(1, '10.0.0.1', 'rssi', 'mikrotik', '60G: shakopeefiber2shakopeeroof', -34.0),
        ]));

        $state = RfLinkState::find($device->id);
        $this->assertEqualsWithDelta(-34.0, $state->rssi_dbm, 0.001);
        $this->assertSame('mikrotik', $state->rssi_sensor_type);
    }

    public function test_mikrotik_regular_wifi_has_no_signal_sensor_and_stays_null_not_a_guess(): void
    {
        // Standard 2.4/5/9GHz MikroTik wifi reports noise-floor but NO received-signal-dBm
        // sensor at all in LibreNMS (documented gap, ~4,600 devices live) - rssi_dbm must stay
        // null rather than being backfilled from something unrelated like ccq/quality.
        $device = Device::factory()->create(['mgmt_ip' => '10.0.0.1']);

        app(PullLibreNmsRfMetrics::class)($this->fakeSource([
            $this->sensor(1, '10.0.0.1', 'noise-floor', 'mikrotik', '5G: Home5g', -108.0),
        ]));

        $sample = DB::table('rf_link_samples')->where('device_id', $device->id)->first();
        $this->assertNull($sample->rssi_dbm);
        $this->assertNull($sample->rssi_sensor_type);
        $this->assertEqualsWithDelta(-108.0, $sample->noise_floor_dbm, 0.001);
    }

    public function test_cambium_pmp_ssr_ratio_is_never_used_as_signal_dbm(): void
    {
        // Cambium's `ssr` class ("Cambium Signal Strength Ratio", live range -12..20) looks
        // superficially signal-like but is a proprietary ratio, not dBm - it must never land in
        // rssi_dbm. Cambium's only trustworthy signal-adjacent sensors are native snr-h/snr-v.
        $device = Device::factory()->create(['mgmt_ip' => '10.0.0.1']);

        app(PullLibreNmsRfMetrics::class)($this->fakeSource([
            $this->sensor(1, '10.0.0.1', 'ssr', 'pmp', 'Cambium Signal Strength Ratio', 17.0),
            $this->sensor(1, '10.0.0.1', 'snr', 'pmp-h', 'Cambium SNR Horizontal', 24.0),
            $this->sensor(1, '10.0.0.1', 'snr', 'pmp-v', 'Cambium SNR Vertical', 20.0),
        ]));

        $sample = DB::table('rf_link_samples')->where('device_id', $device->id)->first();
        $this->assertNull($sample->rssi_dbm);
        $this->assertEqualsWithDelta(20.0, $sample->snr_db, 0.001); // worse of horiz/vert
        $this->assertSame('native', $sample->snr_source);
    }

    public function test_mimosa_ptp_and_non_ptp_use_their_respective_rx_power_sensors(): void
    {
        $ptp = Device::factory()->create(['mgmt_ip' => '10.0.0.1']);
        $nonPtp = Device::factory()->create(['mgmt_ip' => '10.0.0.2']);

        app(PullLibreNmsRfMetrics::class)($this->fakeSource([
            $this->sensor(1, '10.0.0.1', 'power', 'mimosa-ptp-rx', 'Rx Power: Horiz. Chain', -58.0),
            $this->sensor(1, '10.0.0.1', 'power', 'mimosa-ptp-tx', 'Tx Power: Horiz. Chain', 16.0),
            $this->sensor(2, '10.0.0.2', 'power', 'mimosa-rx', 'Min Rx Power: MIMOSA-5Ghz-1', -60.0),
            $this->sensor(2, '10.0.0.2', 'power', 'mimosa-tx', 'Tx Power: MIMOSA-5Ghz-1', 18.0),
        ]));

        $this->assertEqualsWithDelta(-58.0, RfLinkState::find($ptp->id)->rssi_dbm, 0.001);
        $this->assertEqualsWithDelta(-60.0, RfLinkState::find($nonPtp->id)->rssi_dbm, 0.001);
    }

    public function test_aviat_wtm_uses_rsl_and_native_snr(): void
    {
        $device = Device::factory()->create(['mgmt_ip' => '10.0.0.1']);

        app(PullLibreNmsRfMetrics::class)($this->fakeSource([
            $this->sensor(1, '10.0.0.1', 'rssi', 'aviat-wtm-carrier-rsl', 'RSL (Carrier1/1)', -50.0),
            $this->sensor(1, '10.0.0.1', 'power', 'aviat-wtm-carrier-txpower', 'TX Power (Carrier1/1)', 26.0),
            $this->sensor(1, '10.0.0.1', 'snr', 'aviat-wtm-carrier-snr', 'SNR (Carrier1/1)', 39.0),
        ]));

        $sample = DB::table('rf_link_samples')->where('device_id', $device->id)->first();
        $this->assertEqualsWithDelta(-50.0, $sample->rssi_dbm, 0.001);
        $this->assertEqualsWithDelta(39.0, $sample->snr_db, 0.001);
        $this->assertSame('native', $sample->snr_source);
    }

    public function test_saf_integrax_uses_rx_level_not_tx_power(): void
    {
        $device = Device::factory()->create(['mgmt_ip' => '10.0.0.1']);

        app(PullLibreNmsRfMetrics::class)($this->fakeSource([
            $this->sensor(1, '10.0.0.1', 'power', 'saf-integrax-a-rx-level', 'Radio-A Rx Level', -58.0),
            $this->sensor(1, '10.0.0.1', 'power', 'saf-integrax-a-tx-power', 'Radio-A Tx Power', 16.0),
        ]));

        $state = RfLinkState::find($device->id);
        $this->assertEqualsWithDelta(-58.0, $state->rssi_dbm, 0.001);
        $this->assertSame('saf-integrax-a-rx-level', $state->rssi_sensor_type);
    }

    // ================================================================================
    // Sanity guards
    // ================================================================================

    public function test_out_of_range_signal_is_rejected_not_stored(): void
    {
        $device = Device::factory()->create(['mgmt_ip' => '10.0.0.1']);

        $result = app(PullLibreNmsRfMetrics::class)($this->fakeSource([
            // +71 dBm cannot exist for a receiver - implausible, must be dropped.
            $this->sensor(1, '10.0.0.1', 'power', 'airos-rx', 'Signal Level', 71.0),
        ]));

        $sample = DB::table('rf_link_samples')->where('device_id', $device->id)->first();
        $this->assertNull($sample->rssi_dbm);
        $this->assertNull($sample->rssi_sensor_type);
        $this->assertSame(1, $result['rejected']);
    }

    public function test_out_of_range_noise_floor_is_rejected_not_stored(): void
    {
        $device = Device::factory()->create(['mgmt_ip' => '10.0.0.1']);

        app(PullLibreNmsRfMetrics::class)($this->fakeSource([
            $this->sensor(1, '10.0.0.1', 'power', 'airos-rx', 'Signal Level', -55.0),
            // 0 dBm noise floor is a disabled/placeholder reading, not real - live MikroTik data
            // shows this exact pattern for SSID rows with no actual RF data.
            $this->sensor(1, '10.0.0.1', 'noise-floor', 'airos', 'Noise Floor', 0.0),
        ]));

        $sample = DB::table('rf_link_samples')->where('device_id', $device->id)->first();
        $this->assertEqualsWithDelta(-55.0, $sample->rssi_dbm, 0.001); // signal itself still valid
        $this->assertNull($sample->noise_floor_dbm);
        $this->assertNull($sample->snr_db); // no derivation possible without a valid noise floor
    }

    public function test_out_of_range_native_snr_is_rejected_and_does_not_block_a_derived_fallback(): void
    {
        $device = Device::factory()->create(['mgmt_ip' => '10.0.0.1']);

        app(PullLibreNmsRfMetrics::class)($this->fakeSource([
            $this->sensor(1, '10.0.0.1', 'power', 'airos-rx', 'Signal Level', -55.0),
            $this->sensor(1, '10.0.0.1', 'noise-floor', 'airos', 'Noise Floor', -95.0),
            // 97.9 dB SNR is beyond plausible (live Mimosa data shows exactly this failure mode).
            $this->sensor(1, '10.0.0.1', 'snr', 'mimosa', 'SNR: Horiz. Chain', 97.9),
        ]));

        $sample = DB::table('rf_link_samples')->where('device_id', $device->id)->first();
        // Native SNR rejected -> falls back to a valid derived value instead of silently
        // keeping the implausible native reading.
        $this->assertEqualsWithDelta(40.0, $sample->snr_db, 0.001); // -55 - (-95)
        $this->assertSame('derived', $sample->snr_source);
    }

    public function test_derived_snr_that_is_itself_implausible_is_also_rejected(): void
    {
        $device = Device::factory()->create(['mgmt_ip' => '10.0.0.1']);

        app(PullLibreNmsRfMetrics::class)($this->fakeSource([
            $this->sensor(1, '10.0.0.1', 'power', 'airos-rx', 'Signal Level', -30.0),
            $this->sensor(1, '10.0.0.1', 'noise-floor', 'airos', 'Noise Floor', -110.0),
        ]));

        // -30 - (-110) = 80 dB, above the 0..60 plausible SNR range - must not be stored either.
        $sample = DB::table('rf_link_samples')->where('device_id', $device->id)->first();
        $this->assertNull($sample->snr_db);
        $this->assertNull($sample->snr_source);
    }

    public function test_rejections_across_an_entire_run_log_exactly_one_warning_not_one_per_device(): void
    {
        Log::shouldReceive('channel')->andReturnSelf();
        Log::shouldReceive('warning')
            ->once()
            ->with('rf: sanity guard rejected implausible readings', \Mockery::on(function (array $context) {
                return ($context['rejected']['signal'] ?? 0) === 2;
            }));
        // Other levels (debug/info) may still be called incidentally - don't choke on those.
        Log::shouldReceive('debug')->zeroOrMoreTimes();
        Log::shouldReceive('info')->zeroOrMoreTimes();

        Device::factory()->create(['mgmt_ip' => '10.0.0.1']);
        Device::factory()->create(['mgmt_ip' => '10.0.0.2']);

        app(PullLibreNmsRfMetrics::class)($this->fakeSource([
            $this->sensor(1, '10.0.0.1', 'power', 'airos-rx', 'Signal Level', 71.0), // implausible
            $this->sensor(2, '10.0.0.2', 'power', 'airos-rx', 'Signal Level', 68.0), // implausible
        ]));
    }

    // ================================================================================
    // Behaviour unrelated to the signal-mapping fix, preserved from the original suite.
    // ================================================================================

    public function test_never_stores_capacity_or_ccq_even_if_the_source_returns_them(): void
    {
        $device = Device::factory()->create(['mgmt_ip' => '10.0.0.1']);

        app(PullLibreNmsRfMetrics::class)($this->fakeSource([
            $this->sensor(1, '10.0.0.1', 'power', 'airos-rx', 'Signal Level', -55.0),
            $this->sensor(1, '10.0.0.1', 'capacity', 'airos', 'airMAX Capacity', 0.0),
            $this->sensor(1, '10.0.0.1', 'ccq', 'airos', 'CCQ', 33.0),
        ]));

        $sample = DB::table('rf_link_samples')->where('device_id', $device->id)->first();
        $this->assertNotNull($sample);
        // The schema has no capacity/ccq columns at all - the samples row simply has no trace
        // of them; confirm the columns that DO exist aren't polluted by the excluded classes.
        $this->assertFalse(in_array('ccq_pct', array_keys((array) $sample), true));
        $this->assertFalse(in_array('capacity', array_keys((array) $sample), true));
    }

    public function test_prefers_rx_rate_over_tx_and_converts_bps_to_mbps(): void
    {
        $device = Device::factory()->create(['mgmt_ip' => '10.0.0.1']);

        app(PullLibreNmsRfMetrics::class)($this->fakeSource([
            $this->sensor(1, '10.0.0.1', 'rate', 'airos-tx', 'Tx Rate', 100_000_000.0),
            $this->sensor(1, '10.0.0.1', 'rate', 'airos-rx', 'Rx Rate', 300_000_000.0),
        ]));

        $sample = DB::table('rf_link_samples')->where('device_id', $device->id)->first();
        $this->assertEqualsWithDelta(300.0, $sample->rate_mbps, 0.001);
    }

    public function test_power_requires_a_tx_descriptor_and_ignores_rx_chain_readings(): void
    {
        $device = Device::factory()->create(['mgmt_ip' => '10.0.0.1']);

        app(PullLibreNmsRfMetrics::class)($this->fakeSource([
            $this->sensor(1, '10.0.0.1', 'power', 'airos-af-ltu-rx-chain-0', 'RX Power Chain 0', -51.0),
            $this->sensor(1, '10.0.0.1', 'power', 'airos-af-ltu-rx-chain-1', 'RX Power Chain 1', -50.0),
            $this->sensor(1, '10.0.0.1', 'power', 'airos-tx', 'Tx Power', 24.0),
        ]));

        $sample = DB::table('rf_link_samples')->where('device_id', $device->id)->first();
        $this->assertEqualsWithDelta(24.0, $sample->tx_power_dbm, 0.001);
    }

    public function test_power_is_null_when_only_rx_chain_readings_exist(): void
    {
        $device = Device::factory()->create(['mgmt_ip' => '10.0.0.1']);

        app(PullLibreNmsRfMetrics::class)($this->fakeSource([
            $this->sensor(1, '10.0.0.1', 'power', 'airos-af-ltu-rx-chain-0', 'RX Power Chain 0', -51.0),
            $this->sensor(1, '10.0.0.1', 'power', 'airos-af-ltu-rx-chain-1', 'RX Power Chain 1', -50.0),
        ]));

        $sample = DB::table('rf_link_samples')->where('device_id', $device->id)->first();
        $this->assertNull($sample->tx_power_dbm);
    }

    public function test_records_librenms_own_lastupdate_for_downstream_staleness(): void
    {
        $device = Device::factory()->create(['mgmt_ip' => '10.0.0.1']);

        app(PullLibreNmsRfMetrics::class)($this->fakeSource([
            $this->sensor(1, '10.0.0.1', 'power', 'airos-rx', 'Signal Level', -55.0, lastupdate: '2026-06-01 08:00:00'),
        ]));

        $sample = DB::table('rf_link_samples')->where('device_id', $device->id)->first();
        $this->assertSame('2026-06-01 08:00:00', (string) $sample->source_lastupdate);

        $state = RfLinkState::find($device->id);
        $this->assertNotNull($state->source_lastupdate);
        $this->assertSame('2026-06-01 08:00:00', $state->source_lastupdate->toDateTimeString());
        $this->assertNotNull($state->synced_at);
    }

    public function test_multi_carrier_readings_collapse_to_one_row_using_the_worse_carrier(): void
    {
        // Real LibreNMS data (2026-07-30) showed sensor_index does NOT line up across sensor
        // classes for standard airOS (rssi indexes by chain, noise-floor by something else
        // entirely on the same device) - grouping by sensor_index silently broke SNR
        // derivation in production. This ingestion stage collapses to one row per device;
        // a genuinely multi-carrier device (Aviat WTM) still gets the worse-carrier value via
        // pickSignalReading()'s tie-break, which is the behaviour alerting actually wants anyway.
        $device = Device::factory()->create(['mgmt_ip' => '10.0.0.1']);

        app(PullLibreNmsRfMetrics::class)($this->fakeSource([
            $this->sensor(1, '10.0.0.1', 'rssi', 'aviat-wtm-carrier-rsl', 'RSL (Carrier1/1)', -40.0, index: 'Carrier1/1'),
            $this->sensor(1, '10.0.0.1', 'rssi', 'aviat-wtm-carrier-rsl', 'RSL (Carrier1/2)', -70.0, index: 'Carrier1/2'),
        ]));

        $this->assertSame(1, DB::table('rf_link_samples')->where('device_id', $device->id)->count());

        $state = RfLinkState::find($device->id);
        $this->assertEqualsWithDelta(-70.0, $state->rssi_dbm, 0.001); // worse of the two carriers
    }

    public function test_rssi_and_noise_floor_combine_across_differing_librenms_sensor_indexes(): void
    {
        // The exact production bug: LibreNMS reports the signal sensor under sensor_index '0'
        // and "Noise Floor" under sensor_index '1' for the SAME device - if grouping keyed on
        // sensor_index, these would never meet and derived SNR would always be null.
        $device = Device::factory()->create(['mgmt_ip' => '10.0.0.1']);

        app(PullLibreNmsRfMetrics::class)($this->fakeSource([
            $this->sensor(1, '10.0.0.1', 'power', 'airos-rx', 'Signal Level', -54.0, index: '0'),
            $this->sensor(1, '10.0.0.1', 'noise-floor', 'airos', 'Noise Floor', -95.0, index: '1'),
        ]));

        $sample = DB::table('rf_link_samples')->where('device_id', $device->id)->first();
        $this->assertEqualsWithDelta(41.0, $sample->snr_db, 0.001);
        $this->assertSame('derived', $sample->snr_source);
    }

    public function test_ignores_port_errors_for_a_device_with_no_wireless_sensor_reading(): void
    {
        Device::factory()->create(['mgmt_ip' => '10.0.0.1']);

        app(PullLibreNmsRfMetrics::class)($this->fakeSource(
            sensors: [],
            portErrors: [['device_id' => 1, 'ip' => '10.0.0.1', 'if_errors_in' => 5, 'if_errors_out' => 2]],
        ));

        $this->assertSame(0, DB::table('rf_link_samples')->count());
    }

    public function test_attaches_port_error_counters_to_a_devices_sample_row(): void
    {
        $device = Device::factory()->create(['mgmt_ip' => '10.0.0.1']);

        app(PullLibreNmsRfMetrics::class)($this->fakeSource(
            sensors: [$this->sensor(1, '10.0.0.1', 'power', 'airos-rx', 'Signal Level', -55.0)],
            portErrors: [['device_id' => 1, 'ip' => '10.0.0.1', 'if_errors_in' => 5, 'if_errors_out' => 2]],
        ));

        $sample = DB::table('rf_link_samples')->where('device_id', $device->id)->first();
        $this->assertSame(5, $sample->if_errors_in);
        $this->assertSame(2, $sample->if_errors_out);
    }

    public function test_no_op_and_no_exception_when_the_source_throws(): void
    {
        Device::factory()->create(['mgmt_ip' => '10.0.0.1']);
        $source = new class implements LibreNmsRfSource
        {
            public function wirelessSensors(?DateTimeInterface $since = null): array
            {
                throw new \RuntimeException('connection refused');
            }

            public function portErrorCounters(): array
            {
                return [];
            }
        };

        $result = app(PullLibreNmsRfMetrics::class)($source);

        $this->assertSame('unreachable', $result['skipped']);
        $this->assertSame(0, DB::table('rf_link_samples')->count());
    }
}
