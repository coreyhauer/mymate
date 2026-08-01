<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\RfLinkState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RfLinkStateApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsUser();
    }

    public function test_lists_all_rows_with_full_serialization(): void
    {
        $device = Device::factory()->create();

        RfLinkState::create([
            'device_id' => $device->id,
            'sensor_index' => '5',
            'rssi_dbm' => -58.5,
            'rssi_sensor_type' => 'airos-rx',
            'noise_floor_dbm' => -95.0,
            'snr_db' => 36.5,
            'snr_source' => 'native',
            'rate_mbps' => 866.7,
            'channel_util_pct' => 12.3,
            'tx_power_dbm' => 24.0,
            'distance_mi' => 3.2,
            'freq_mhz' => 5800.0,
            'if_errors_in' => 4,
            'if_errors_out' => 1,
            'source_lastupdate' => now()->subMinutes(2),
            'synced_at' => now(),
        ]);

        $row = $this->getJson('/api/rf-link-state')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->json('data.0');

        $this->assertSame($device->id, $row['device_id']);
        $this->assertSame('5', $row['sensor_index']);
        $this->assertSame(-58.5, $row['rssi_dbm']);
        $this->assertSame('airos-rx', $row['rssi_sensor_type']);
        $this->assertSame(-95.0, $row['noise_floor_dbm']);
        $this->assertSame(36.5, $row['snr_db']);
        $this->assertSame('native', $row['snr_source']);
        $this->assertSame(866.7, $row['rate_mbps']);
        $this->assertSame(12.3, $row['channel_util_pct']);
        $this->assertSame(24.0, $row['tx_power_dbm']);
        $this->assertSame(3.2, $row['distance_mi']);
        $this->assertSame(5800.0, $row['freq_mhz']);
        $this->assertSame(4, $row['if_errors_in']);
        $this->assertSame(1, $row['if_errors_out']);
        $this->assertNotNull($row['source_lastupdate']);
        $this->assertNotNull($row['synced_at']);
        $this->assertArrayNotHasKey('baseline_rssi_dbm', $row);
        $this->assertArrayNotHasKey('deviation_db', $row);
    }

    public function test_since_filters_out_rows_synced_before_it(): void
    {
        $old = Device::factory()->create();
        $new = Device::factory()->create();

        RfLinkState::create(['device_id' => $old->id, 'synced_at' => now()->subHours(2)]);
        RfLinkState::create(['device_id' => $new->id, 'synced_at' => now()]);

        $this->getJson('/api/rf-link-state?since='.now()->subHour()->toIso8601String())
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.device_id', $new->id);
    }

    public function test_filters_by_device_id(): void
    {
        $a = Device::factory()->create();
        $b = Device::factory()->create();

        RfLinkState::create(['device_id' => $a->id]);
        RfLinkState::create(['device_id' => $b->id]);

        $this->getJson("/api/rf-link-state?device_id={$a->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.device_id', $a->id);
    }

    public function test_invalid_since_is_rejected(): void
    {
        $this->getJson('/api/rf-link-state?since=not-a-date')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['since']);
    }

    public function test_requires_authentication(): void
    {
        $this->app['auth']->forgetGuards();

        $this->getJson('/api/rf-link-state')->assertUnauthorized();
    }
}
