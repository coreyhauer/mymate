<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Sonar ticket link endpoints. All Sonar HTTP is faked - these tests must never reach the
 * real gigfire.sonar.software API (Http::fake() throws if an unfaked request escapes it).
 *
 * `Http::fake()` stubs stack in registration order and the FIRST callback that returns a
 * response wins - a plain `Http::fake($closure)` always matches, so calling it a second time
 * in the same test does NOT override the first (it silently never gets consulted), and a bare
 * `Http::fake()` reset is just as bad the other way (its built-in blank-response default is
 * itself a match-everything stub, so it shadows anything registered after it). The one safe
 * pattern is to register exactly ONE fake per test - via `$this->sonarResponder` here, set
 * once in setUp() - and change *behaviour* mid-test by reassigning that property instead of
 * calling Http::fake() again.
 */
class SonarTicketLinkApiTest extends TestCase
{
    use RefreshDatabase;

    /** @var callable(ClientRequest): mixed - Http::response() returns a Promise, not a Response */
    private $sonarResponder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sonarResponder = fn (ClientRequest $request) => Http::response(['data' => []], 200);
        Http::fake(fn (ClientRequest $request) => ($this->sonarResponder)($request));
    }

    private function enableSonar(): void
    {
        config()->set('mymate.sonar.token', 'test-token');
        config()->set('mymate.sonar.enabled', true);
        config()->set('mymate.sonar.cache_ttl', 300);
    }

    private function disableSonar(): void
    {
        config()->set('mymate.sonar.token', '');
        config()->set('mymate.sonar.enabled', false);
    }

    /** @param array<int, array<string, mixed>> $ticketsById */
    private function fakeSonarTickets(array $ticketsById): void
    {
        $this->sonarResponder = function (ClientRequest $request) use ($ticketsById) {
            $query = $request->data()['query'] ?? '';
            preg_match_all('/(t\d+): tickets\(id: (\d+)\)/', $query, $matches, PREG_SET_ORDER);

            $data = [];
            foreach ($matches as [$full, $alias, $id]) {
                $ticket = $ticketsById[(int) $id] ?? null;
                $data[$alias] = [
                    'entities' => $ticket ? [$ticket] : [],
                    'page_info' => ['total_count' => $ticket ? 1 : 0],
                ];
            }

            return Http::response(['data' => $data], 200);
        };
    }

    private function fakeSonarDown(): void
    {
        $this->sonarResponder = fn () => Http::response('Service Unavailable', 500);
    }

    private function fakeSonarAuthFailure(): void
    {
        $this->sonarResponder = fn () => Http::response(['error' => 'Unauthenticated.'], 200);
    }

    private function failIfSonarIsCalled(): void
    {
        $this->sonarResponder = function (): never {
            throw new \RuntimeException('should not be called - cache is fresh');
        };
    }

    /** @return array<string, mixed> */
    private function sonarTicket(int $id, array $overrides = []): array
    {
        return array_merge([
            'id' => (string) $id,
            'subject' => 'No Internet',
            'status' => 'OPEN',
            'priority' => 'LOW',
            'description' => 'Customer reports no internet.',
            'created_at' => '2026-07-01T00:00:00+00:00',
            'updated_at' => '2026-07-01T00:00:00+00:00',
            'closed_at' => null,
            'due_date' => null,
            'ticketable_type' => 'Account',
            'ticketable_id' => '703605',
            'ticket_group' => ['id' => '13', 'name' => 'CS Level 1'],
            'user' => ['id' => '282', 'name' => 'Leovic Revilla'],
            'ticketable' => ['id' => '703605', 'name' => 'Alan Holsing East house'],
        ], $overrides);
    }

    public function test_requires_authentication(): void
    {
        $device = Device::factory()->create();

        $this->getJson("/api/devices/{$device->id}/sonar-tickets")->assertUnauthorized();
    }

    // --- Linking ------------------------------------------------------------

    public function test_links_a_ticket_by_bare_id(): void
    {
        $this->enableSonar();
        $user = $this->actingAsUser();
        $device = Device::factory()->create();
        $this->fakeSonarTickets([108314 => $this->sonarTicket(108314)]);

        $this->postJson("/api/devices/{$device->id}/sonar-tickets", ['reference' => '108314'])
            ->assertCreated()
            ->assertJsonPath('data.ticket_id', 108314)
            ->assertJsonPath('data.subject', 'No Internet')
            ->assertJsonPath('data.status', 'OPEN')
            ->assertJsonPath('data.assignee_name', 'Leovic Revilla')
            ->assertJsonPath('data.group_name', 'CS Level 1')
            ->assertJsonPath('data.account_name', 'Alan Holsing East house')
            ->assertJsonPath('data.linked_by_name', $user->name)
            ->assertJsonPath('data.editable', true)
            ->assertJsonPath('data.stale', false);

        $this->assertDatabaseHas('sonar_ticket_links', ['ticket_id' => 108314, 'linked_by' => $user->id]);
    }

    public function test_links_a_ticket_by_hash_prefixed_id(): void
    {
        $this->enableSonar();
        $this->actingAsUser();
        $device = Device::factory()->create();
        $this->fakeSonarTickets([108314 => $this->sonarTicket(108314)]);

        $this->postJson("/api/devices/{$device->id}/sonar-tickets", ['reference' => '#108314'])
            ->assertCreated()->assertJsonPath('data.ticket_id', 108314);
    }

    public function test_links_a_ticket_by_full_url(): void
    {
        $this->enableSonar();
        $this->actingAsUser();
        $device = Device::factory()->create();
        $this->fakeSonarTickets([108314 => $this->sonarTicket(108314)]);

        $this->postJson("/api/devices/{$device->id}/sonar-tickets", ['reference' => 'https://gigfire.sonar.software/#/tickets/108314'])
            ->assertCreated()->assertJsonPath('data.ticket_id', 108314);
    }

    /**
     * The URL an operator actually copies out of Sonar's address bar, including a trailing
     * query string - the id must come from the /tickets/show/ segment, not the last digits
     * on the line (which would otherwise parse as ticket #2).
     */
    public function test_links_a_ticket_by_real_sonar_url_with_query_string(): void
    {
        $this->enableSonar();
        $this->actingAsUser();
        $device = Device::factory()->create();
        $this->fakeSonarTickets([89172 => $this->sonarTicket(89172)]);

        $this->postJson("/api/devices/{$device->id}/sonar-tickets", [
            'reference' => 'https://gigfire.sonar.software/app#/tickets/show/89172?tab=2',
        ])->assertCreated()->assertJsonPath('data.ticket_id', 89172);
    }

    public function test_rejects_a_reference_with_no_digits(): void
    {
        $this->enableSonar();
        $this->actingAsUser();
        $device = Device::factory()->create();

        $this->postJson("/api/devices/{$device->id}/sonar-tickets", ['reference' => 'not-a-ticket'])
            ->assertStatus(422);
    }

    public function test_unknown_ticket_id_is_a_404(): void
    {
        $this->enableSonar();
        $this->actingAsUser();
        $device = Device::factory()->create();
        $this->fakeSonarTickets([]); // Sonar answers, but has no such ticket.

        $this->postJson("/api/devices/{$device->id}/sonar-tickets", ['reference' => '999999999'])
            ->assertStatus(404);

        $this->assertDatabaseCount('sonar_ticket_links', 0);
    }

    public function test_duplicate_link_is_a_409(): void
    {
        $this->enableSonar();
        $this->actingAsUser();
        $device = Device::factory()->create();
        $this->fakeSonarTickets([108314 => $this->sonarTicket(108314)]);

        $this->postJson("/api/devices/{$device->id}/sonar-tickets", ['reference' => '108314'])->assertCreated();
        $this->postJson("/api/devices/{$device->id}/sonar-tickets", ['reference' => '108314'])->assertStatus(409);

        $this->assertDatabaseCount('sonar_ticket_links', 1);
    }

    public function test_create_returns_503_when_sonar_is_down(): void
    {
        $this->enableSonar();
        $this->actingAsUser();
        $device = Device::factory()->create();
        $this->fakeSonarDown();

        $this->postJson("/api/devices/{$device->id}/sonar-tickets", ['reference' => '108314'])
            ->assertStatus(503);

        $this->assertDatabaseCount('sonar_ticket_links', 0);
    }

    public function test_create_returns_503_when_sonar_auth_fails(): void
    {
        $this->enableSonar();
        $this->actingAsUser();
        $device = Device::factory()->create();
        $this->fakeSonarAuthFailure();

        $this->postJson("/api/devices/{$device->id}/sonar-tickets", ['reference' => '108314'])
            ->assertStatus(503);
    }

    public function test_create_returns_503_when_sonar_is_not_configured(): void
    {
        $this->disableSonar();
        $this->actingAsUser();
        $device = Device::factory()->create();

        $this->postJson("/api/devices/{$device->id}/sonar-tickets", ['reference' => '108314'])
            ->assertStatus(503);
    }

    // --- Reading / staleness --------------------------------------------

    public function test_read_serves_stale_cache_instead_of_5xx_when_sonar_is_down(): void
    {
        $this->enableSonar();
        $this->actingAsUser();
        $device = Device::factory()->create();
        $this->fakeSonarTickets([108314 => $this->sonarTicket(108314)]);

        $this->postJson("/api/devices/{$device->id}/sonar-tickets", ['reference' => '108314'])->assertCreated();

        // Age the cache past the TTL, then take Sonar down for the read.
        $this->travel(400)->seconds();
        $this->fakeSonarDown();

        $this->getJson("/api/devices/{$device->id}/sonar-tickets")
            ->assertOk()
            ->assertJsonPath('data.0.ticket_id', 108314)
            ->assertJsonPath('data.0.subject', 'No Internet') // last-known-good, not lost
            ->assertJsonPath('data.0.stale', true);
    }

    public function test_read_refreshes_a_stale_cached_ticket(): void
    {
        $this->enableSonar();
        $this->actingAsUser();
        $device = Device::factory()->create();
        $this->fakeSonarTickets([108314 => $this->sonarTicket(108314, ['status' => 'OPEN'])]);

        $this->postJson("/api/devices/{$device->id}/sonar-tickets", ['reference' => '108314'])->assertCreated();

        $this->travel(400)->seconds();
        $this->fakeSonarTickets([108314 => $this->sonarTicket(108314, ['status' => 'PENDING_INTERNAL'])]);

        $this->getJson("/api/devices/{$device->id}/sonar-tickets")
            ->assertOk()
            ->assertJsonPath('data.0.status', 'PENDING_INTERNAL')
            ->assertJsonPath('data.0.stale', false);

        $this->assertDatabaseHas('sonar_ticket_links', ['ticket_id' => 108314, 'status' => 'PENDING_INTERNAL']);
    }

    public function test_read_does_not_refetch_a_fresh_cache(): void
    {
        $this->enableSonar();
        $this->actingAsUser();
        $device = Device::factory()->create();
        $this->fakeSonarTickets([108314 => $this->sonarTicket(108314)]);

        $this->postJson("/api/devices/{$device->id}/sonar-tickets", ['reference' => '108314'])->assertCreated();

        $this->failIfSonarIsCalled();

        $this->getJson("/api/devices/{$device->id}/sonar-tickets")->assertOk();
    }

    // --- Site/link scoping, manual refresh, deletion ---------------------

    public function test_site_and_link_scoped_tickets_are_isolated(): void
    {
        $this->enableSonar();
        $this->actingAsUser();
        $site = Site::factory()->create();
        $this->fakeSonarTickets([108314 => $this->sonarTicket(108314)]);

        $this->postJson("/api/sites/{$site->id}/sonar-tickets", ['reference' => '108314'])->assertCreated();

        $this->getJson("/api/sites/{$site->id}/sonar-tickets")->assertOk()->assertJsonCount(1, 'data');

        $device = Device::factory()->create();
        $this->getJson("/api/devices/{$device->id}/sonar-tickets")->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_manual_refresh_endpoint(): void
    {
        $this->enableSonar();
        $this->actingAsUser();
        $device = Device::factory()->create();
        $this->fakeSonarTickets([108314 => $this->sonarTicket(108314, ['status' => 'OPEN'])]);

        $linkId = $this->postJson("/api/devices/{$device->id}/sonar-tickets", ['reference' => '108314'])->json('data.id');

        $this->fakeSonarTickets([108314 => $this->sonarTicket(108314, ['status' => 'CLOSED'])]);

        $this->postJson("/api/sonar-tickets/{$linkId}/refresh")
            ->assertOk()->assertJsonPath('data.status', 'CLOSED');
    }

    public function test_delete_removes_the_link(): void
    {
        $this->enableSonar();
        $this->actingAsUser();
        $device = Device::factory()->create();
        $this->fakeSonarTickets([108314 => $this->sonarTicket(108314)]);

        $linkId = $this->postJson("/api/devices/{$device->id}/sonar-tickets", ['reference' => '108314'])->json('data.id');

        $this->deleteJson("/api/sonar-tickets/{$linkId}")->assertNoContent();
        $this->assertDatabaseCount('sonar_ticket_links', 0);
    }

    // --- Authorship / non-admin behaviour --------------------------------

    public function test_non_admin_can_link_a_ticket(): void
    {
        $this->enableSonar();
        $device = Device::factory()->create();
        $viewer = User::factory()->create();
        $this->actingAs($viewer);
        $this->fakeSonarTickets([108314 => $this->sonarTicket(108314)]);

        $this->postJson("/api/devices/{$device->id}/sonar-tickets", ['reference' => '108314'])
            ->assertCreated()->assertJsonPath('data.linked_by_name', $viewer->name);
    }

    public function test_non_admin_cannot_delete_another_users_link(): void
    {
        $this->enableSonar();
        $device = Device::factory()->create();
        $author = User::factory()->create();
        $other = User::factory()->create();

        $this->actingAs($author);
        $this->fakeSonarTickets([108314 => $this->sonarTicket(108314)]);
        $linkId = $this->postJson("/api/devices/{$device->id}/sonar-tickets", ['reference' => '108314'])->json('data.id');

        $this->actingAs($other);
        $this->deleteJson("/api/sonar-tickets/{$linkId}")->assertForbidden();
        $this->assertDatabaseHas('sonar_ticket_links', ['id' => $linkId]);
    }

    public function test_admin_can_delete_any_users_link(): void
    {
        $this->enableSonar();
        $device = Device::factory()->create();
        $author = User::factory()->create();

        $this->actingAs($author);
        $this->fakeSonarTickets([108314 => $this->sonarTicket(108314)]);
        $linkId = $this->postJson("/api/devices/{$device->id}/sonar-tickets", ['reference' => '108314'])->json('data.id');

        $this->actingAsUser(); // admin
        $this->deleteJson("/api/sonar-tickets/{$linkId}")->assertNoContent();
    }
}
