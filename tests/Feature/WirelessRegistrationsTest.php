<?php

namespace Tests\Feature;

use App\Actions\Polling\ReadWireless;
use App\Models\Device;
use App\Models\WirelessRegistration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Persist semantics of App\Actions\Polling\ReadWireless (the OSPF pattern, PPPoE's
 * empty-read rule) plus the WirelessRegistrationController read API - mirrors
 * ReadOspfTest + OspfNeighborApiTest.
 */
class WirelessRegistrationsTest extends TestCase
{
    use RefreshDatabase;

    // ------------------------------------------------------------------
    // normalizeMac
    // ------------------------------------------------------------------

    public function test_normalize_mac_lowercases_and_reseparates(): void
    {
        $this->assertSame('aa:bb:cc:dd:ee:ff', ReadWireless::normalizeMac('AA:BB:CC:DD:EE:FF'));
        $this->assertSame('aa:bb:cc:dd:ee:ff', ReadWireless::normalizeMac('aabb.ccdd.eeff'));
        $this->assertSame('aa:bb:cc:dd:ee:ff', ReadWireless::normalizeMac('AABBCCDDEEFF'));
    }

    public function test_normalize_mac_rejects_anything_not_twelve_hex_digits(): void
    {
        $this->assertSame('', ReadWireless::normalizeMac(''));
        $this->assertSame('', ReadWireless::normalizeMac(null));
        $this->assertSame('', ReadWireless::normalizeMac('aa:bb:cc'));
        $this->assertSame('', ReadWireless::normalizeMac(12345));
    }

    // ------------------------------------------------------------------
    // persist()
    // ------------------------------------------------------------------

    public function test_persist_inserts_shaped_rows(): void
    {
        $device = Device::factory()->create();

        app(ReadWireless::class)->persist($device->id, [
            [
                'interface' => 'wlan1',
                'mac-address' => 'AA:BB:CC:DD:EE:01',
                'signal-strength' => '-65dBm@6Mbps',
                'signal-to-noise' => '38',
                'tx-ccq' => '95',
                'tx-rate' => '300Mbps-40MHz-1S',
                'rx-rate' => '150Mbps-20MHz-1S',
                'uptime' => '1h30m',
                'last-activity' => '100ms',
            ],
        ]);

        $row = WirelessRegistration::where('device_id', $device->id)->sole();
        $this->assertSame('wlan1', $row->interface);
        $this->assertSame('aa:bb:cc:dd:ee:01', $row->mac_address);
        $this->assertSame(-65.0, $row->signal_strength_dbm);
        $this->assertSame(38.0, $row->signal_to_noise_db);
        $this->assertSame(95.0, $row->tx_ccq_pct);
        $this->assertSame('300Mbps-40MHz-1S', $row->tx_rate);
        $this->assertSame('150Mbps-20MHz-1S', $row->rx_rate);
        $this->assertSame(5400, $row->uptime_seconds);
        $this->assertSame(0, $row->last_activity_seconds);
        $this->assertNotNull($row->first_seen_at);
        $this->assertNotNull($row->last_seen_at);
    }

    public function test_first_seen_at_is_insert_only_and_survives_a_later_update(): void
    {
        $device = Device::factory()->create();
        $row = [
            'interface' => 'wlan1', 'mac-address' => 'aa:bb:cc:dd:ee:01',
            'signal-strength' => '-60', 'uptime' => '1h',
        ];

        app(ReadWireless::class)->persist($device->id, [$row]);
        $original = WirelessRegistration::where('device_id', $device->id)->sole();
        $firstSeenAt = $original->first_seen_at;

        $this->travel(10)->minutes();

        // Same client, updated reading (signal moved) - the natural key conflicts and the
        // row is updated, but first_seen_at must not move.
        $row['signal-strength'] = '-70';
        app(ReadWireless::class)->persist($device->id, [$row]);

        $updated = WirelessRegistration::where('device_id', $device->id)->sole();
        $this->assertSame($original->id, $updated->id);
        $this->assertSame(-70.0, $updated->signal_strength_dbm);
        $this->assertTrue($firstSeenAt->equalTo($updated->first_seen_at));
        $this->assertTrue($updated->last_seen_at->greaterThan($original->last_seen_at));
    }

    public function test_prunes_a_vanished_client_only_when_the_read_returned_at_least_one_row(): void
    {
        $device = Device::factory()->create();

        app(ReadWireless::class)->persist($device->id, [
            ['mac-address' => 'aa:bb:cc:dd:ee:01'],
            ['mac-address' => 'aa:bb:cc:dd:ee:02'],
        ]);
        $this->assertSame(2, WirelessRegistration::where('device_id', $device->id)->count());

        // A non-empty read that no longer carries the second client prunes it.
        app(ReadWireless::class)->persist($device->id, [
            ['mac-address' => 'aa:bb:cc:dd:ee:01'],
        ]);
        $this->assertSame(['aa:bb:cc:dd:ee:01'], WirelessRegistration::where('device_id', $device->id)->pluck('mac_address')->all());
    }

    public function test_empty_read_is_untrusted_and_prunes_nothing(): void
    {
        $device = Device::factory()->create();

        app(ReadWireless::class)->persist($device->id, [
            ['mac-address' => 'aa:bb:cc:dd:ee:01'],
            ['mac-address' => 'aa:bb:cc:dd:ee:02'],
        ]);
        $this->assertSame(2, WirelessRegistration::where('device_id', $device->id)->count());

        // An empty registration table (radio unreachable, or genuinely client-less right now)
        // must not be recorded as "no clients" - unlike OSPF, there is nothing here to tell
        // the two cases apart.
        app(ReadWireless::class)->persist($device->id, []);

        $this->assertSame(2, WirelessRegistration::where('device_id', $device->id)->count());
    }

    public function test_in_batch_dedupe_keeps_one_row_per_natural_key(): void
    {
        $device = Device::factory()->create();

        // The same client can legitimately appear in more than one registration-table union
        // (classic + wifiwave2), which would otherwise sink the whole upsert statement.
        app(ReadWireless::class)->persist($device->id, [
            ['mac-address' => 'aa:bb:cc:dd:ee:01', 'signal-strength' => '-60'],
            ['mac-address' => 'aa:bb:cc:dd:ee:01', 'signal-strength' => '-61'],
        ]);

        $this->assertSame(1, WirelessRegistration::where('device_id', $device->id)->count());
    }

    public function test_persist_is_a_noop_when_disabled_by_config(): void
    {
        config(['mymate.wireless.persist' => false]);
        $device = Device::factory()->create();

        app(ReadWireless::class)->persist($device->id, [['mac-address' => 'aa:bb:cc:dd:ee:01']]);

        $this->assertSame(0, WirelessRegistration::where('device_id', $device->id)->count());
    }

    public function test_a_db_failure_during_persist_is_swallowed_not_thrown(): void
    {
        $this->expectNotToPerformAssertions();

        // A device id with no matching row: the FK constraint on device_id makes the insert
        // fail inside the transaction, which must be caught and logged, not bubble up and cost
        // the caller its metrics reading.
        app(ReadWireless::class)->persist(999999, [['mac-address' => 'aa:bb:cc:dd:ee:01']]);
    }

    // ------------------------------------------------------------------
    // API
    // ------------------------------------------------------------------

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsUser();
    }

    public function test_lists_registrations_with_device_join_in_cursor_paginated_envelope(): void
    {
        $device = Device::factory()->create(['name' => 'AP-1', 'site_id' => null]);

        WirelessRegistration::create([
            'device_id' => $device->id,
            'interface' => 'wlan1',
            'mac_address' => 'aa:bb:cc:dd:ee:01',
            'signal_strength_dbm' => -65.0,
            'signal_to_noise_db' => 38.0,
            'tx_ccq_pct' => 95.0,
            'tx_rate' => '300Mbps',
            'rx_rate' => '150Mbps',
            'uptime_seconds' => 5400,
            'last_activity_seconds' => 0,
            'first_seen_at' => now()->subHour(),
            'last_seen_at' => now(),
        ]);

        $response = $this->getJson('/api/wireless-registrations')->assertOk()->assertJsonCount(1, 'data');

        $row = $response->json('data.0');
        $this->assertSame($device->id, $row['device_id']);
        $this->assertSame('AP-1', $row['device_name']);
        $this->assertSame('wlan1', $row['interface']);
        $this->assertSame('aa:bb:cc:dd:ee:01', $row['mac_address']);
        // JSON collapses a whole-number float to an int (-65.0 -> -65), so compare numerically.
        $this->assertEquals(-65.0, $row['signal_strength_dbm']);
        $this->assertEquals(95.0, $row['tx_ccq_pct']);
        $this->assertSame(5400, $row['uptime_seconds']);

        $this->assertArrayHasKey('next_cursor', $response->json('meta'));
    }

    public function test_stale_rows_are_hidden_by_default_and_revealed_on_request(): void
    {
        $device = Device::factory()->create();

        WirelessRegistration::create([
            'device_id' => $device->id, 'mac_address' => 'aa:bb:cc:dd:ee:09',
            'first_seen_at' => now()->subDay(), 'last_seen_at' => now()->subDay(),
        ]);
        WirelessRegistration::create([
            'device_id' => $device->id, 'mac_address' => 'aa:bb:cc:dd:ee:07',
            'first_seen_at' => now(), 'last_seen_at' => now(),
        ]);

        $this->getJson('/api/wireless-registrations')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.mac_address', 'aa:bb:cc:dd:ee:07');

        $this->getJson('/api/wireless-registrations?stale=1')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/wireless-registrations?max_age_minutes=0')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_mac_filter_normalizes_the_query_value(): void
    {
        $device = Device::factory()->create();
        WirelessRegistration::create([
            'device_id' => $device->id, 'mac_address' => 'aa:bb:cc:dd:ee:01',
            'first_seen_at' => now(), 'last_seen_at' => now(),
        ]);

        $this->getJson('/api/wireless-registrations?mac=AA:BB:CC:DD:EE:01')
            ->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_q_matches_mac_interface_or_device_name(): void
    {
        $device = Device::factory()->create(['name' => 'TOWER-9']);
        WirelessRegistration::create([
            'device_id' => $device->id, 'interface' => 'wlan1', 'mac_address' => 'aa:bb:cc:dd:ee:01',
            'first_seen_at' => now(), 'last_seen_at' => now(),
        ]);

        $this->getJson('/api/wireless-registrations?q=tower-9')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/wireless-registrations?q=wlan1')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/wireless-registrations?q=ee:01')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/wireless-registrations?q=nope')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_array_params_are_rejected_rather_than_500ing(): void
    {
        $this->getJson('/api/wireless-registrations?mac[]=x')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['mac']);

        $this->getJson('/api/wireless-registrations?since[]=x')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['since']);
    }

    public function test_invalid_since_is_rejected(): void
    {
        $this->getJson('/api/wireless-registrations?since=not-a-date')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['since']);
    }

    public function test_since_accepts_epoch_seconds_and_milliseconds(): void
    {
        $device = Device::factory()->create();

        WirelessRegistration::create([
            'device_id' => $device->id, 'mac_address' => 'aa:bb:cc:dd:ee:01',
            'first_seen_at' => now(), 'last_seen_at' => now(),
        ]);

        $this->getJson('/api/wireless-registrations?since='.now()->subMinutes(5)->getTimestamp())
            ->assertOk()->assertJsonCount(1, 'data');

        $this->getJson('/api/wireless-registrations?since='.now()->subMinutes(5)->getTimestampMs())
            ->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_requires_authentication(): void
    {
        $this->app['auth']->forgetGuards();

        $this->getJson('/api/wireless-registrations')->assertUnauthorized();
    }
}
