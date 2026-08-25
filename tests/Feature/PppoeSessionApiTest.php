<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\PppoeSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PppoeSessionApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsUser();
    }

    public function test_lists_sessions_with_device_join_in_cursor_paginated_envelope(): void
    {
        $device = Device::factory()->create(['name' => 'CONC-1', 'site_id' => null]);

        PppoeSession::create([
            'device_id' => $device->id,
            'username' => 'jsmith1',
            'remote_address' => '10.50.1.20',
            'caller_id' => 'AA:BB:CC:DD:EE:FF',
            'uptime_seconds' => 3600,
            'swept_at' => now(),
        ]);

        $response = $this->getJson('/api/pppoe-sessions')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $row = $response->json('data.0');
        $this->assertSame($device->id, $row['device_id']);
        $this->assertSame('CONC-1', $row['device_name']);
        $this->assertNull($row['site_id']);
        $this->assertSame('jsmith1', $row['username']);
        $this->assertSame('10.50.1.20', $row['remote_address']);
        $this->assertSame('AA:BB:CC:DD:EE:FF', $row['caller_id']);
        $this->assertSame(3600, $row['uptime_seconds']);
        $this->assertNotNull($row['swept_at']);

        // Cursor-paginated envelope, unconditionally (unlike Outage's opt-in per_page) - a
        // single-row result is the LAST page, so `next_cursor` is legitimately null; assert
        // the envelope shape (the paginator's meta keys are present) rather than its value.
        $this->assertArrayHasKey('meta', $response->json());
        $this->assertArrayHasKey('next_cursor', $response->json('meta'));
        $this->assertNotNull($response->json('meta.path'));
    }

    public function test_since_filters_out_rows_swept_before_it_and_accepts_epoch_ms(): void
    {
        $old = Device::factory()->create();
        $new = Device::factory()->create();

        PppoeSession::create(['device_id' => $old->id, 'username' => 'old-user', 'swept_at' => now()->subHours(2)]);
        PppoeSession::create(['device_id' => $new->id, 'username' => 'new-user', 'swept_at' => now()]);

        // ISO8601 form.
        $this->getJson('/api/pppoe-sessions?since='.now()->subHour()->toIso8601String())
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.username', 'new-user');

        // Raw epoch-ms form.
        $sinceMs = (int) now()->subHour()->getTimestampMs();
        $this->getJson("/api/pppoe-sessions?since={$sinceMs}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.username', 'new-user');
    }

    public function test_username_is_an_exact_match(): void
    {
        $device = Device::factory()->create();

        PppoeSession::create(['device_id' => $device->id, 'username' => 'ghansentrucking13', 'swept_at' => now()]);
        PppoeSession::create(['device_id' => $device->id, 'username' => 'ghansentrucking13-backup', 'swept_at' => now()]);

        $this->getJson('/api/pppoe-sessions?username=ghansentrucking13')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.username', 'ghansentrucking13');
    }

    public function test_q_is_a_case_insensitive_substring_match(): void
    {
        $device = Device::factory()->create();

        PppoeSession::create(['device_id' => $device->id, 'username' => 'GHansenTrucking13', 'swept_at' => now()]);
        PppoeSession::create(['device_id' => $device->id, 'username' => 'someone-else', 'swept_at' => now()]);

        $this->getJson('/api/pppoe-sessions?q=hansentruck')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.username', 'GHansenTrucking13');
    }

    public function test_filters_by_device_id(): void
    {
        $a = Device::factory()->create();
        $b = Device::factory()->create();

        PppoeSession::create(['device_id' => $a->id, 'username' => 'user-a', 'swept_at' => now()]);
        PppoeSession::create(['device_id' => $b->id, 'username' => 'user-b', 'swept_at' => now()]);

        $this->getJson("/api/pppoe-sessions?device_id={$a->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.device_id', $a->id);
    }

    public function test_per_page_is_honored_and_capped(): void
    {
        $device = Device::factory()->create();

        foreach (range(1, 5) as $i) {
            PppoeSession::create(['device_id' => $device->id, 'username' => "user-{$i}", 'swept_at' => now()]);
        }

        $this->getJson('/api/pppoe-sessions?per_page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->getJson('/api/pppoe-sessions?per_page=2001')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['per_page']);
    }

    public function test_invalid_since_is_rejected(): void
    {
        $this->getJson('/api/pppoe-sessions?since=not-a-date')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['since']);
    }

    public function test_stale_rows_are_hidden_by_default_and_revealed_on_request(): void
    {
        $device = Device::factory()->create();

        // A concentrator that dropped out of the sweep - renamed, unmonitored, credential
        // pulled. Its last sessions must not keep answering "these customers are online".
        PppoeSession::create(['device_id' => $device->id, 'username' => 'long-gone', 'swept_at' => now()->subDay()]);
        PppoeSession::create(['device_id' => $device->id, 'username' => 'current', 'swept_at' => now()]);

        $this->getJson('/api/pppoe-sessions')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.username', 'current');

        $this->getJson('/api/pppoe-sessions?stale=1')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/pppoe-sessions?max_age_minutes=0')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_array_params_are_rejected_rather_than_500ing(): void
    {
        $this->getJson('/api/pppoe-sessions?username[]=x')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['username']);

        $this->getJson('/api/pppoe-sessions?q[]=x')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['q']);

        $this->getJson('/api/pppoe-sessions?since[]=x')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['since']);
    }

    public function test_since_accepts_epoch_seconds_as_well_as_milliseconds(): void
    {
        $device = Device::factory()->create();
        PppoeSession::create(['device_id' => $device->id, 'username' => 'current', 'swept_at' => now()]);

        $this->getJson('/api/pppoe-sessions?since='.now()->subMinutes(5)->getTimestamp())
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_an_out_of_range_epoch_is_a_422_not_a_500(): void
    {
        $this->getJson('/api/pppoe-sessions?since=99999999999999999999')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['since']);
    }

    public function test_q_treats_like_metacharacters_literally(): void
    {
        $device = Device::factory()->create();

        PppoeSession::create(['device_id' => $device->id, 'username' => 'a_b', 'swept_at' => now()]);
        PppoeSession::create(['device_id' => $device->id, 'username' => 'axb', 'swept_at' => now()]);

        // Unescaped, `_` is "any single character" and this would also match axb.
        $this->getJson('/api/pppoe-sessions?q=a_b')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.username', 'a_b');
    }

    public function test_requires_authentication(): void
    {
        $this->app['auth']->forgetGuards();

        $this->getJson('/api/pppoe-sessions')->assertUnauthorized();
    }
}
