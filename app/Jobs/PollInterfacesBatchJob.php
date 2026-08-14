<?php

namespace App\Jobs;

use App\Actions\Polling\PollInterfaces;
use App\Jobs\Middleware\LoggedWithoutOverlapping;
use App\Support\EngineLog;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Thin queue envelope: one throughput tick for one **shard** of the fleet (
 * scale-out). The loop dispatches one of these per shard each tick; the per-shard
 * overlap lock gives backpressure (a slow shard skips its next tick rather than
 * piling up) **and** cross-daemon safety (two dispatchers can't double-run a shard).
 */
class PollInterfacesBatchJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /**
     * Whole-job ceiling. A timeout here is a TOTAL loss of the batch's output - this
     * action persists after its device loop - so the number that matters is "comfortably
     * longer than a worst-case batch", not "average plus a bit".
     */
    public int $timeout;

    /** @param  list<int>  $deviceIds */
    public function __construct(public int $shard, public array $deviceIds, ?int $timeout = null)
    {
        $this->onQueue('poll');
        // Supplied by PollDispatcher from mymate.poll.job_timeout. Defaulted with a literal
        // rather than a config() call so the job stays constructable without a container
        // (and so the value is fixed at dispatch time, not read again on the worker).
        $this->timeout = max(30, $timeout ?? 120);
    }

    public function handle(PollInterfaces $poll): void
    {
        $poll($this->deviceIds);
    }

    /** @return array<int, object> */
    public function middleware(): array
    {
        // LoggedWithoutOverlapping: the stock middleware discards a job in total silence, so a
        // shard held out by a stale lock looked exactly like a shard with nothing to report.
        // expireAfter tracks the job ceiling so a killed worker cannot lock a shard out for
        // longer than the job could ever legitimately run.
        return [
            (new LoggedWithoutOverlapping("poll-shard-{$this->shard}"))
                ->dontRelease()
                ->expireAfter($this->timeout)
                ->withContext(['shard' => $this->shard, 'devices' => count($this->deviceIds)]),
        ];
    }

    public function failed(Throwable $e): void
    {
        EngineLog::error('poll: batch job failed', [
            'shard' => $this->shard,
            'devices' => count($this->deviceIds),
            'exception' => $e::class,
            'error' => $e->getMessage(),
        ]);
    }
}
