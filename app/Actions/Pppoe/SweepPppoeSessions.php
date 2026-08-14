<?php

namespace App\Actions\Pppoe;

use App\Models\Device;
use App\Services\RouterOs\RouterOsClient;
use App\Services\RouterOs\RouterOsTarget;
use App\Support\EngineLog;
use App\Support\RouterOsDuration;
use Illuminate\Support\Facades\DB;

/**
 * Sweep the ACTIVE PPPoE sessions off a *set* of concentrators (one shard) and materialise
 * them into `pppoe_sessions` - the scale-out unit, mirroring App\Actions\Polling\PollInterfaces:
 *  - one device's failure is caught, logged and skipped; it never sinks the shard,
 *  - a device's rows are reconciled (upsert what is live, delete what is gone) inside ONE
 *    transaction, so a reader never sees a half-written device and a failed read never
 *    destroys the previous (stale but honest) answer. See persist() for why this is an
 *    upsert rather than the delete-then-insert it started as.
 *
 * Credential resolution is NOT re-implemented here: RouterOsTarget::fromDevice is "the one
 * place this resolution lives" and it reads `$device->credential` - the generic `credential_id`
 * FK, which every PPPoE concentrator has populated (thousands of them point at the same shared
 * RouterOS credentials row). `routeros_credential_id` is a *separate, optional* slot for
 * SNMP-polled MikroTiks that still need API-only reads (OSPF) and is NULL on the whole PPPoE
 * fleet - so reading it here would find nothing. The only thing overridden below is the
 * connect/read timeout, which gets its own knob because a PPPoE sweep is a bigger read than a
 * throughput tick.
 *
 * Returns the number of devices that produced a persisted result.
 */
class SweepPppoeSessions
{
    public function __construct(private RouterOsClient $client) {}

    /** @param  list<int>  $deviceIds */
    public function __invoke(array $deviceIds): int
    {
        if ($deviceIds === []) {
            return 0;
        }

        $startedAt = microtime(true);

        $devices = Device::with('credential')->whereIn('id', $deviceIds)->get();

        $swept = 0;
        $failed = 0;
        $sessions = 0;

        foreach ($devices as $device) {
            try {
                $rows = $this->readSessions($device);
            } catch (\Throwable $e) {
                // One unreachable/erroring concentrator must not take the shard down. Its
                // previous rows are left in place (stale, with an older swept_at) rather than
                // deleted - "unreachable" must never read as "nobody is online".
                // (RouterOS exceptions carry host + transport error only, never credentials.)
                $failed++;
                EngineLog::warning('pppoe: device sweep failed', [
                    'device_id' => $device->id,
                    'device' => $device->name,
                    'ip' => $device->mgmt_ip,
                    'exception' => $e::class,
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            try {
                $this->persist((int) $device->id, $rows);
            } catch (\Throwable $e) {
                $failed++;
                EngineLog::warning('pppoe: device persist failed', [
                    'device_id' => $device->id,
                    'device' => $device->name,
                    'sessions' => count($rows),
                    'exception' => $e::class,
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            $swept++;
            $sessions += count($rows);
        }

        // Heartbeat (debug): one line per shard so cadence, fan-out and failure rate are
        // visible without instrumenting anything else.
        EngineLog::debug('pppoe: sweep batch complete', [
            'devices' => $devices->count(),
            'swept' => $swept,
            'failed' => $failed,
            'sessions' => $sessions,
            'ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ]);

        return $swept;
    }

    /**
     * Read one concentrator's `/ppp/active` table and shape it into insert rows. Opens and
     * closes its own connection; any failure throws (the caller isolates it).
     *
     * @return list<array<string, mixed>>
     */
    private function readSessions(Device $device): array
    {
        $conn = $this->client->open($this->target($device));

        try {
            $replies = $conn->query('/ppp/active/print');
        } finally {
            $conn->close();
        }

        $cap = max(1, (int) config('mymate.pppoe.max_sessions_per_device', 4000));

        // Deliberately NOT filtered on `service = pppoe`. These devices are PPPoE
        // concentrators, and any active ppp session on one still answers the question the
        // table exists for ("which concentrator is this subscriber terminated on, since
        // when"). Filtering on a field RouterOS doesn't always populate would silently drop
        // real customers, which is a far worse failure than including a stray l2tp row.
        $rows = [];
        foreach ($replies as $reply) {
            $username = trim((string) ($reply['name'] ?? ''));
            if ($username === '') {
                // A session with no username can't be joined to a customer - it's noise.
                continue;
            }

            if (count($rows) >= $cap) {
                // Loud, never silent: a concentrator reporting past the cap is either a real
                // scale change or a bug, and both deserve a log line rather than a quiet truncation.
                EngineLog::warning('pppoe: session cap hit, truncating device read', [
                    'device_id' => $device->id,
                    'device' => $device->name,
                    'cap' => $cap,
                    'reported' => count($replies),
                ]);
                break;
            }

            $rows[] = [
                'device_id' => (int) $device->id,
                'username' => $username,
                'remote_address' => self::text($reply['address'] ?? null),
                // Normalised to '' rather than null: it is part of the natural key the upsert
                // conflicts on, and Postgres treats NULLs as distinct in a unique index, which
                // would let the same session insert a fresh duplicate row on every sweep.
                // PppoeSessionResource maps '' back to null, so the API shape is unchanged.
                'caller_id' => self::text($reply['caller-id'] ?? null) ?? '',
                'uptime_seconds' => RouterOsDuration::toSeconds($reply['uptime'] ?? null),
            ];
        }

        return $rows;
    }

    /**
     * Reconcile this device's rows in ONE transaction: UPSERT everything the read returned,
     * then delete only what the read did NOT return. Called only after a fully successful read,
     * so partial rows for a device are structurally impossible.
     *
     * This used to be DELETE-then-INSERT, which had three problems:
     *
     *  1. The DELETE ran before the empty-result guard. A read that succeeded at the transport
     *     level but yielded zero usable rows - the API user losing its `ppp` policy, or every
     *     row arriving with an empty name and being skipped - wiped a healthy concentrator's
     *     sessions and reported success. The guard is now the first thing in the transaction,
     *     and a device that had rows and now reports none is logged rather than silently
     *     emptied (the sweep is left alone to re-confirm on the next tick).
     *  2. Re-inserting every row re-minted its autoincrement id, so the read API's id-cursor
     *     pagination pointed into ids that no longer existed: a client walking pages silently
     *     lost every concentrator swept mid-walk. Upserting on the natural key keeps a
     *     continuing session's id stable for as long as the session lives.
     *  3. It churned ~13k dead tuples every 5 minutes (~7.5M/day) for a table whose contents
     *     barely change between sweeps, which is pure vacuum load. Upsert only writes rows
     *     that actually moved.
     *
     * The natural key is (device_id, username, caller_id) - one subscriber's session on one
     * concentrator. caller_id is normalised to '' at read time because a NULL never conflicts
     * in a Postgres unique index.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function persist(int $deviceId, array $rows): void
    {
        DB::transaction(function () use ($deviceId, $rows): void {
            // Whole seconds: swept_at is timestamp(0), so a value carrying microseconds would
            // be rounded on the way into the column and no longer equal the value we prune
            // against below - which would delete the rows this very sweep just wrote.
            $sweptAt = now()->startOfSecond();

            if ($rows === []) {
                // GUARD BEFORE ANY WRITE. "I could not see any sessions" must never be
                // recorded as "there are no sessions" - an unreachable-shaped failure that
                // still returns cleanly would otherwise erase a live concentrator.
                $existing = DB::table('pppoe_sessions')->where('device_id', $deviceId)->count();
                if ($existing > 0) {
                    EngineLog::warning('pppoe: device returned no sessions but has rows - keeping them', [
                        'device_id' => $deviceId,
                        'existing_rows' => $existing,
                    ]);
                }

                return;
            }

            // Two rows with the same natural key in one batch would make Postgres reject the
            // whole statement ("ON CONFLICT DO UPDATE command cannot affect row a second
            // time"), which would sink an otherwise-good device. Last one wins.
            $unique = [];
            foreach ($rows as $row) {
                $unique[$row['device_id'].'|'.$row['username'].'|'.$row['caller_id']] = $row + ['swept_at' => $sweptAt];
            }
            $unique = array_values($unique);

            // Chunked so a pathologically large concentrator can't exceed Postgres's
            // bound-parameter ceiling (65535 / 6 columns); a normal device is one statement.
            foreach (array_chunk($unique, 1000) as $chunk) {
                DB::table('pppoe_sessions')->upsert(
                    $chunk,
                    ['device_id', 'username', 'caller_id'],
                    ['remote_address', 'uptime_seconds', 'swept_at'],
                );
            }

            // Everything this device carries that the read did NOT return is a session that
            // has gone away. Every row we just wrote carries exactly $sweptAt, so a strict
            // less-than is precisely "not in this read".
            DB::table('pppoe_sessions')
                ->where('device_id', $deviceId)
                ->where('swept_at', '<', $sweptAt)
                ->delete();
        });
    }

    /**
     * The device's RouterOS target, with the PPPoE-specific timeout substituted. Everything
     * else (host/port/TLS/credential) comes from the single resolution site.
     */
    private function target(Device $device): RouterOsTarget
    {
        $base = RouterOsTarget::fromDevice($device);
        $timeout = max(1, (int) config('mymate.pppoe.timeout', 5));

        if ($timeout === $base->timeout) {
            return $base;
        }

        return new RouterOsTarget(
            host: $base->host,
            port: $base->port,
            username: $base->username,
            password: $base->password,
            timeout: $timeout,
            ssl: $base->ssl,
        );
    }

    /** Trim a RouterOS field, collapsing empty/absent to null. */
    private static function text(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
