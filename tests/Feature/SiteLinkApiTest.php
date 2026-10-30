<?php

namespace Tests\Feature;

use App\Models\Site;
use App\Models\SiteLink;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Site-to-site backhaul links used to be writable only through the artisan OSS import
 * (mymate:sites:import --links), so an operator who spotted a missing or wrong pair on the geo
 * map needed shell access to fix it. These tests cover the API write path - admin-gated, with
 * the same A-B/B-A pairing semantics the importer uses.
 */
class SiteLinkApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsUser();
    }

    public function test_lists_site_links_with_site_names(): void
    {
        [$a, $b] = Site::factory()->count(2)->create();
        SiteLink::create(['site_a_id' => $a->id, 'site_b_id' => $b->id, 'media_type' => 'wireless']);

        $this->getJson('/api/site-links')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.site_a_name', $a->name)
            ->assertJsonPath('data.0.site_b_name', $b->name)
            ->assertJsonPath('data.0.media_type', 'wireless');
    }

    public function test_creates_a_site_link(): void
    {
        [$a, $b] = Site::factory()->count(2)->create();

        $this->postJson('/api/site-links', [
            'site_a_id' => $a->id, 'site_b_id' => $b->id, 'media_type' => 'wireless',
        ])
            ->assertCreated()
            ->assertJsonPath('data.site_a_id', $a->id)
            ->assertJsonPath('data.media_type', 'wireless');

        $this->assertDatabaseHas('site_links', ['site_a_id' => $a->id, 'site_b_id' => $b->id]);
    }

    public function test_create_fills_the_order_independent_pair_key_the_importer_uses(): void
    {
        [$a, $b] = Site::factory()->count(2)->create();

        $this->postJson('/api/site-links', ['site_a_id' => $a->id, 'site_b_id' => $b->id])
            ->assertCreated();

        $expectedRef = 'pair:'.min($a->id, $b->id).'-'.max($a->id, $b->id);
        $this->assertDatabaseHas('site_links', ['site_a_id' => $a->id, 'site_b_id' => $b->id, 'external_ref' => $expectedRef]);
    }

    public function test_rejects_a_duplicate_pair_in_either_orientation(): void
    {
        [$a, $b] = Site::factory()->count(2)->create();
        SiteLink::create(['site_a_id' => $a->id, 'site_b_id' => $b->id]);

        // Same orientation...
        $this->postJson('/api/site-links', ['site_a_id' => $a->id, 'site_b_id' => $b->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['site_a_id']);

        // ...and flipped: B-A is the same link as A-B.
        $this->postJson('/api/site-links', ['site_a_id' => $b->id, 'site_b_id' => $a->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['site_a_id']);
    }

    public function test_rejects_a_link_to_itself(): void
    {
        $a = Site::factory()->create();

        $this->postJson('/api/site-links', ['site_a_id' => $a->id, 'site_b_id' => $a->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['site_b_id']);
    }

    public function test_rejects_a_bad_media_type(): void
    {
        [$a, $b] = Site::factory()->count(2)->create();

        $this->postJson('/api/site-links', ['site_a_id' => $a->id, 'site_b_id' => $b->id, 'media_type' => 'pigeon'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['media_type']);
    }

    public function test_updates_media_type_and_ends(): void
    {
        [$a, $b, $c] = Site::factory()->count(3)->create();
        $link = SiteLink::create(['site_a_id' => $a->id, 'site_b_id' => $b->id]);

        $this->putJson("/api/site-links/{$link->id}", [
            'site_a_id' => $a->id, 'site_b_id' => $c->id, 'media_type' => 'fiber',
        ])
            ->assertOk()
            ->assertJsonPath('data.site_b_id', $c->id)
            ->assertJsonPath('data.media_type', 'fiber');

        $this->assertDatabaseHas('site_links', ['id' => $link->id, 'site_a_id' => $a->id, 'site_b_id' => $c->id]);
    }

    public function test_update_refuses_to_take_over_another_links_pair(): void
    {
        [$a, $b, $c, $d] = Site::factory()->count(4)->create();
        $link = SiteLink::create(['site_a_id' => $a->id, 'site_b_id' => $b->id]); // being edited
        $other = SiteLink::create(['site_a_id' => $c->id, 'site_b_id' => $d->id]); // target pair

        $this->putJson("/api/site-links/{$link->id}", ['site_a_id' => $d->id, 'site_b_id' => $c->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['site_a_id']);

        $this->assertDatabaseHas('site_links', ['id' => $other->id, 'site_a_id' => $c->id, 'site_b_id' => $d->id]);
    }

    public function test_updates_can_keep_the_pair_untouched(): void
    {
        [$a, $b] = Site::factory()->count(2)->create();
        $link = SiteLink::create(['site_a_id' => $a->id, 'site_b_id' => $b->id]);

        // Updating media_type only must not trip the pair check.
        $this->putJson("/api/site-links/{$link->id}", ['media_type' => 'fiber'])
            ->assertOk()
            ->assertJsonPath('data.media_type', 'fiber');
    }

    public function test_deletes_a_site_link(): void
    {
        [$a, $b] = Site::factory()->count(2)->create();
        $link = SiteLink::create(['site_a_id' => $a->id, 'site_b_id' => $b->id]);

        $this->deleteJson("/api/site-links/{$link->id}")->assertNoContent();

        $this->assertDatabaseMissing('site_links', ['id' => $link->id]);
    }

    public function test_non_admin_cannot_write_site_links(): void
    {
        $this->actingAs(User::factory()->create()); // read-only tier
        [$a, $b] = Site::factory()->count(2)->create();

        $this->postJson('/api/site-links', ['site_a_id' => $a->id, 'site_b_id' => $b->id])
            ->assertForbidden();
    }

    public function test_non_admin_can_still_read_site_links(): void
    {
        $this->actingAs(User::factory()->create());
        [$a, $b] = Site::factory()->count(2)->create();
        SiteLink::create(['site_a_id' => $a->id, 'site_b_id' => $b->id]);

        $this->getJson('/api/site-links')->assertOk()->assertJsonCount(1, 'data');
    }
}
