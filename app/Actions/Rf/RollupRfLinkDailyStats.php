<?php

namespace App\Actions\Rf;

use App\Support\EngineLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Nightly rollup: rf_link_samples (raw, 5-min resolution, 14-day retention via
 * ManageHistoryPartitions) -> rf_link_daily_stats (one row per device+sensor_index+day, kept
 * far longer). This is what accumulates the history a later baseline/alerting stage will read
 * (design §4b: rolling 30-day median/p10 of daily RSSI) - building that computation itself is
 * out of scope for this task, but the accumulation needs to start today for the "day 30" of
 * the eventual soak to ever arrive, hence rolling up nightly from the moment ingestion starts
 * rather than waiting for a later stage to backfill it.
 *
 * Idempotent - ON CONFLICT upserts, so re-running for the same day (a manual backfill, or a
 * retry after a failure) is safe and just recomputes that day's stats from whatever raw
 * samples still exist.
 */
class RollupRfLinkDailyStats
{
    /** @return array{day: string, rows: int, error?: string} */
    public function __invoke(?Carbon $day = null): array
    {
        $day = ($day ?? now()->subDay())->copy()->startOfDay();
        $from = $day->toDateTimeString();
        $to = $day->copy()->addDay()->toDateTimeString();

        try {
            $affected = DB::affectingStatement(<<<'SQL'
                INSERT INTO rf_link_daily_stats
                    (device_id, day, sensor_index, rssi_median, rssi_p10, rssi_stddev, snr_median, rate_median_mbps, sample_count, created_at, updated_at)
                SELECT
                    device_id,
                    ?::date AS day,
                    sensor_index,
                    percentile_cont(0.5) WITHIN GROUP (ORDER BY rssi_dbm) FILTER (WHERE rssi_dbm IS NOT NULL) AS rssi_median,
                    percentile_cont(0.1) WITHIN GROUP (ORDER BY rssi_dbm) FILTER (WHERE rssi_dbm IS NOT NULL) AS rssi_p10,
                    stddev_samp(rssi_dbm) AS rssi_stddev,
                    percentile_cont(0.5) WITHIN GROUP (ORDER BY snr_db) FILTER (WHERE snr_db IS NOT NULL) AS snr_median,
                    percentile_cont(0.5) WITHIN GROUP (ORDER BY rate_mbps) FILTER (WHERE rate_mbps IS NOT NULL) AS rate_median_mbps,
                    count(*) AS sample_count,
                    now(), now()
                FROM rf_link_samples
                WHERE ts >= ? AND ts < ?
                GROUP BY device_id, sensor_index
                ON CONFLICT (device_id, day, sensor_index) DO UPDATE SET
                    rssi_median = EXCLUDED.rssi_median,
                    rssi_p10 = EXCLUDED.rssi_p10,
                    rssi_stddev = EXCLUDED.rssi_stddev,
                    snr_median = EXCLUDED.snr_median,
                    rate_median_mbps = EXCLUDED.rate_median_mbps,
                    sample_count = EXCLUDED.sample_count,
                    updated_at = now()
                SQL, [$day->toDateString(), $from, $to]);
        } catch (Throwable $e) {
            EngineLog::warning('rf: daily rollup failed', ['day' => $day->toDateString(), 'error' => $e->getMessage()]);

            return ['day' => $day->toDateString(), 'rows' => 0, 'error' => $e->getMessage()];
        }

        return ['day' => $day->toDateString(), 'rows' => $affected];
    }
}
