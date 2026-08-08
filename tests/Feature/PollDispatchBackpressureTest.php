<?php

namespace Tests\Feature;

use App\Enums\PollMethod;
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
        $this->assertSame(0, app(PollDispatcher::class)->dispatchMetrics(), 'metrics must respect the same backlog');
        $this->assertCount($before, Queue::pushed(PollInterfacesBatchJob::class), 'nothing new may be enqueued');
    }
}
