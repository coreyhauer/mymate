<?php

namespace Tests\Feature;

use App\Enums\PollMethod;
use App\Models\Device;
use App\Services\Polling\ConnectBackoff;
use App\Services\RouterOs\RouterOsClientException;
use App\Services\Snmp\SnmpClientException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The per-device connect-failure circuit breaker. Deliberately independent of
 * `fail_streak` (the ICMP ping sweep) - these are `poll_fail_streak` / `poll_backoff_until`,
 * driven only by transport failures on the RouterOS/SNMP poll path.
 */
class ConnectBackoffTest extends TestCase
{
    use RefreshDatabase;

    private function device(): Device
    {
        return Device::factory()->create(['poll_method' => PollMethod::RouterOs]);
    }

    public function test_a_transport_failure_sets_streak_and_the_first_backoff_window(): void
    {
        Carbon::setTestNow('2026-07-30 12:00:00');
        $device = $this->device();

        (new ConnectBackoff)->recordFailure($device, new RouterOsClientException('connect timeout', transport: true));

        $device->refresh();
        $this->assertSame(1, $device->poll_fail_streak);
        // Default schedule_minutes = [1, 2, 5, 10, 30] - streak 1 -> 1 minute.
        $this->assertTrue($device->poll_backoff_until->equalTo(Carbon::parse('2026-07-30 12:01:00')));

        Carbon::setTestNow();
    }

    public function test_consecutive_transport_failures_follow_the_configured_schedule_and_cap(): void
    {
        Carbon::setTestNow('2026-07-30 12:00:00');
        config(['mymate.poll.connect_backoff.schedule_minutes' => [1, 2, 5]]);
        $device = $this->device();
        $backoff = new ConnectBackoff;
        $e = new RouterOsClientException('connect timeout', transport: true);

        $backoff->recordFailure($device, $e); // streak 1 -> 1 min
        $device->refresh();
        $this->assertSame(1, $device->poll_fail_streak);
        $this->assertTrue($device->poll_backoff_until->equalTo(now()->addMinutes(1)));

        $backoff->recordFailure($device, $e); // streak 2 -> 2 min
        $device->refresh();
        $this->assertSame(2, $device->poll_fail_streak);
        $this->assertTrue($device->poll_backoff_until->equalTo(now()->addMinutes(2)));

        $backoff->recordFailure($device, $e); // streak 3 -> 5 min (last schedule entry)
        $device->refresh();
        $this->assertSame(3, $device->poll_fail_streak);
        $this->assertTrue($device->poll_backoff_until->equalTo(now()->addMinutes(5)));

        $backoff->recordFailure($device, $e); // streak 4, beyond the schedule -> caps at 5 min
        $device->refresh();
        $this->assertSame(4, $device->poll_fail_streak);
        $this->assertTrue($device->poll_backoff_until->equalTo(now()->addMinutes(5)));

        Carbon::setTestNow();
    }

    public function test_an_auth_failure_does_not_set_connect_backoff(): void
    {
        // BadCredentialsException maps to transport:false (EvilFreelancerRouterOsClient) - the
        // device answered and rejected the credential, it's not unreachable.
        $device = $this->device();

        (new ConnectBackoff)->recordFailure($device, new RouterOsClientException('auth failed', transport: false));

        $device->refresh();
        $this->assertSame(0, $device->poll_fail_streak);
        $this->assertNull($device->poll_backoff_until);
    }

    public function test_an_snmp_config_failure_does_not_set_connect_backoff_but_a_real_snmp_transport_failure_does(): void
    {
        $configFailure = $this->device();
        $transportFailure = $this->device();
        $backoff = new ConnectBackoff;

        // "no usable SNMP credential" - thrown before any packet is sent (default transport:false).
        $backoff->recordFailure($configFailure, new SnmpClientException('no usable SNMP credential'));
        $this->assertSame(0, $configFailure->refresh()->poll_fail_streak);
        $this->assertNull($configFailure->poll_backoff_until);

        // A genuine SNMP timeout - PhpSnmpClient marks these transport:true.
        $backoff->recordFailure($transportFailure, new SnmpClientException('SNMP get failed: Timeout', transport: true));
        $this->assertSame(1, $transportFailure->refresh()->poll_fail_streak);
        $this->assertNotNull($transportFailure->poll_backoff_until);
    }

    public function test_a_successful_connect_resets_the_streak_and_clears_backoff(): void
    {
        $device = $this->device();
        $device->forceFill(['poll_fail_streak' => 3, 'poll_backoff_until' => now()->addMinutes(5)])->save();

        (new ConnectBackoff)->recordSuccess($device);

        $device->refresh();
        $this->assertSame(0, $device->poll_fail_streak);
        $this->assertNull($device->poll_backoff_until);
    }

    public function test_recording_success_on_an_already_clean_device_does_not_write(): void
    {
        $device = $this->device();
        $before = $device->updated_at;

        (new ConnectBackoff)->recordSuccess($device);

        $this->assertTrue($device->fresh()->updated_at->equalTo($before));
    }

    public function test_backoff_can_be_disabled_via_config(): void
    {
        config(['mymate.poll.connect_backoff.enabled' => false]);
        $device = $this->device();

        (new ConnectBackoff)->recordFailure($device, new RouterOsClientException('connect timeout', transport: true));

        $device->refresh();
        $this->assertSame(0, $device->poll_fail_streak);
        $this->assertNull($device->poll_backoff_until);
    }
}
