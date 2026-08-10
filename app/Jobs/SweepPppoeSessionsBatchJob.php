<?php

namespace App\Jobs;

use App\Actions\Pppoe\SweepPppoeSessions;
use App\Support\EngineLog;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

/**
 * Thin queue envelope: sweep the active PPPoE sessions of one **shard** of the concentrator
 * fleet, on its own isolated queue.
 *
 * Sharded, NOT one job per device, on purpose. ~2,300 concentrators every 5 minutes would be
 * ~660k jobs/day if fanned out per device; App\Services\Polling\PollDispatcher::dispatchDiscovery's
 * docblock records what that costs here (a per-device fan-out once buried the poll queue 9.6M
 * jobs deep and days behind). Sharding makes the job count depend on shard count, not fleet
 * size, and per-device failure isolation lives inside the action instead of in the queue.
 *
 * `tries = 1`: a missed sweep is corrected by the next tick 5 minutes later - retrying would
 * just re-hammer a concentrator that already failed to answer.
 *
 * The per-shard overlap lock gives backpressure (a slow shard skips its next tick rather than
 * piling up) and cross-dispatcher safety (a manual `mymate:pppoe:sweep` run can't double-run
 * a shard the scheduler already has in flight). `expireAfter` tracks the worker timeout so a
 * killed worker can never leave a shard locked out for good.
 */
class SweepPppoeSessionsBatchJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /** @param  list<int>  $deviceIds */
    public function __construct(public int $shard, public array $deviceIds)
    {
        $this->onQueue((string) config('mymate.pppoe.queue', 'pppoe'));
    }

    public function handle(SweepPppoeSessions $sweep): void
    {
        $sweep($this->deviceIds);
    }

    /** @return array<int, object> */
    public function middleware(): array
    {
        $expire = max(60, (int) config('mymate.pppoe.job_timeout', 300));

        return [(new WithoutOverlapping("pppoe-shard-{$this->shard}"))->dontRelease()->expireAfter($expire)];
    }

    public function failed(Throwable $e): void
    {
        EngineLog::error('pppoe: sweep batch job failed', [
            'shard' => $this->shard,
            'devices' => count($this->deviceIds),
            'exception' => $e::class,
            'error' => $e->getMessage(),
        ]);
    }
}
