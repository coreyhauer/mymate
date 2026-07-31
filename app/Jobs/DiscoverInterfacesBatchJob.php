<?php

namespace App\Jobs;

use App\Actions\Polling\DiscoverFleetInterfaces;
use App\Support\EngineLog;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

/**
 * Thin queue envelope: interface (re)discovery for one **shard** of the fleet - the sibling of
 * PollInterfacesBatchJob.
 *
 * On its own `discover` queue, deliberately: discovery walks far more of the MIB than a throughput
 * tick, so however slow it gets it must never be able to delay the polling that writes
 * utilisation. Sharing the `poll` queue is exactly what starved throughput before.
 *
 * The per-shard overlap lock gives backpressure - a shard still walking simply skips its next
 * tick instead of piling up - with a long window, since a full discovery sweep is slow by nature.
 */
class DiscoverInterfacesBatchJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /** Discovery is slow by design; well above the per-shard wall time we expect. */
    public int $timeout = 900;

    /** @param  list<int>  $deviceIds */
    public function __construct(public int $shard, public array $deviceIds)
    {
        $this->onQueue('discover');
    }

    public function handle(DiscoverFleetInterfaces $discover): void
    {
        $discover($this->deviceIds);
    }

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping("discover-shard-{$this->shard}"))->dontRelease()->expireAfter(1800)];
    }

    public function failed(Throwable $e): void
    {
        EngineLog::error('discover: batch job failed', [
            'shard' => $this->shard,
            'devices' => count($this->deviceIds),
            'exception' => $e::class,
            'error' => $e->getMessage(),
        ]);
    }
}
