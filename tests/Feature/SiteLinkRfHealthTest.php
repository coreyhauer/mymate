<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\RfLinkState;
use App\Models\Site;
use App\Models\SiteLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * SiteLink::rfHealth() is the read model a later map/API stage will consume (design §2e/§5 of
 * this task's BUILD list) - covered here so that stage is a thin layer over something already
 * proven, not something built blind.
 */
class SiteLinkRfHealthTest extends TestCase
{
    use RefreshDatabase;

    private function link(?Device $a, ?Device $b): SiteLink
    {
        $siteA = Site::factory()->create();
        $siteB = Site::factory()->create();

        return SiteLink::create([
            'site_a_id' => $siteA->id, 'site_b_id' => $siteB->id, 'media_type' => 'wireless',
            'external_ref' => 'link:'.uniqid(),
            'device_a_id' => $a?->id, 'device_b_id' => $b?->id,
        ]);
    }

    public function test_returns_health_for_both_resolved_ends(): void
    {
        $a = Device::factory()->create();
        $b = Device::factory()->create();
        RfLinkState::create(['device_id' => $a->id, 'rssi_dbm' => -50.0, 'source_lastupdate' => now(), 'synced_at' => now()]);
        RfLinkState::create(['device_id' => $b->id, 'rssi_dbm' => -55.0, 'source_lastupdate' => now(), 'synced_at' => now()]);

        $health = $this->link($a, $b)->rfHealth();

        $this->assertEqualsWithDelta(-50.0, $health['a']['rssi_dbm'], 0.001);
        $this->assertEqualsWithDelta(-55.0, $health['b']['rssi_dbm'], 0.001);
        $this->assertFalse($health['a']['stale']);
    }

    public function test_unresolved_end_is_null_not_a_fake_good_reading(): void
    {
        $a = Device::factory()->create();
        RfLinkState::create(['device_id' => $a->id, 'rssi_dbm' => -50.0, 'source_lastupdate' => now(), 'synced_at' => now()]);

        // b end never resolved (endpoint_confidence work hasn't matched a device for it).
        $health = $this->link($a, null)->rfHealth();

        $this->assertNotNull($health['a']);
        $this->assertNull($health['b']);
    }

    public function test_stale_reading_is_flagged_never_silently_shown_as_current(): void
    {
        config(['mymate.librenms_rf.stale_after_minutes' => 30]);
        $a = Device::factory()->create();
        RfLinkState::create(['device_id' => $a->id, 'rssi_dbm' => -50.0, 'source_lastupdate' => now()->subHours(2), 'synced_at' => now()]);

        $health = $this->link($a, null)->rfHealth();

        $this->assertTrue($health['a']['stale']);
    }

    public function test_missing_state_row_is_null(): void
    {
        $a = Device::factory()->create(); // never synced by the RF pull

        $health = $this->link($a, null)->rfHealth();

        $this->assertNull($health['a']);
    }
}
