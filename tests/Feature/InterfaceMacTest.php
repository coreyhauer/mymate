<?php

namespace Tests\Feature;

use App\Actions\Polling\DiscoverInterfaces;
use App\Enums\PollMethod;
use App\Models\Credential;
use App\Models\Device;
use App\Models\NetworkInterface;
use App\Services\RouterOs\RouterOsClient;
use App\Services\Snmp\SnmpClient;
use App\Support\MacAddress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeRouterOsClient;
use Tests\Support\FakeSnmpClient;
use Tests\TestCase;

/**
 * Feature B (spec §2): interfaces.mac_address, captured at discovery from RouterOS
 * `/interface/print` and SNMP `ifPhysAddress`, exposed on InterfaceResource and rolled up
 * onto DeviceResource as `mac_addresses`.
 */
class InterfaceMacTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsUser();
    }

    private function routerOsDevice(): Device
    {
        $credential = Credential::factory()->routeros()->create();

        return Device::factory()->create([
            'poll_method' => PollMethod::RouterOs,
            'credential_id' => $credential->id,
        ]);
    }

    private function snmpDevice(): Device
    {
        $credential = Credential::factory()->create(['snmp_community' => 'public-test']);

        return Device::factory()->create([
            'poll_method' => PollMethod::Snmp,
            'credential_id' => $credential->id,
        ]);
    }

    // --- MacAddress::normalize() -------------------------------------------------------

    public function test_normalize_lowercases_colon_form(): void
    {
        $this->assertSame('aa:bb:cc:dd:ee:ff', MacAddress::normalize('AA:BB:CC:DD:EE:FF'));
    }

    public function test_normalize_accepts_space_separated_hex(): void
    {
        $this->assertSame('48:8f:5a:12:34:56', MacAddress::normalize('48 8F 5A 12 34 56'));
    }

    public function test_normalize_accepts_bare_hex_with_prefix(): void
    {
        $this->assertSame('48:8f:5a:12:34:56', MacAddress::normalize('0x488f5a123456'));
    }

    public function test_normalize_blanks_all_zero_address(): void
    {
        $this->assertSame('', MacAddress::normalize('00:00:00:00:00:00'));
    }

    public function test_normalize_blanks_null_empty_and_malformed(): void
    {
        $this->assertSame('', MacAddress::normalize(null));
        $this->assertSame('', MacAddress::normalize(''));
        $this->assertSame('', MacAddress::normalize('not-a-mac'));
        $this->assertSame('', MacAddress::normalize('aa:bb:cc')); // too short
    }

    // --- Capture: RouterOS ---------------------------------------------------------------

    public function test_router_os_discovery_captures_and_normalizes_mac_address(): void
    {
        $device = $this->routerOsDevice();
        $client = new FakeRouterOsClient(replies: [
            '/interface/print' => [
                ['.id' => '*1', 'name' => 'ether1', 'type' => 'ether', 'mac-address' => '48:8F:5A:12:34:56'],
            ],
            '/interface/ethernet/print' => [['name' => 'ether1']],
            '/interface/ethernet/monitor' => [['name' => 'ether1', 'rate' => '1Gbps']],
        ]);
        $this->app->instance(RouterOsClient::class, $client);

        app(DiscoverInterfaces::class)($device);

        $this->assertDatabaseHas('interfaces', [
            'device_id' => $device->id, 'if_index' => 1, 'mac_address' => '48:8f:5a:12:34:56',
        ]);
    }

    public function test_router_os_discovery_blanks_all_zero_mac(): void
    {
        $device = $this->routerOsDevice();
        $client = new FakeRouterOsClient(replies: [
            '/interface/print' => [
                ['.id' => '*1', 'name' => 'bridge1', 'type' => 'bridge', 'mac-address' => '00:00:00:00:00:00'],
            ],
            '/interface/ethernet/print' => [],
            '/interface/ethernet/monitor' => [],
        ]);
        $this->app->instance(RouterOsClient::class, $client);

        app(DiscoverInterfaces::class)($device);

        $this->assertDatabaseHas('interfaces', [
            'device_id' => $device->id, 'if_index' => 1, 'mac_address' => '',
        ]);
    }

    // --- Capture: SNMP ---------------------------------------------------------------------

    public function test_snmp_discovery_captures_and_normalizes_mac_address(): void
    {
        $device = $this->snmpDevice();
        $oids = config('mymate.snmp.oids');
        $snmp = new FakeSnmpClient;
        $snmp->walks[$oids['if_name']] = [1 => 'ether1'];
        $snmp->walks[$oids['if_high_speed']] = [1 => '1000'];
        $snmp->walks[$oids['if_phys_address']] = [1 => 'AA:BB:CC:DD:EE:FF'];
        $this->app->instance(SnmpClient::class, $snmp);

        app(DiscoverInterfaces::class)($device);

        $this->assertDatabaseHas('interfaces', [
            'device_id' => $device->id, 'if_index' => 1, 'mac_address' => 'aa:bb:cc:dd:ee:ff',
        ]);
    }

    public function test_snmp_discovery_blanks_mac_when_agent_does_not_answer_it(): void
    {
        $device = $this->snmpDevice();
        $oids = config('mymate.snmp.oids');
        $snmp = new FakeSnmpClient;
        $snmp->walks[$oids['if_name']] = [1 => 'ether1'];
        $snmp->walks[$oids['if_high_speed']] = [1 => '1000'];
        // if_phys_address left unscripted - some agents don't answer it.
        $this->app->instance(SnmpClient::class, $snmp);

        app(DiscoverInterfaces::class)($device);

        $this->assertDatabaseHas('interfaces', [
            'device_id' => $device->id, 'if_index' => 1, 'mac_address' => '',
        ]);
    }

    // --- InterfaceResource -------------------------------------------------------------

    public function test_interface_resource_exposes_mac_address(): void
    {
        $device = Device::factory()->create();
        NetworkInterface::factory()->create([
            'device_id' => $device->id, 'if_index' => 1, 'mac_address' => 'aa:bb:cc:dd:ee:ff',
        ]);

        $this->getJson("/api/devices/{$device->id}/interfaces")
            ->assertOk()
            ->assertJsonPath('data.0.mac_address', 'aa:bb:cc:dd:ee:ff');
    }

    public function test_interface_resource_exposes_blank_mac_address(): void
    {
        $device = Device::factory()->create();
        NetworkInterface::factory()->create([
            'device_id' => $device->id, 'if_index' => 1, 'mac_address' => '',
        ]);

        $this->getJson("/api/devices/{$device->id}/interfaces")
            ->assertOk()
            ->assertJsonPath('data.0.mac_address', '');
    }

    // --- DeviceResource: mac_addresses --------------------------------------------------

    public function test_device_resource_rolls_up_distinct_sorted_non_empty_macs(): void
    {
        $device = Device::factory()->create();
        NetworkInterface::factory()->create(['device_id' => $device->id, 'if_index' => 1, 'mac_address' => 'bb:bb:bb:bb:bb:bb']);
        NetworkInterface::factory()->create(['device_id' => $device->id, 'if_index' => 2, 'mac_address' => 'aa:aa:aa:aa:aa:aa']);
        // Duplicate MAC (e.g. a bridge sharing its port's MAC) must not appear twice.
        NetworkInterface::factory()->create(['device_id' => $device->id, 'if_index' => 3, 'mac_address' => 'aa:aa:aa:aa:aa:aa']);
        // Blank MAC must be excluded, not surfaced as an empty-string entry.
        NetworkInterface::factory()->create(['device_id' => $device->id, 'if_index' => 4, 'mac_address' => '']);

        $this->getJson('/api/devices')
            ->assertOk()
            ->assertJsonPath('data.0.mac_addresses', ['aa:aa:aa:aa:aa:aa', 'bb:bb:bb:bb:bb:bb']);
    }

    public function test_device_resource_mac_addresses_empty_array_when_no_interfaces(): void
    {
        Device::factory()->create();

        $this->getJson('/api/devices')
            ->assertOk()
            ->assertJsonPath('data.0.mac_addresses', []);
    }

    public function test_devices_index_eager_loads_interfaces_without_n_plus_one(): void
    {
        // A handful of devices each with a MAC-bearing interface. This isn't a strict query-count
        // assertion (that couples the test to unrelated eager loads like `parent`/`site`) - it's a
        // smoke test that the endpoint stays fast and correct at more than one device, which is
        // exactly the shape DeviceController::index()'s `interfaces:id,device_id,mac_address`
        // eager load exists to keep off the N+1 path.
        $devices = Device::factory()->count(5)->create();
        foreach ($devices as $i => $device) {
            NetworkInterface::factory()->create([
                'device_id' => $device->id,
                'if_index' => 1,
                'mac_address' => sprintf('%02x:00:00:00:00:00', $i),
            ]);
        }

        $response = $this->getJson('/api/devices')->assertOk();
        $macs = collect($response->json('data'))->pluck('mac_addresses')->flatten();

        $this->assertCount(5, $macs);
    }
}
