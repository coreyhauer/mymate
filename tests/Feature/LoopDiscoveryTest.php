<?php

namespace Tests\Feature;

use App\Enums\PollMethod;
use App\Jobs\DiscoverInterfacesBatchJob;
use App\Models\Device;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The discovery sweep dispatches SHARDED batch jobs, not one job per device. The per-device
 * fan-out it replaced put ~17k jobs per cycle on the shared `poll` queue and buried throughput
 * polling millions of jobs deep, so these assert on shard membership rather than job count.
 */
class LoopDiscoveryTest extends TestCase
{
    use RefreshDatabase;

    /** @return list<int> every device id carried by a dispatched discovery batch */
    private function dispatchedDeviceIds(): array
    {
        $ids = [];
        foreach (Queue::pushed(DiscoverInterfacesBatchJob::class) as $job) {
            $ids = [...$ids, ...$job->deviceIds];
        }

        return $ids;
    }

    public function test_discover_dispatches_for_both_poll_methods(): void
    {
        // : periodic re-discovery now covers SNMP *and* RouterOS
        // (it was SNMP-only, leaving RouterOS devices un-refreshed).
        Queue::fake();
        $snmp = Device::factory()->create(['poll_method' => PollMethod::Snmp]);
        $routerOs = Device::factory()->create(['poll_method' => PollMethod::RouterOs]);

        $this->artisan('mymate:loop --discover')->assertSuccessful();

        $ids = $this->dispatchedDeviceIds();
        $this->assertContains($snmp->id, $ids);
        $this->assertContains($routerOs->id, $ids);
        $this->assertCount(2, $ids);
    }

    public function test_ping_only_devices_are_not_discovered(): void
    {
        //  (FR-36): a ping-only device has no throughput/discovery driver, so
        // it must be excluded from the discovery sweep too - not just throughput.
        Queue::fake();
        $snmp = Device::factory()->create(['poll_method' => PollMethod::Snmp]);
        $pingOnly = Device::factory()->create(['poll_method' => PollMethod::None]);

        $this->artisan('mymate:loop --discover')->assertSuccessful();

        $ids = $this->dispatchedDeviceIds();
        $this->assertContains($snmp->id, $ids);
        $this->assertNotContains($pingOnly->id, $ids);
    }

    public function test_unmonitored_devices_are_not_discovered(): void
    {
        // The old per-device fan-out never filtered `monitored`, so paused/acked devices were
        // rediscovered forever - pure waste on the queue that starved throughput.
        Queue::fake();
        $active = Device::factory()->create(['poll_method' => PollMethod::Snmp, 'monitored' => true]);
        $paused = Device::factory()->create(['poll_method' => PollMethod::Snmp, 'monitored' => false]);

        $this->artisan('mymate:loop --discover')->assertSuccessful();

        $ids = $this->dispatchedDeviceIds();
        $this->assertContains($active->id, $ids);
        $this->assertNotContains($paused->id, $ids);
    }

    public function test_discovery_job_count_is_bounded_by_shards_not_fleet_size(): void
    {
        // The regression that mattered: job count must not scale with the fleet.
        Queue::fake();
        config(['mymate.poll.discover_shards' => 4]);
        Device::factory()->count(40)->create(['poll_method' => PollMethod::Snmp]);

        $this->artisan('mymate:loop --discover')->assertSuccessful();

        Queue::assertPushed(DiscoverInterfacesBatchJob::class, 4);
        $this->assertCount(40, $this->dispatchedDeviceIds());
    }
}
