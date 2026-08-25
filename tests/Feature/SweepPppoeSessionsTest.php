<?php

namespace Tests\Feature;

use App\Actions\Pppoe\SweepPppoeSessions;
use App\Models\Credential;
use App\Models\Device;
use App\Models\PppoeSession;
use App\Services\RouterOs\RouterOsClient;
use App\Services\RouterOs\RouterOsConnection;
use App\Services\RouterOs\RouterOsClientException;
use App\Services\RouterOs\RouterOsTarget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The write path, pinned against the two ways it previously destroyed data:
 * a successful-but-empty read wiping a healthy concentrator, and delete-then-insert
 * re-minting every id on every sweep (which broke the read API's id cursor).
 */
class SweepPppoeSessionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_session_keeps_its_id_across_sweeps(): void
    {
        $device = $this->concentrator();

        $this->sweepWith($device, [
            ['name' => 'jsmith1', 'address' => '10.50.1.20', 'caller-id' => 'AA:BB:CC:DD:EE:FF', 'uptime' => '1h'],
        ]);

        $first = PppoeSession::where('username', 'jsmith1')->firstOrFail();

        // Same session, later sweep, longer uptime.
        $this->sweepWith($device, [
            ['name' => 'jsmith1', 'address' => '10.50.1.20', 'caller-id' => 'AA:BB:CC:DD:EE:FF', 'uptime' => '2h'],
        ]);

        $second = PppoeSession::where('username', 'jsmith1')->firstOrFail();

        // The whole point of upserting: an id-cursor client walking pages must not have the
        // rows renumbered underneath it mid-walk.
        $this->assertSame($first->id, $second->id, 'a continuing session must keep its id');
        $this->assertSame(7200, $second->uptime_seconds, 'the row must still be updated in place');
        $this->assertSame(1, PppoeSession::count());
    }

    public function test_an_empty_read_does_not_wipe_a_healthy_concentrator(): void
    {
        $device = $this->concentrator();

        $this->sweepWith($device, [
            ['name' => 'jsmith1', 'address' => '10.50.1.20', 'uptime' => '1h'],
            ['name' => 'bjones2', 'address' => '10.50.1.21', 'uptime' => '3h'],
        ]);
        $this->assertSame(2, PppoeSession::count());

        // A read that succeeds at the transport level but yields nothing usable - the API user
        // losing its ppp policy, or every row arriving with an empty name. This used to DELETE
        // first and report success, erasing a live concentrator.
        $this->sweepWith($device, []);

        $this->assertSame(2, PppoeSession::count(), 'an empty read must never delete existing sessions');
    }

    public function test_rows_the_read_no_longer_returns_are_removed(): void
    {
        $device = $this->concentrator();

        $this->sweepWith($device, [
            ['name' => 'stays', 'address' => '10.50.1.20', 'uptime' => '1h'],
            ['name' => 'goes', 'address' => '10.50.1.21', 'uptime' => '1h'],
        ]);
        $this->assertSame(2, PppoeSession::count());

        // A non-empty read IS trustworthy, so a session missing from it has genuinely
        // disconnected and must disappear.
        $this->sweepWith($device, [
            ['name' => 'stays', 'address' => '10.50.1.20', 'uptime' => '2h'],
        ]);

        $this->assertSame(['stays'], PppoeSession::pluck('username')->all());
    }

    public function test_a_failed_read_leaves_the_previous_answer_alone(): void
    {
        $device = $this->concentrator();

        $this->sweepWith($device, [['name' => 'jsmith1', 'address' => '10.50.1.20', 'uptime' => '1h']]);
        $this->assertSame(1, PppoeSession::count());

        // Unreachable must never read as "nobody is online".
        $this->swap(RouterOsClient::class, new class implements RouterOsClient
        {
            public function open(RouterOsTarget $target): RouterOsConnection
            {
                throw new RouterOsClientException('connect timed out');
            }
        });
        app(SweepPppoeSessions::class)([$device->id]);

        $this->assertSame(1, PppoeSession::count());
    }

    public function test_duplicate_rows_in_one_reply_do_not_sink_the_device(): void
    {
        $device = $this->concentrator();

        // Two replies on the same natural key in one batch would make Postgres reject the
        // whole upsert ("cannot affect row a second time") and lose the device entirely.
        $this->sweepWith($device, [
            ['name' => 'dup', 'address' => '10.50.1.20', 'caller-id' => 'AA:BB', 'uptime' => '1h'],
            ['name' => 'dup', 'address' => '10.50.1.99', 'caller-id' => 'AA:BB', 'uptime' => '9h'],
        ]);

        $this->assertSame(1, PppoeSession::count());
        // Last one wins.
        $this->assertSame('10.50.1.99', PppoeSession::firstOrFail()->remote_address);
    }

    /** A monitored, centrally-polled RouterOS device with a usable credential. */
    private function concentrator(): Device
    {
        $credential = Credential::factory()->create(['type' => 'routeros', 'username' => 'poller']);

        return Device::factory()->create([
            'name' => 'CONC-pppoe-1',
            'monitored' => true,
            'agent_id' => null,
            'poll_method' => 'routeros',
            'credential_id' => $credential->id,
            'mgmt_ip' => '10.0.0.1',
        ]);
    }

    /**
     * Run one sweep of $device against a fake RouterOS that answers /ppp/active with $replies.
     *
     * @param  list<array<string, mixed>>  $replies
     */
    private function sweepWith(Device $device, array $replies): void
    {
        $this->swap(RouterOsClient::class, new class($replies) implements RouterOsClient
        {
            /** @param  list<array<string, mixed>>  $replies */
            public function __construct(private array $replies) {}

            public function open(RouterOsTarget $target): RouterOsConnection
            {
                return new class($this->replies) implements RouterOsConnection
                {
                    /** @param  list<array<string, mixed>>  $replies */
                    public function __construct(private array $replies) {}

                    public function query(string $command, array $params = []): array
                    {
                        return $command === '/ppp/active/print' ? $this->replies : [];
                    }

                    public function close(): void {}
                };
            }
        });

        app(SweepPppoeSessions::class)([$device->id]);
    }
}
