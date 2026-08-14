<?php

namespace App\Jobs;

use App\Actions\Pppoe\SweepPppoeSessions;
use App\Jobs\Middleware\LoggedWithoutOverlapping;
use App\Support\EngineLog;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
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

    /**
     * @param  list<int>  $deviceIds
     * @param  bool  $manual  a hand-run sweep (`mymate:pppoe:sweep --device=/--limit=`) rather
     *                        than the scheduled fleet pass - see middleware()
     */
    public function __construct(public int $shard, public array $deviceIds, public bool $manual = false)
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

        // Manual runs get their OWN lock namespace. The overlap lock is keyed by shard, and a
        // hand-run canary hashes onto whatever shard its device belongs to - so if the
        // scheduler happened to be holding that shard, dontRelease() threw the operator's job
        // away and printed "Queued 1 shard job(s)". The run looked like it had happened and had
        // simply found nothing, which is the worst possible answer while diagnosing a
        // concentrator. A manual run is small, targeted and rare; it can safely proceed
        // alongside the scheduled pass (the per-device persist is transactional either way).
        $key = $this->manual
            ? 'pppoe-manual-'.$this->shard.'-'.md5(implode(',', $this->deviceIds))
            : "pppoe-shard-{$this->shard}";

        // LoggedWithoutOverlapping, not the stock middleware: dontRelease() discards a job in
        // total silence, so a shard held out by a stale lock was indistinguishable from a shard
        // full of concentrators with no sessions.
        return [
            (new LoggedWithoutOverlapping($key))
                ->dontRelease()
                ->expireAfter($expire)
                ->withContext([
                    'shard' => $this->shard,
                    'devices' => count($this->deviceIds),
                    'manual' => $this->manual,
                ]),
        ];
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
