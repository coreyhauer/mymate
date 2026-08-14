<?php

namespace App\Jobs;

use App\Actions\Polling\PollSensors;
use App\Jobs\Middleware\LoggedWithoutOverlapping;
use App\Support\EngineLog;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Queue envelope: one custom-sensor poll tick for one shard of the fleet - the sibling of
 * PollDeviceMetricsBatchJob. Per-shard overlap lock gives backpressure and cross-daemon safety.
 */
class PollSensorsBatchJob implements ShouldQueue
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
    public function __construct(public int $shard, public array $deviceIds, ?string $queue = null, ?int $timeout = null)
    {
        // Supplied by PollDispatcher from mymate.device_metrics.*. Defaulted with literals
        // rather than config() calls so the job stays constructable without a container.
        $this->onQueue($queue ?? 'metrics');
        $this->timeout = max(30, $timeout ?? 120);
    }

    public function handle(PollSensors $poll): void
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
            (new LoggedWithoutOverlapping("sensors-shard-{$this->shard}"))
                ->dontRelease()
                ->expireAfter($this->timeout)
                ->withContext(['shard' => $this->shard, 'devices' => count($this->deviceIds)]),
        ];
    }

    public function failed(Throwable $e): void
    {
        EngineLog::error('sensors: batch job failed', [
            'shard' => $this->shard,
            'devices' => count($this->deviceIds),
            'exception' => $e::class,
            'error' => $e->getMessage(),
        ]);
    }
}
