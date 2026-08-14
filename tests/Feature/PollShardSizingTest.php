<?php

namespace Tests\Feature;

use App\Enums\DeviceStatus;
use App\Enums\PollMethod;
use App\Jobs\PollDeviceMetricsBatchJob;
use App\Jobs\PollInterfacesBatchJob;
use App\Models\Device;
use App\Services\Polling\PollDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The invariant that broke: batch size must stay bounded as the fleet grows.
 *
 * A fixed shard count lets every batch grow with the fleet, and a batch that outgrows its
 * worker timeout does not degrade - it stops producing output entirely, because both poll
 * actions persist after their device loop. On the production box that meant 187-device
 * batches, 206s of work against a 60s timeout, and ten days with no throughput history.
 */
class PollShardSizingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Insert directly rather than through DeviceFactory: its name is a
     * `unique()->domainWord()` evaluated inside definition() before any override applies, and
     * that pool is exhausted long before the device counts this test needs (faker's unique
     * memory also survives deleting the rows). Nothing here depends on realistic attributes -
     * sharding only ever sees ids.
     */
    private function fleet(int $n): void
    {
        static $seq = 0;
        $now = now();
        $rows = [];

        foreach (range(1, $n) as $i) {
            $seq++;
            $rows[] = [
                'name' => 'shard-fleet-'.$seq,
                'mgmt_ip' => '10.'.(($seq >> 16) & 255).'.'.(($seq >> 8) & 255).'.'.($seq & 255),
                'poll_method' => PollMethod::Snmp->value,
                'status' => DeviceStatus::Unknown->value,
                'monitored' => true,
                'agent_id' => null,
                'map_x' => 0,
                'map_y' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('devices')->insert($chunk);
        }
    }

    public function test_batch_size_stays_bounded_as_the_fleet_grows(): void
    {
        config(['mymate.poll.shards' => 4, 'mymate.poll.devices_per_shard' => 10]);
        Queue::fake();

        // 100 devices over 4 configured shards would be 25 per batch - over the ceiling.
        $this->fleet(100);
        app(PollDispatcher::class)->dispatch();

        $sizes = [];
        Queue::assertPushed(PollInterfacesBatchJob::class, function ($job) use (&$sizes) {
            $sizes[] = count($job->deviceIds);

            return true;
        });

        $this->assertNotEmpty($sizes);
        // devices_per_shard is a TARGET AVERAGE, not a hard cap - crc32 does not divide a
        // fleet evenly, and the skew is proportionally worst at small shard counts. What must
        // hold is that batches stay in the same order of magnitude as the target, so the
        // job_timeout margin absorbs the rest.
        $this->assertLessThanOrEqual(20, max($sizes), 'batches must stay near devices_per_shard');
        $this->assertLessThanOrEqual(10, array_sum($sizes) / count($sizes), 'mean batch must respect the target');
        $this->assertSame(100, array_sum($sizes), 'every device must still be dispatched exactly once');
    }

    public function test_batch_size_does_not_grow_with_the_fleet(): void
    {
        // The actual regression: with a fixed shard count, every device added made every
        // batch longer until batches outran the worker timeout and stopped writing anything.
        config(['mymate.poll.shards' => 4, 'mymate.poll.devices_per_shard' => 10]);

        $means = [];
        foreach ([50, 200, 800] as $n) {
            Device::query()->delete();
            Queue::fake();
            $this->fleet($n);
            app(PollDispatcher::class)->dispatch();

            $sizes = [];
            Queue::assertPushed(PollInterfacesBatchJob::class, function ($job) use (&$sizes) {
                $sizes[] = count($job->deviceIds);

                return true;
            });
            $means[$n] = array_sum($sizes) / count($sizes);
        }

        foreach ($means as $n => $mean) {
            $this->assertLessThanOrEqual(10, $mean, "mean batch at {$n} devices must stay at the target");
        }
    }

    public function test_configured_shard_count_is_a_floor_not_a_ceiling(): void
    {
        // A small fleet must still honour the configured shard count - this value is also
        // what keeps per-shard broadcast payloads small, so raising it must never be undone.
        config(['mymate.poll.shards' => 8, 'mymate.poll.devices_per_shard' => 1000]);
        Queue::fake();

        $this->fleet(16);
        $dispatched = app(PollDispatcher::class)->dispatch();

        $this->assertSame(8, $dispatched, 'shards is a floor');
    }

    public function test_metrics_go_to_their_own_queue_not_the_poll_queue(): void
    {
        // Metrics sharing `poll` is what let a throughput backlog silence them entirely -
        // and OSPF with them, since ReadOspf runs inside the metrics batch.
        config(['mymate.device_metrics.queue' => 'metrics']);
        Queue::fake();

        $this->fleet(20);
        app(PollDispatcher::class)->dispatchMetrics();

        Queue::assertPushed(PollDeviceMetricsBatchJob::class, fn ($job) => $job->queue === 'metrics');
        Queue::assertNotPushed(PollInterfacesBatchJob::class);
    }

    public function test_a_poll_backlog_does_not_suppress_metrics(): void
    {
        // The regression this guards: dispatchMetrics() used to check the `poll` queue's
        // depth, so throughput congestion stopped metrics being dispatched at all - and OSPF
        // with them. Bus::fake intercepts the job push, leaving the Queue facade free to
        // answer the depth probe, so we can assert which queue was actually consulted.
        config([
            'mymate.poll.shards' => 4,
            'mymate.device_metrics.shards' => 4,
            'mymate.device_metrics.queue' => 'metrics',
        ]);
        $this->fleet(20);

        Bus::fake();
        Queue::shouldReceive('size')->with('metrics')->andReturn(0);
        Queue::shouldReceive('size')->with('poll')->never();

        $this->assertGreaterThan(
            0,
            app(PollDispatcher::class)->dispatchMetrics(),
            'metrics must dispatch even when the poll queue is backlogged'
        );
        Bus::assertDispatched(PollDeviceMetricsBatchJob::class);
    }

    public function test_jobs_carry_a_timeout_longer_than_the_configured_ceiling_allows_a_batch_to_run(): void
    {
        config(['mymate.poll.job_timeout' => 120, 'mymate.device_metrics.job_timeout' => 120]);

        $this->assertSame(120, (new PollInterfacesBatchJob(0, [1]))->timeout);
        $this->assertSame(120, (new PollDeviceMetricsBatchJob(0, [1]))->timeout);
    }
}
