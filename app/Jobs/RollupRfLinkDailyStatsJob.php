<?php

namespace App\Jobs;

use App\Actions\Rf\RollupRfLinkDailyStats;
use App\Support\EngineLog;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

/**
 * Nightly rollup of yesterday's rf_link_samples into rf_link_daily_stats (see
 * App\Actions\Rf\RollupRfLinkDailyStats). Same defensive shape as PullLibreNmsRfMetricsJob -
 * never throws, logs once per run.
 */
class RollupRfLinkDailyStatsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct()
    {
        $this->onQueue('default');
    }

    public function handle(RollupRfLinkDailyStats $rollup): void
    {
        try {
            $result = $rollup();
            EngineLog::debug('rf: daily rollup', $result);
        } catch (Throwable $e) {
            EngineLog::warning('rf: daily rollup job failed', ['error' => $e->getMessage()]);
        }
    }

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('rf-daily-rollup'))->dontRelease()->expireAfter(600)];
    }

    public function failed(Throwable $e): void
    {
        EngineLog::error('rf: daily rollup job failed permanently', ['error' => $e->getMessage()]);
    }
}
