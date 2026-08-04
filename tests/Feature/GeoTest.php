<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * GitHub #11: the geo overlay's tile config, the geocoder proxy, and the CSP allowing tiles.
 */
class GeoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsUser();
    }

    public function test_map_config_reports_the_tile_settings(): void
    {
        config(['mymate.map.tile_url' => 'https://tile.example/{z}/{x}/{y}.png', 'mymate.map.geocoder_url' => 'https://geo.example']);

        $this->getJson('/api/map-config')
            ->assertOk()
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.tile_url', 'https://tile.example/{z}/{x}/{y}.png')
            ->assertJsonPath('data.geocoder_enabled', true);
    }

    public function test_map_config_disabled_when_no_tile_url_and_no_basemap(): void
    {
        config(['mymate.map.tile_url' => '', 'mymate.map.basemap.style_url' => '']);
        $this->getJson('/api/map-config')
            ->assertOk()
            ->assertJsonPath('data.enabled', false)
            ->assertJsonPath('data.basemap', null);
    }

    public function test_map_config_reports_the_vector_basemap_when_set(): void
    {
        // A vector basemap enables the map (MapLibre renderer) even with no raster tile URL.
        config(['mymate.map.tile_url' => '', 'mymate.map.basemap.style_url' => '/map/style.json']);
        $this->getJson('/api/map-config')
            ->assertOk()
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.basemap.style_url', '/map/style.json');
    }

    public function test_geocode_proxies_the_provider_and_returns_coordinates(): void
    {
        config(['mymate.map.geocoder_url' => 'https://geo.example/search']);
        Http::fake(['geo.example/*' => Http::response([['lat' => '-27.4698', 'lon' => '153.0251', 'display_name' => 'Brisbane']])]);

        $this->getJson('/api/geocode?q=Brisbane')
            ->assertOk()
            ->assertJsonPath('data.lat', -27.4698)
            ->assertJsonPath('data.lng', 153.0251)
            ->assertJsonPath('data.label', 'Brisbane');
    }

    public function test_geocode_returns_null_on_no_match(): void
    {
        config(['mymate.map.geocoder_url' => 'https://geo.example/search']);
        Http::fake(['geo.example/*' => Http::response([])]);

        $this->getJson('/api/geocode?q=nowhere')->assertOk()->assertExactJson(['data' => null]);
    }

    public function test_setting_coordinates_marks_the_source_manual(): void
    {
        $device = Device::factory()->create();

        $this->patchJson("/api/devices/{$device->id}", ['latitude' => -27.47, 'longitude' => 153.02])
            ->assertOk()
            ->assertJsonPath('data.geo_source', 'manual');

        $this->assertDatabaseHas('devices', ['id' => $device->id, 'geo_source' => 'manual']);
    }

    public function test_geo_tickets_rolls_open_tickets_up_to_their_placed_site(): void
    {
        config(['mymate.sonar.ticket_url_template' => 'https://sonar.example/app#/tickets/show/{id}']);

        $site = Site::factory()->at(43.59206, -94.83101)->create(['name' => 'Gavin Tlam']);
        $device = Device::factory()->create(['site_id' => $site->id, 'name' => 'tlam5n']);

        // Site-linked and device-linked open tickets both roll up to the same site row.
        $site->sonarTicketLinks()->create(['ticket_id' => 111, 'subject' => 'Tower power flapping', 'status' => 'OPEN']);
        $device->sonarTicketLinks()->create(['ticket_id' => 222, 'subject' => 'Sector down', 'status' => 'PENDING_INTERNAL']);
        // CLOSED is not an open ticket - never drawn.
        $device->sonarTicketLinks()->create(['ticket_id' => 333, 'subject' => 'Old resolved thing', 'status' => 'CLOSED']);

        // A ticket on a device at an UNPLACED site can't be drawn - excluded, not a null island.
        $bare = Site::factory()->create(['latitude' => null, 'longitude' => null]);
        Device::factory()->create(['site_id' => $bare->id])
            ->sonarTicketLinks()->create(['ticket_id' => 444, 'subject' => 'Invisible', 'status' => 'OPEN']);

        $data = $this->getJson('/api/geo/tickets')->assertOk()->json('data');

        $this->assertCount(1, $data);
        $this->assertSame($site->id, $data[0]['site_id']);
        $this->assertSame('Gavin Tlam', $data[0]['name']);
        $this->assertEqualsWithDelta(43.59206, $data[0]['lat'], 0.0001);
        $ids = array_column($data[0]['tickets'], 'ticket_id');
        sort($ids);
        $this->assertSame([111, 222], $ids);

        $byId = array_column($data[0]['tickets'], null, 'ticket_id');
        $this->assertSame('https://sonar.example/app#/tickets/show/111', $byId[111]['url']);
        $this->assertNull($byId[111]['via_device']); // site-linked: no device attribution
        $this->assertSame('tlam5n', $byId[222]['via_device']); // device-linked: names the radio
    }

    public function test_geo_tickets_treats_never_refreshed_links_as_open(): void
    {
        // A just-linked ticket has status NULL until the first Sonar refresh lands - the map
        // must show it (it is overwhelmingly an open ticket; that's why someone linked it).
        $site = Site::factory()->at(41.0, -97.0)->create();
        $site->sonarTicketLinks()->create(['ticket_id' => 555]);

        $this->getJson('/api/geo/tickets')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.tickets.0.ticket_id', 555);
    }

    public function test_csp_allows_the_configured_tile_host(): void
    {
        config(['mymate.map.tile_csp_hosts' => 'https://tiles.example.net']);
        // The security headers ride the web (SPA) response.
        $csp = $this->get('/')->headers->get('Content-Security-Policy');
        $this->assertStringContainsString("img-src 'self' data: blob: https://tiles.example.net", (string) $csp);
        // MapLibre GL renders in a worker spawned from a blob: URL.
        $this->assertStringContainsString("worker-src 'self' blob:", (string) $csp);
    }
}
