<?php

namespace Tests\Feature;

use App\Actions\Rf\RollupRfLinkDailyStats;
use App\Models\Device;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RollupRfLinkDailyStatsTest extends TestCase
{
    use RefreshDatabase;

    private function insertSample(int $deviceId, Carbon $ts, ?float $rssi, string $sensorIndex = ''): void
    {
        DB::table('rf_link_samples')->insert([
            'device_id' => $deviceId,
            'ts' => $ts,
            'sensor_index' => $sensorIndex,
            'rssi_dbm' => $rssi,
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
            'source_lastupdate' => $ts,
        ]);
    }

    public function test_rolls_up_median_and_p10_from_a_days_raw_samples(): void
    {
        $device = Device::factory()->create();
        $day = now()->yesterday()->startOfDay();

        // Five samples across the day: -50, -52, -55, -58, -70 (one bad outlier).
        foreach ([-50.0, -52.0, -55.0, -58.0, -70.0] as $i => $rssi) {
            $this->insertSample($device->id, $day->copy()->addHours($i * 4), $rssi);
        }

        $result = app(RollupRfLinkDailyStats::class)($day);

        $this->assertSame(1, $result['rows']);
        $row = DB::table('rf_link_daily_stats')->where('device_id', $device->id)->first();
        $this->assertNotNull($row);
        $this->assertSame(5, $row->sample_count);
        $this->assertEqualsWithDelta(-55.0, $row->rssi_median, 0.001);
        // p10 should sit down near the outlier, well below the median.
        $this->assertLessThan($row->rssi_median, $row->rssi_p10);
    }

    public function test_keeps_sensor_index_groups_separate(): void
    {
        $device = Device::factory()->create();
        $day = now()->yesterday()->startOfDay();

        $this->insertSample($device->id, $day->copy()->addHours(1), -40.0, 'Carrier1/1');
        $this->insertSample($device->id, $day->copy()->addHours(1), -70.0, 'Carrier1/2');

        app(RollupRfLinkDailyStats::class)($day);

        $this->assertSame(2, DB::table('rf_link_daily_stats')->where('device_id', $device->id)->count());
    }

    public function test_is_idempotent_when_rerun_for_the_same_day(): void
    {
        $device = Device::factory()->create();
        $day = now()->yesterday()->startOfDay();
        $this->insertSample($device->id, $day->copy()->addHours(1), -50.0);

        app(RollupRfLinkDailyStats::class)($day);
        $this->insertSample($device->id, $day->copy()->addHours(2), -60.0);
        app(RollupRfLinkDailyStats::class)($day); // re-run, e.g. a backfill retry

        $this->assertSame(1, DB::table('rf_link_daily_stats')->where('device_id', $device->id)->count());
        $row = DB::table('rf_link_daily_stats')->where('device_id', $device->id)->first();
        $this->assertSame(2, $row->sample_count); // reflects both samples on the re-run
    }

    public function test_defaults_to_rolling_up_yesterday_when_no_day_is_given(): void
    {
        $device = Device::factory()->create();
        $this->insertSample($device->id, now()->yesterday()->startOfDay()->addHours(6), -55.0);

        $result = app(RollupRfLinkDailyStats::class)();

        $this->assertSame(now()->yesterday()->startOfDay()->toDateString(), $result['day']);
        $this->assertSame(1, $result['rows']);
    }
}
