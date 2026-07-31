<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\Link;
use App\Models\NetworkInterface;
use App\Models\Note;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NoteApiTest extends TestCase
{
    use RefreshDatabase;

    private function link(): Link
    {
        $a = Device::factory()->create();
        $b = Device::factory()->create();
        $aIf = NetworkInterface::factory()->for($a)->create();
        $bIf = NetworkInterface::factory()->for($b)->create();

        return Link::create([
            'a_device_id' => $a->id, 'a_interface_id' => $aIf->id,
            'b_device_id' => $b->id, 'b_interface_id' => $bIf->id,
        ]);
    }

    public function test_requires_authentication(): void
    {
        $device = Device::factory()->create();

        $this->getJson("/api/devices/{$device->id}/notes")->assertUnauthorized();
    }

    public function test_crud_lifecycle_on_a_device(): void
    {
        $user = $this->actingAsUser();
        $device = Device::factory()->create();

        $res = $this->postJson("/api/devices/{$device->id}/notes", ['body' => 'Swapped the PoE injector'])
            ->assertCreated()
            ->assertJsonPath('data.body', 'Swapped the PoE injector')
            ->assertJsonPath('data.author_id', $user->id)
            ->assertJsonPath('data.author_name', $user->name)
            ->assertJsonPath('data.editable', true);

        $id = $res->json('data.id');

        $this->getJson("/api/devices/{$device->id}/notes")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $id);

        $this->patchJson("/api/notes/{$id}", ['body' => 'Swapped the PoE injector - resolved'])
            ->assertOk()
            ->assertJsonPath('data.body', 'Swapped the PoE injector - resolved');

        $this->deleteJson("/api/notes/{$id}")->assertNoContent();
        $this->assertDatabaseCount('notes', 0);
    }

    public function test_listed_newest_first(): void
    {
        $this->actingAsUser();
        $device = Device::factory()->create();

        $first = $this->postJson("/api/devices/{$device->id}/notes", ['body' => 'first'])->json('data.id');
        $second = $this->postJson("/api/devices/{$device->id}/notes", ['body' => 'second'])->json('data.id');

        $this->getJson("/api/devices/{$device->id}/notes")
            ->assertOk()
            ->assertJsonPath('data.0.id', $second)
            ->assertJsonPath('data.1.id', $first);
    }

    public function test_site_and_link_scoped_notes_are_isolated(): void
    {
        $this->actingAsUser();
        $site = Site::factory()->create();
        $link = $this->link();

        $this->postJson("/api/sites/{$site->id}/notes", ['body' => 'site note'])->assertCreated();
        $this->postJson("/api/links/{$link->id}/notes", ['body' => 'link note'])->assertCreated();

        $this->getJson("/api/sites/{$site->id}/notes")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.body', 'site note');
        $this->getJson("/api/links/{$link->id}/notes")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.body', 'link note');
    }

    public function test_rejects_an_empty_or_too_long_body(): void
    {
        $this->actingAsUser();
        $device = Device::factory()->create();

        $this->postJson("/api/devices/{$device->id}/notes", ['body' => ''])
            ->assertStatus(422)->assertJsonValidationErrors('body');

        $this->postJson("/api/devices/{$device->id}/notes", ['body' => str_repeat('x', 5001)])
            ->assertStatus(422)->assertJsonValidationErrors('body');
    }

    // --- Authorship / non-admin behaviour --------------------------------

    public function test_non_admin_can_create_a_note_on_a_device(): void
    {
        $device = Device::factory()->create();
        $viewer = User::factory()->create();
        $this->actingAs($viewer);

        $this->postJson("/api/devices/{$device->id}/notes", ['body' => 'operator note'])
            ->assertCreated()
            ->assertJsonPath('data.author_id', $viewer->id)
            ->assertJsonPath('data.editable', true);
    }

    public function test_non_admin_cannot_edit_or_delete_another_users_note(): void
    {
        $device = Device::factory()->create();
        $author = User::factory()->create();
        $other = User::factory()->create();

        $note = $device->notes()->create(['body' => 'authors note', 'author_id' => $author->id, 'author_name' => $author->name]);

        $this->actingAs($other);
        $this->getJson("/api/devices/{$device->id}/notes")->assertJsonPath('data.0.editable', false);
        $this->patchJson("/api/notes/{$note->id}", ['body' => 'hijacked'])->assertForbidden();
        $this->deleteJson("/api/notes/{$note->id}")->assertForbidden();
        $this->assertDatabaseHas('notes', ['id' => $note->id, 'body' => 'authors note']);
    }

    public function test_non_admin_can_edit_their_own_note(): void
    {
        $device = Device::factory()->create();
        $author = User::factory()->create();

        $note = $device->notes()->create(['body' => 'mine', 'author_id' => $author->id, 'author_name' => $author->name]);

        $this->actingAs($author);
        $this->patchJson("/api/notes/{$note->id}", ['body' => 'mine, edited'])
            ->assertOk()->assertJsonPath('data.body', 'mine, edited');
        $this->deleteJson("/api/notes/{$note->id}")->assertNoContent();
    }

    public function test_admin_can_edit_or_delete_any_users_note(): void
    {
        $device = Device::factory()->create();
        $author = User::factory()->create();
        $note = $device->notes()->create(['body' => 'authors note', 'author_id' => $author->id, 'author_name' => $author->name]);

        $this->actingAsUser(); // admin

        $this->patchJson("/api/notes/{$note->id}", ['body' => 'admin edited'])->assertOk();
        $this->deleteJson("/api/notes/{$note->id}")->assertNoContent();
    }

    public function test_note_survives_the_authors_removal(): void
    {
        $device = Device::factory()->create();
        $author = User::factory()->create(['name' => 'Departed Operator']);
        $note = $device->notes()->create(['body' => 'orphaned', 'author_id' => $author->id, 'author_name' => $author->name]);

        $author->delete();
        $this->actingAsUser();

        $this->getJson("/api/devices/{$device->id}/notes")
            ->assertOk()
            ->assertJsonPath('data.0.author_name', 'Departed Operator')
            ->assertJsonPath('data.0.body', 'orphaned');
        $this->assertDatabaseHas('notes', ['id' => $note->id]);
    }
}
