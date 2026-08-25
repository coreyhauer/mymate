<?php

namespace App\Actions\Polling;

use App\Models\Credential;
use App\Services\RouterOs\RouterOsClient;
use App\Services\RouterOs\RouterOsTarget;
use App\Support\EngineLog;
use App\Support\RouterOsDuration;
use Illuminate\Support\Facades\DB;

/**
 * Read a MikroTik's OSPF state over the RouterOS API (GitHub #11) - the full-neighbour count
 * and the per-interface cost (metric) - since RouterOS exposes none of this over SNMP. One
 * connection serves both. Best-effort: any failure yields nulls / an empty cost map.
 *
 * It ALSO persists the per-neighbour detail into `ospf_neighbors` when given a device id. That
 * detail was previously read and thrown away on every poll: the count said "4" while nothing
 * recorded which four, over which interface, in which state, or which one had just dropped -
 * and since RouterOS publishes no OSPF-MIB over SNMP, that reply is the only place the
 * information exists. Persisting it costs no extra device round trips.
 *
 * The return value is UNCHANGED (`neighbors` + `costs`) and the count is computed exactly as
 * before, so every existing caller behaves identically whether persistence is on or off.
 */
class ReadOspf
{
    public function __construct(private RouterOsClient $client) {}

    /**
     * @param  int|null  $deviceId  when given (and mymate.ospf.persist is on), the per-neighbour
     *                              rows are reconciled into `ospf_neighbors` for this device
     * @return array{neighbors: ?int, costs: array<string, int>}
     */
    public function __invoke(string $host, Credential $cred, ?int $deviceId = null): array
    {
        $none = ['neighbors' => null, 'costs' => []];
        if ($cred->type !== 'routeros' || ! $cred->username) {
            return $none;
        }

        $port = $cred->api_port ?: 8728;
        try {
            $conn = $this->client->open(new RouterOsTarget(
                host: $host,
                port: $port,
                username: (string) $cred->username,
                password: (string) $cred->password,
                timeout: max(1, (int) config('mymate.routeros.timeout', 3)),
                ssl: $port === 8729,
            ));
            try {
                $neighborRows = $conn->query('/routing/ospf/neighbor/print');
                $interfaceRows = $conn->query('/routing/ospf/interface/print');
            } finally {
                $conn->close();
            }

            $neighbors = self::countFull($neighborRows);
            $costs = self::costsByInterface($interfaceRows);
        } catch (\Throwable) {
            // A failed read must leave any previously-recorded adjacencies alone - "I could not
            // ask" must never be recorded as "there are no neighbours".
            return $none;
        }

        if ($deviceId !== null && config('mymate.ospf.persist', true)) {
            try {
                $this->persist($deviceId, $neighborRows, $interfaceRows !== []);
            } catch (\Throwable $e) {
                // Persistence is a side-effect of the metrics poll. It must never cost the
                // caller its reading, so a DB problem is logged and swallowed.
                EngineLog::warning('ospf: neighbour persist failed', [
                    'device_id' => $deviceId,
                    'neighbors' => count($neighborRows),
                    'exception' => $e::class,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return ['neighbors' => $neighbors, 'costs' => $costs];
    }

    /**
     * Reconcile this device's adjacencies: upsert what the read returned, delete what it did
     * not, in one transaction, stamped at commit time.
     *
     * ON THE EMPTY READ. PPPoE treats "zero rows" as untrustworthy and keeps what it has,
     * because a concentrator with no sessions is implausible. OSPF is the opposite: zero
     * adjacencies is a REAL and urgent state - it is the thing an operator most wants to see -
     * so refusing to record it would be the bug. But an empty reply is also what a device that
     * simply does not run OSPF returns, and what a credential that has lost its `policy=read`
     * would produce if the client ever swallowed the trap.
     *
     * The OSPF interface list, already fetched for the cost map, separates those cases at no
     * extra cost: if the device reports OSPF interfaces, OSPF is configured and readable and an
     * empty neighbour list genuinely means every adjacency is down - record it. If it reports
     * neither interfaces nor neighbours, there is nothing to distinguish "no OSPF here" from
     * "cannot read OSPF", so previously-known rows are left alone (they will age out through
     * the staleness filter and the reaper) and the transition is logged.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @param  bool  $ospfConfigured  the device reported at least one OSPF interface
     */
    private function persist(int $deviceId, array $rows, bool $ospfConfigured): void
    {
        DB::transaction(function () use ($deviceId, $rows, $ospfConfigured): void {
            // Whole seconds: last_seen_at is timestamptz(0) by convention here, and a value
            // carrying microseconds would not equal the value the prune compares against -
            // which would delete the rows this poll just wrote.
            $seenAt = now()->startOfSecond();

            if ($rows === [] && ! $ospfConfigured) {
                $existing = DB::table('ospf_neighbors')->where('device_id', $deviceId)->count();
                if ($existing > 0) {
                    EngineLog::warning('ospf: device reports no OSPF at all but has neighbours - keeping them', [
                        'device_id' => $deviceId,
                        'existing_rows' => $existing,
                    ]);
                }

                return;
            }

            $unique = [];
            foreach ($rows as $row) {
                $shaped = self::shape($deviceId, $row, $seenAt);
                // Two rows on the same natural key in one batch would make Postgres reject the
                // whole statement ("ON CONFLICT DO UPDATE command cannot affect row a second
                // time") and sink an otherwise-good device. Last one wins.
                $unique[$shaped['router_id'].'|'.$shaped['neighbor_address']] = $shaped;
            }
            $unique = array_values($unique);

            if ($unique !== []) {
                foreach (array_chunk($unique, 500) as $chunk) {
                    DB::table('ospf_neighbors')->upsert(
                        $chunk,
                        ['device_id', 'router_id', 'neighbor_address'],
                        [
                            'interface', 'state', 'is_full', 'adjacency_seconds', 'state_changes',
                            'instance', 'area', 'dr_id', 'backup_dr_id', 'priority', 'last_seen_at',
                        ],
                    );
                }
            }

            // Anything this device carries that this read did not return is an adjacency that
            // has gone away. Matched on the natural key rather than on "last_seen_at older than
            // this poll": two polls of one device landing inside the same second would leave the
            // previous rows carrying the identical timestamp, and a time-based prune would then
            // delete nothing. An empty read (every adjacency down) deletes them all, which is
            // the whole point - see the docblock above.
            $prune = DB::table('ospf_neighbors')->where('device_id', $deviceId);

            if ($unique !== []) {
                $pairs = array_map(
                    static fn (array $r): array => [$r['router_id'], $r['neighbor_address']],
                    $unique,
                );
                $placeholders = implode(',', array_fill(0, count($pairs), '(?,?)'));
                $prune->whereRaw(
                    "(router_id, neighbor_address) NOT IN ({$placeholders})",
                    array_merge(...$pairs),
                );
            }

            $prune->delete();
        });
    }

    /**
     * Shape one RouterOS neighbour reply into an insert row.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private static function shape(int $deviceId, array $row, \DateTimeInterface $seenAt): array
    {
        $state = self::text($row['state'] ?? null);

        return [
            'device_id' => $deviceId,
            // '' rather than null - these form the unique key, and a NULL never conflicts.
            'router_id' => self::text($row['router-id'] ?? null) ?? '',
            'neighbor_address' => self::text($row['address'] ?? null) ?? '',
            'interface' => self::text($row['interface'] ?? null),
            'state' => $state,
            // Same test the count uses, so is_full and devices.ospf_neighbors can never
            // disagree about what "up" means.
            'is_full' => $state !== null && str_contains(strtolower($state), 'full'),
            'adjacency_seconds' => RouterOsDuration::toSeconds($row['adjacency'] ?? null),
            'state_changes' => self::int($row['state-changes'] ?? null),
            'instance' => self::text($row['instance'] ?? null),
            'area' => self::text($row['area'] ?? null),
            'dr_id' => self::text($row['dr-id'] ?? null),
            'backup_dr_id' => self::text($row['backup-dr-id'] ?? null),
            'priority' => self::int($row['priority'] ?? null),
            'last_seen_at' => $seenAt,
        ];
    }

    /**
     * Count neighbours in the "Full" state (a fully-formed adjacency). RouterOS 6 and 7 both
     * label it "Full".
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    public static function countFull(array $rows): int
    {
        $full = 0;
        foreach ($rows as $row) {
            if (str_contains(strtolower((string) ($row['state'] ?? '')), 'full')) {
                $full++;
            }
        }

        return $full;
    }

    /**
     * Map each OSPF interface name to its cost (outbound metric). An interface can appear more
     * than once (multiple areas/instances); the last value wins - they're normally identical.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, int>
     */
    public static function costsByInterface(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $name = (string) ($row['interface'] ?? '');
            if ($name !== '' && isset($row['cost']) && is_numeric($row['cost'])) {
                $out[$name] = (int) $row['cost'];
            }
        }

        return $out;
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

    /** A RouterOS numeric field, or null when absent/unparseable. */
    private static function int(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
