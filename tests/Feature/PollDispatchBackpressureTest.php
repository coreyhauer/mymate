<?php

namespace Tests\Feature;

use App\Enums\PollMethod;
use App\Jobs\PollDeviceMetricsBatchJob;
use App\Jobs\PollInterfacesBatchJob;
use App\Models\Device;
use App\Services\Polling\PollDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The dispatcher must stop enqueueing while the queue is already backed up. Without this the
 * per-shard overlap lock gives the *appearance* of backpressure while redis grows without
 * bound - the mechanism behind both August 2026 wedges of the production box.
 */
class PollDispatchBackpressureTest extends TestCase
{
    use RefreshDatabase;

    private function pollableDevice(): Device
    {
        return Device::factory()->create([
            'monitored' => true,
            'agent_id' => null,
            'poll_method' => PollMethod::throughputMethods()[0],
        ]);
    }

    public function test_dispatch_enqueues_when_the_queue_is_drained(): void
    {
        $this->pollableDevice();
        Queue::fake();

        $this->assertGreaterThan(0, app(PollDispatcher::class)->dispatch());
        Queue::assertPushed(PollInterfacesBatchJob::class);
    }

    public function test_dispatch_is_skipped_while_a_backlog_is_still_waiting(): void
    {
        $this->pollableDevice();
        config(['mymate.poll.shards' => 8]); // limit = max(2*8, 64) = 64
        Queue::fake();

        // Simulate a backlog the workers have not drained.
        for ($i = 0; $i < 70; $i++) {
            PollInterfacesBatchJob::dispatch($i, [1]);
        }
        $before = count(Queue::pushed(PollInterfacesBatchJob::class));

        $this->assertSame(0, app(PollDispatcher::class)->dispatch(), 'a deep queue must suppress dispatch');
        $this->assertCount($before, Queue::pushed(PollInterfacesBatchJob::class), 'nothing new may be enqueued');
    }

    public function test_a_throughput_backlog_does_not_suppress_metrics(): void
    {
        // This assertion used to be the opposite - metrics were required to respect the
        // `poll` backlog, because they rode the `poll` queue. That coupling is what silenced
        // metrics on the production box for six days: throughput congestion (its own,
        // separate bug) stopped metrics being dispatched at all, and OSPF went with them
        // because ReadOspf runs inside the metrics batch. The two lanes now have their own
        // queues and their own backpressure, so one drowning must not mute the other.
        $this->pollableDevice();
        config(['mymate.poll.shards' => 8, 'mymate.device_metrics.queue' => 'metrics']);
        Queue::fake();

        for ($i = 0; $i < 70; $i++) {
            PollInterfacesBatchJob::dispatch($i, [1]);
        }

        $this->assertSame(0, app(PollDispatcher::class)->dispatch(), 'throughput is still suppressed');
        $this->assertGreaterThan(
            0,
            app(PollDispatcher::class)->dispatchMetrics(),
            'metrics must dispatch regardless of the throughput backlog'
        );
        Queue::assertPushed(PollDeviceMetricsBatchJob::class);
    }
}
