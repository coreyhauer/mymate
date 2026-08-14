<?php

namespace App\Services\Polling;

use App\Enums\PollMethod;
use App\Jobs\DiscoverInterfacesBatchJob;
use App\Jobs\PollDeviceMetricsBatchJob;
use App\Jobs\PollInterfacesBatchJob;
use App\Jobs\PollSensorsBatchJob;
use App\Models\Device;
use App\Models\Sensor;
use App\Support\EngineLog;
use Illuminate\Support\Facades\Queue;

/**
 * Shards the pollable fleet into N batch jobs by `crc32(device_id) % shards` and
 * dispatches one PollInterfacesBatchJob per non-empty shard.
 *
 * Sharding is deterministic (stable shard key per device + config N), so the
 * per-shard overlap lock on the job gives cross-daemon safety.
 *
 * The overlap lock is NOT backpressure: it stops two copies of a shard RUNNING at once, but
 * a duplicate still sits in redis until a worker pops it - and while every worker is busy on
 * a multi-minute batch, nobody is popping. A cycle that dispatches faster than workers drain
 * therefore grows the queue without bound. That is exactly what wedged this box twice in one
 * week (2026-08-06: 463k jobs, redis 5.8GB, 23.9k devices falsely down; 2026-08-08: 91k and
 * climbing). `guard()` below is the real backpressure: skip the cycle while a full round is
 * still waiting. Scale by raising `mymate.poll.shards` and adding `poll` workers.
 */
class PollDispatcher
{
    /**
     * Dispatch-time backpressure. True when `$queue` still holds more than a couple of full
     * rounds of work, meaning the previous cycles have not drained - dispatching another one
     * would only deepen a backlog the workers already cannot keep up with. Skipping is safe:
     * these are periodic refresh jobs, so the next tick re-dispatches whatever is still due.
     */
    private function backpressured(string $queue, int $shards): bool
    {
        $limit = max(2 * $shards, 64);
        $depth = (int) Queue::size($queue);
        if ($depth <= $limit) {
            return false;
        }

        EngineLog::warning('poll: dispatch skipped, queue backlog', [
            'queue' => $queue, 'depth' => $depth, 'limit' => $limit,
        ]);

        return true;
    }

    /**
     * How many shards to split $deviceCount into.
     *
     * `shards` is a fixed number, so on its own it lets batch size grow with the fleet
     * forever - and a batch that outgrows its worker timeout does not degrade, it stops
     * producing output entirely (both PollInterfaces and PollDeviceMetrics persist AFTER
     * their device loop, so a job killed mid-loop writes nothing at all). That is how this
     * box ran for ten days with 187-device batches, 206s of work and a 60s timeout,
     * recording no throughput history whatsoever.
     *
     * So the configured shard count is a FLOOR and `devices_per_shard` is the TARGET average
     * batch size; whichever demands more shards wins. Fleet growth now costs more jobs, never
     * longer jobs.
     *
     * "Target average", not "ceiling": shards are crc32(device_id) % n, and a hash does not
     * divide a fleet evenly. Production measured min 169 / max 204 against a mean of 187 at
     * 128 shards - about +10% - and the skew is proportionally worse for small shard counts.
     * So the shard count is over-provisioned by SKEW_HEADROOM and the real safety margin is
     * `job_timeout`, which is sized against the worst case (a batch of nothing but PPPoE
     * concentrators at ~2.3s/device), not against the average.
     */
    private const SKEW_HEADROOM = 1.25;

    private function shardCount(int $deviceCount, string $prefix): int
    {
        $configured = max(1, (int) config($prefix.'.shards', config('mymate.poll.shards', 16)));
        $perShard = max(1, (int) config($prefix.'.devices_per_shard', 32));

        return max($configured, (int) ceil($deviceCount / $perShard * self::SKEW_HEADROOM));
    }

    /**
     * The centrally-pollable fleet: monitored, agent-less, and carrying an actual
     * throughput method. The one definition, shared by every dispatcher below so their
     * shard maps cannot drift apart.
     *
     * Ping-only devices (poll_method=none) have no driver, so dispatching them would just
     * throw and log a spurious `poll: device poll failed` every tick.
     *
     * @return list<int>
     */
    private function pollableIds(): array
    {
        return Device::where('monitored', true)
            ->whereNull('agent_id') // agent-assigned devices are polled by their agent
            ->whereIn('poll_method', PollMethod::throughputMethods())
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * Split ids into shards by crc32(device_id) % shards - the house key, stable per device
     * for a given shard count.
     *
     * @param  list<int>  $ids
     * @return array<int, list<int>>
     */
    private function byShard(array $ids, int $shards): array
    {
        $byShard = [];
        foreach ($ids as $id) {
            $byShard[crc32((string) $id) % $shards][] = $id;
        }

        return $byShard;
    }

    /** @return int number of batch jobs dispatched (non-empty shards) */
    public function dispatch(): int
    {
        $ids = $this->pollableIds();
        if ($ids === []) {
            return 0;
        }

        $shards = $this->shardCount(count($ids), 'mymate.poll');
        if ($this->backpressured('poll', $shards)) {
            return 0;
        }

        $byShard = $this->byShard($ids, $shards);

        $timeout = (int) config('mymate.poll.job_timeout', 120);
        foreach ($byShard as $shard => $shardIds) {
            PollInterfacesBatchJob::dispatch($shard, $shardIds, $timeout);
        }

        return count($byShard);
    }

    /**
     * Shard the same pollable fleet into device-metrics (cpu/mem/temp) batch jobs. Same
     * shard key as throughput so a device's metrics and throughput land on matching shard
     * numbers; dispatched on the (slower) device-metrics cadence from the loop.
     *
     * @return int number of batch jobs dispatched (non-empty shards)
     */
    public function dispatchMetrics(): int
    {
        $ids = $this->pollableIds();
        if ($ids === []) {
            return 0;
        }

        $shards = $this->shardCount(count($ids), 'mymate.device_metrics');
        // Guarded on its OWN queue. This used to check `poll`, so a throughput backlog
        // suppressed metrics dispatch entirely - metrics were silenced by a queue they no
        // longer even run on, and OSPF (read inside the metrics batch) went with them.
        if ($this->backpressured((string) config('mymate.device_metrics.queue', 'metrics'), $shards)) {
            return 0;
        }

        $byShard = $this->byShard($ids, $shards);

        $queue = (string) config('mymate.device_metrics.queue', 'metrics');
        $timeout = (int) config('mymate.device_metrics.job_timeout', 120);
        foreach ($byShard as $shard => $shardIds) {
            PollDeviceMetricsBatchJob::dispatch($shard, $shardIds, $queue, $timeout);
        }

        return count($byShard);
    }

    /**
     * Custom SNMP sensors: shard the SNMP fleet and dispatch a sensor-poll job per shard.
     * No-op (no jobs) when no sensors are defined, so it's cheap to call every metrics tick.
     *
     * @return int number of batch jobs dispatched (non-empty shards)
     */
    public function dispatchSensors(): int
    {
        if (! Sensor::where('enabled', true)->exists()) {
            return 0;
        }

        $ids = $this->pollableIds();
        if ($ids === []) {
            return 0;
        }

        // Sensors ride the metrics lane (same cadence, same shape of work) and are
        // backpressured with it - this had NO guard at all, so it could deepen a backlog
        // the workers already could not drain.
        $shards = $this->shardCount(count($ids), 'mymate.device_metrics');
        if ($this->backpressured((string) config('mymate.device_metrics.queue', 'metrics'), $shards)) {
            return 0;
        }

        $byShard = $this->byShard($ids, $shards);

        $queue = (string) config('mymate.device_metrics.queue', 'metrics');
        $timeout = (int) config('mymate.device_metrics.job_timeout', 120);
        foreach ($byShard as $shard => $shardIds) {
            PollSensorsBatchJob::dispatch($shard, $shardIds, $queue, $timeout);
        }

        return count($byShard);
    }

    /**
     * Interface (re)discovery, sharded onto the isolated `discover` queue.
     *
     * This used to dispatch one job per device straight onto `poll` - ~17k jobs every
     * discover_interval, unfiltered by `monitored`. The poll workers couldn't drain a fleet's
     * worth of full ifTable walks inside the interval, so the backlog compounded every cycle
     * until the queue was ~9.6M deep and days behind, which starved the throughput jobs that
     * write utilisation. Sharding makes the job count depend on shard count rather than fleet
     * size, and its own queue means a slow sweep can only ever delay discovery.
     *
     * @return int number of batch jobs dispatched (non-empty shards)
     */
    public function dispatchDiscovery(): int
    {
        $shards = max(1, (int) config('mymate.poll.discover_shards', 32));
        if ($this->backpressured('discover', $shards)) {
            return 0;
        }

        // Same fleet the throughput poller uses - including `monitored`, which the old
        // per-device fan-out skipped, so paused devices were being rediscovered forever.
        $ids = Device::where('monitored', true)
            ->whereNull('agent_id') // agent devices are (re)discovered by their agent
            ->whereIn('poll_method', PollMethod::throughputMethods())
            ->pluck('id');
        if ($ids->isEmpty()) {
            return 0;
        }

        /** @var array<int, list<int>> $byShard */
        $byShard = [];
        foreach ($ids as $id) {
            $byShard[crc32((string) $id) % $shards][] = (int) $id;
        }

        foreach ($byShard as $shard => $shardIds) {
            DiscoverInterfacesBatchJob::dispatch($shard, $shardIds);
        }

        return count($byShard);
    }
}
