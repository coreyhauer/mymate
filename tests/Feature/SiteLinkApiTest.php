<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\Site;
use App\Models\SiteLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SiteLinkApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsUser();
    }

    public function test_lists_all_site_links_with_full_row_and_decoded_evidence(): void
    {
        $siteA = Site::factory()->create();
        $siteB = Site::factory()->create();
        $deviceA = Device::factory()->create();
        $deviceB = Device::factory()->create();

        $resolved = SiteLink::create([
            'site_a_id' => $siteA->id,
            'site_b_id' => $siteB->id,
            'media_type' => 'wireless',
            'external_ref' => 'uisp:link-1',
            'device_a_id' => $deviceA->id,
            'device_b_id' => $deviceB->id,
            'endpoint_confidence' => 'reciprocal_name',
            'endpoint_method' => 'matched alpha2bravo tokens',
            'endpoint_evidence' => ['matched_tokens' => ['alpha', 'bravo'], 'distance_mi' => 1.4],
            'endpoints_resolved_at' => now(),
        ]);

        SiteLink::create([
            'site_a_id' => $siteA->id,
            'site_b_id' => $siteB->id,
            'media_type' => 'fiber',
            'external_ref' => 'uisp:link-2',
        ]);

        $response = $this->getJson('/api/site-links')->assertOk()->assertJsonCount(2, 'data');

        $row = collect($response->json('data'))->firstWhere('id', $resolved->id);
        $this->assertSame($siteA->id, $row['site_a_id']);
        $this->assertSame($siteB->id, $row['site_b_id']);
        $this->assertSame('wireless', $row['media_type']);
        $this->assertSame($deviceA->id, $row['device_a_id']);
        $this->assertSame($deviceB->id, $row['device_b_id']);
        $this->assertSame('reciprocal_name', $row['endpoint_confidence']);
        $this->assertSame('matched alpha2bravo tokens', $row['endpoint_method']);
        $this->assertSame(['matched_tokens' => ['alpha', 'bravo'], 'distance_mi' => 1.4], $row['endpoint_evidence']);
        $this->assertNotNull($row['endpoints_resolved_at']);
    }

    public function test_filters_by_site_id_on_either_end(): void
    {
        $siteA = Site::factory()->create();
        $siteB = Site::factory()->create();
        $other = Site::factory()->create();

        SiteLink::create(['site_a_id' => $siteA->id, 'site_b_id' => $siteB->id, 'external_ref' => 'l1']);
        SiteLink::create(['site_a_id' => $other->id, 'site_b_id' => $siteA->id, 'external_ref' => 'l2']);
        SiteLink::create(['site_a_id' => $other->id, 'site_b_id' => $other->id, 'external_ref' => 'l3']);

        $this->getJson("/api/site-links?site_id={$siteA->id}")->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_filters_by_media_type(): void
    {
        $siteA = Site::factory()->create();
        $siteB = Site::factory()->create();

        SiteLink::create(['site_a_id' => $siteA->id, 'site_b_id' => $siteB->id, 'media_type' => 'fiber', 'external_ref' => 'l1']);
        SiteLink::create(['site_a_id' => $siteA->id, 'site_b_id' => $siteB->id, 'media_type' => 'wireless', 'external_ref' => 'l2']);

        $this->getJson('/api/site-links?media_type=fiber')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.media_type', 'fiber');
    }

    public function test_filters_by_confidence(): void
    {
        $siteA = Site::factory()->create();
        $siteB = Site::factory()->create();

        SiteLink::create([
            'site_a_id' => $siteA->id, 'site_b_id' => $siteB->id,
            'external_ref' => 'l1', 'endpoint_confidence' => 'reciprocal_name',
        ]);
        SiteLink::create([
            'site_a_id' => $siteA->id, 'site_b_id' => $siteB->id,
            'external_ref' => 'l2', 'endpoint_confidence' => 'name_only_fuzzy',
        ]);

        $this->getJson('/api/site-links?confidence=reciprocal_name')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.endpoint_confidence', 'reciprocal_name');
    }

    public function test_filters_by_resolved(): void
    {
        $siteA = Site::factory()->create();
        $siteB = Site::factory()->create();
        $deviceA = Device::factory()->create();
        $deviceB = Device::factory()->create();

        SiteLink::create([
            'site_a_id' => $siteA->id, 'site_b_id' => $siteB->id, 'external_ref' => 'l1',
            'device_a_id' => $deviceA->id, 'device_b_id' => $deviceB->id,
        ]);
        SiteLink::create(['site_a_id' => $siteA->id, 'site_b_id' => $siteB->id, 'external_ref' => 'l2']);
        SiteLink::create([
            'site_a_id' => $siteA->id, 'site_b_id' => $siteB->id, 'external_ref' => 'l3',
            'device_a_id' => $deviceA->id, // one end only - not resolved
        ]);

        $this->getJson('/api/site-links?resolved=1')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_requires_authentication(): void
    {
        $this->app['auth']->forgetGuards();

        $siteA = Site::factory()->create();
        $siteB = Site::factory()->create();
        SiteLink::create(['site_a_id' => $siteA->id, 'site_b_id' => $siteB->id, 'external_ref' => 'l1']);

        $this->getJson('/api/site-links')->assertUnauthorized();
    }
}
