<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\OspfNeighbor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OspfNeighborApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsUser();
    }

    public function test_lists_neighbors_with_device_join_in_cursor_paginated_envelope(): void
    {
        $device = Device::factory()->create(['name' => 'CORE-1', 'site_id' => null]);

        OspfNeighbor::create([
            'device_id' => $device->id,
            'router_id' => '10.255.0.7',
            'neighbor_address' => '10.66.71.2',
            'interface' => 'ether1',
            'state' => 'Full',
            'is_full' => true,
            'adjacency_seconds' => 7200,
            'state_changes' => 3,
            'area' => 'backbone',
            'priority' => 1,
            'last_seen_at' => now(),
        ]);

        $response = $this->getJson('/api/ospf-neighbors')->assertOk()->assertJsonCount(1, 'data');

        $row = $response->json('data.0');
        $this->assertSame($device->id, $row['device_id']);
        $this->assertSame('CORE-1', $row['device_name']);
        $this->assertSame('10.255.0.7', $row['router_id']);
        $this->assertSame('10.66.71.2', $row['neighbor_address']);
        $this->assertSame('ether1', $row['interface']);
        $this->assertSame('Full', $row['state']);
        $this->assertTrue($row['is_full']);
        $this->assertSame(7200, $row['adjacency_seconds']);
        $this->assertSame(3, $row['state_changes']);

        $this->assertArrayHasKey('next_cursor', $response->json('meta'));
    }

    public function test_stale_rows_are_hidden_by_default_and_revealed_on_request(): void
    {
        $device = Device::factory()->create();

        // A device that stopped being polled entirely - its last adjacency must not be served
        // as a live one.
        OspfNeighbor::create([
            'device_id' => $device->id, 'router_id' => '10.255.0.9', 'neighbor_address' => '10.66.71.9',
            'state' => 'Full', 'is_full' => true, 'last_seen_at' => now()->subDay(),
        ]);
        OspfNeighbor::create([
            'device_id' => $device->id, 'router_id' => '10.255.0.7', 'neighbor_address' => '10.66.71.2',
            'state' => 'Full', 'is_full' => true, 'last_seen_at' => now(),
        ]);

        $this->getJson('/api/ospf-neighbors')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.router_id', '10.255.0.7');

        $this->getJson('/api/ospf-neighbors?stale=1')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/ospf-neighbors?max_age_minutes=0')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_full_false_returns_only_adjacencies_that_are_not_up(): void
    {
        $device = Device::factory()->create();

        OspfNeighbor::create([
            'device_id' => $device->id, 'router_id' => 'a', 'neighbor_address' => '1',
            'state' => 'Full', 'is_full' => true, 'last_seen_at' => now(),
        ]);
        OspfNeighbor::create([
            'device_id' => $device->id, 'router_id' => 'b', 'neighbor_address' => '2',
            'state' => 'ExStart', 'is_full' => false, 'last_seen_at' => now(),
        ]);

        $this->getJson('/api/ospf-neighbors?full=0')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.state', 'ExStart');
    }

    public function test_state_filter_is_case_insensitive(): void
    {
        $device = Device::factory()->create();

        OspfNeighbor::create([
            'device_id' => $device->id, 'router_id' => 'a', 'neighbor_address' => '1',
            'state' => 'Full', 'is_full' => true, 'last_seen_at' => now(),
        ]);

        $this->getJson('/api/ospf-neighbors?state=full')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_array_params_are_rejected_rather_than_500ing(): void
    {
        $this->getJson('/api/ospf-neighbors?router_id[]=x')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['router_id']);

        $this->getJson('/api/ospf-neighbors?since[]=x')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['since']);
    }

    public function test_invalid_since_is_rejected(): void
    {
        $this->getJson('/api/ospf-neighbors?since=not-a-date')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['since']);
    }

    public function test_since_accepts_epoch_seconds_and_milliseconds(): void
    {
        $device = Device::factory()->create();

        OspfNeighbor::create([
            'device_id' => $device->id, 'router_id' => 'recent', 'neighbor_address' => '1',
            'state' => 'Full', 'is_full' => true, 'last_seen_at' => now(),
        ]);

        $this->getJson('/api/ospf-neighbors?since='.now()->subMinutes(5)->getTimestamp())
            ->assertOk()->assertJsonCount(1, 'data');

        $this->getJson('/api/ospf-neighbors?since='.now()->subMinutes(5)->getTimestampMs())
            ->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_requires_authentication(): void
    {
        $this->app['auth']->forgetGuards();

        $this->getJson('/api/ospf-neighbors')->assertUnauthorized();
    }
}
