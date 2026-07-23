<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Devices inherit their site's coordinates on the geo map, unless they carry their own pin.
 * This is the whole point of sites - place a tower once, every device at it follows.
 */
class DeviceSiteGeoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsUser();
    }

    public function test_a_device_inherits_its_sites_coordinates(): void
    {
        $site = Site::factory()->at(42.18592, -95.72134)->create();
        $device = Device::factory()->create(['site_id' => $site->id, 'latitude' => null, 'longitude' => null]);

        $this->assertSame([42.18592, -95.72134], $device->effectiveCoordinates());

        $this->getJson('/api/devices')
            ->assertOk()
            ->assertJsonPath('data.0.geo_latitude', 42.18592)
            ->assertJsonPath('data.0.geo_longitude', -95.72134)
            ->assertJsonPath('data.0.site_name', $site->name);
    }

    public function test_a_devices_own_pin_wins_over_its_site(): void
    {
        $site = Site::factory()->at(42.0, -95.0)->create();
        $device = Device::factory()->create(['site_id' => $site->id, 'latitude' => 10.0, 'longitude' => 20.0]);

        $this->assertSame([10.0, 20.0], $device->effectiveCoordinates());
    }

    public function test_a_device_with_no_pin_and_an_unplaced_site_has_no_coordinates(): void
    {
        $site = Site::factory()->unplaced()->create();
        $device = Device::factory()->create(['site_id' => $site->id, 'latitude' => null, 'longitude' => null]);

        $this->assertNull($device->effectiveCoordinates());
        $this->getJson('/api/devices')->assertJsonPath('data.0.geo_latitude', null);
    }

    public function test_assigning_a_site_through_the_editor_marks_it_manual(): void
    {
        $site = Site::factory()->create();
        $device = Device::factory()->create();

        $this->patchJson("/api/devices/{$device->id}", ['site_id' => $site->id])
            ->assertOk()
            ->assertJsonPath('data.site_id', $site->id)
            ->assertJsonPath('data.site_source', 'manual');
    }
}
