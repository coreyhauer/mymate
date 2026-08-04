<?php

namespace App\Jobs;

use App\Actions\Rf\PullLibreNmsRfMetrics;
use App\Support\EngineLog;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

/**
 * Thin queue envelope for the ~5-minute RF/link-health pull from LibreNMS (see
 * App\Actions\Rf\PullLibreNmsRfMetrics and scratchpad/link-health-design/
 * backhaul-link-health-design.md). Same shape as ManageHistoryPartitionsJob - light,
 * off-hot-path, `default` queue.
 *
 * Never throws past this job: a LibreNMS outage or an unconfigured install must not fill the
 * log with a retry storm every 5 minutes (this app has a live incident history of log
 * floods). PullLibreNmsRfMetrics itself already catches connection failures and returns a
 * "skipped" result without an exception; this handle() is a last-resort net for anything
 * truly unexpected, logged ONCE per run (never per-device).
 */
class PullLibreNmsRfMetricsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct()
    {
        $this->onQueue('default');
    }

    public function handle(PullLibreNmsRfMetrics $pull): void
    {
        try {
            $result = $pull();
            EngineLog::debug('rf: librenms pull', $result);
        } catch (Throwable $e) {
            EngineLog::warning('rf: librenms pull job failed', ['error' => $e->getMessage()]);
        }
    }

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('librenms-rf-pull'))->dontRelease()->expireAfter(280)];
    }

    public function failed(Throwable $e): void
    {
        EngineLog::error('rf: librenms pull job failed permanently', ['error' => $e->getMessage()]);
    }
}
