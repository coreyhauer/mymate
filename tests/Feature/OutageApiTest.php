<?php

namespace Tests\Feature;

use App\Actions\Outages\RecordOutage;
use App\Models\Device;
use App\Models\Outage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OutageApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_record_outage_is_idempotent_and_stamps_duration(): void
    {
        $device = Device::factory()->create();
        $rec = app(RecordOutage::class);

        $this->travelTo(now()->startOfMinute());
        $rec->open($device);
        $rec->open($device); // again - still one open outage
        $this->assertSame(1, Outage::where('device_id', $device->id)->whereNull('ended_at')->count());

        $this->travel(90)->seconds();
        $rec->close($device);

        $outage = Outage::where('device_id', $device->id)->firstOrFail();
        $this->assertNotNull($outage->ended_at);
        $this->assertSame(90, $outage->duration_s);
        $this->travelBack();
    }

    public function test_lists_outages_newest_first_with_device_name(): void
    {
        $this->actingAsUser();
        $device = Device::factory()->create(['name' => 'CPE-X']);
        Outage::factory()->for($device)->closed()->create();
        Outage::factory()->for($device)->create(); // open

        $this->getJson('/api/outages')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.device_name', 'CPE-X');
    }

    public function test_filters_by_state_and_device(): void
    {
        $this->actingAsUser();
        $a = Device::factory()->create();
        $b = Device::factory()->create();
        Outage::factory()->for($a)->create();           // open
        Outage::factory()->for($a)->closed()->create(); // closed
        Outage::factory()->for($b)->create();           // open, other device

        $this->getJson('/api/outages?state=open')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/outages?state=closed')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/outages?device_id={$a->id}")->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_requires_authentication(): void
    {
        Outage::factory()->create();

        $this->getJson('/api/outages')->assertUnauthorized();
    }

    public function test_default_call_is_unchanged_by_the_new_params(): void
    {
        $this->actingAsUser();
        Outage::factory()->count(3)->create();

        $response = $this->getJson('/api/outages')->assertOk()->assertJsonCount(3, 'data');

        // No pagination envelope - still the bare `data` array the SPA already expects.
        $this->assertArrayNotHasKey('links', $response->json());
        $this->assertArrayNotHasKey('meta', $response->json());
    }

    public function test_filters_by_from_and_to(): void
    {
        $this->actingAsUser();
        $device = Device::factory()->create();

        $this->travelTo(now()->startOfDay());
        $inRange = Outage::factory()->for($device)->create(['started_at' => now()->subDays(2)]);
        Outage::factory()->for($device)->create(['started_at' => now()->subDays(10)]); // too old
        Outage::factory()->for($device)->create(['started_at' => now()]); // too new

        $this->getJson('/api/outages?from='.now()->subDays(3)->toIso8601String().'&to='.now()->subDay()->toIso8601String())
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $inRange->id);
        $this->travelBack();
    }

    public function test_invalid_from_is_rejected(): void
    {
        $this->actingAsUser();

        $this->getJson('/api/outages?from=not-a-date')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['from']);
    }

    public function test_per_page_returns_a_cursor_paginated_envelope(): void
    {
        $this->actingAsUser();
        Outage::factory()->count(5)->create();

        $response = $this->getJson('/api/outages?per_page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->assertNotNull($response->json('meta.next_cursor'));
        $this->assertNotNull($response->json('meta.path'));
    }
}
