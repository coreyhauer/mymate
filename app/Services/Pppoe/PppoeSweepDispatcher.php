<?php

namespace App\Services\Pppoe;

use App\Enums\PollMethod;
use App\Jobs\SweepPppoeSessionsBatchJob;
use App\Models\Device;
use Illuminate\Support\Facades\DB;

/**
 * Selects the PPPoE concentrator fleet, shards it by `crc32(device_id) % shards` (the house
 * pattern - see App\Services\Polling\PollDispatcher) and dispatches one batch job per non-empty
 * shard, **staggered** across the sweep window.
 *
 * Why stagger at all, when nothing else here does: throughput polling talks to a device for
 * ~milliseconds, but a PPPoE sweep opens an API session and reads a table of tens-to-hundreds
 * of rows on ~2,300 routers. Firing every shard at t=0 would be a thundering herd against the
 * concentrator fleet AND a 2,300-device burst of RouterOS logins in a few seconds. Spreading
 * shard dispatch over `stagger_seconds` turns that into a steady trickle: shard i is delayed
 * by `round(i * stagger_seconds / shard_count)` seconds, so the work arrives evenly and the
 * in-flight count is bounded by (shard duration / stagger step) rather than by the fleet size.
 *
 * The delay is queue-side (Redis delayed set), not a sleep - the dispatcher returns immediately
 * and the scheduler tick is never held open.
 *
 * SCOPE (V1): `poll_method = routeros` only. The ~46 SNMP-polled concentrators are deliberately
 * excluded - there is no usable PPPoE active-session table over SNMP on RouterOS (the MikroTik
 * MIB does not expose /ppp/active), so an SNMP lane would be a separate implementation, not a
 * flag. They simply have no rows; the read API's staleness stamp is what tells a caller so.
 */
class PppoeSweepDispatcher
{
    /**
     * @param  int|null  $limit  dispatch only the first N concentrators (canary runs)
     * @param  int|null  $deviceId  dispatch a single device by id (ignores the name filter)
     * @param  bool  $stagger  false = fire every shard immediately (small/manual runs)
     * @return array{devices:int, shards:int, window:int, step:float}
     */
    public function dispatch(?int $limit = null, ?int $deviceId = null, bool $stagger = true): array
    {
        $shards = max(1, (int) config('mymate.pppoe.shards', 96));
        $window = max(0, (int) config('mymate.pppoe.stagger_seconds', 240));

        $ids = $this->concentratorIds($limit, $deviceId);
        if ($ids === []) {
            return ['devices' => 0, 'shards' => 0, 'window' => 0, 'step' => 0.0];
        }

        /** @var array<int, list<int>> $byShard */
        $byShard = [];
        foreach ($ids as $id) {
            $byShard[crc32((string) $id) % $shards][] = $id;
        }
        // Stable order so shard N always draws the same slot in the window - a device's sweep
        // lands at roughly the same offset every cycle instead of jittering across the fleet.
        ksort($byShard);

        $count = count($byShard);
        $step = ($stagger && $window > 0 && $count > 1) ? $window / $count : 0.0;

        $i = 0;
        foreach ($byShard as $shard => $shardIds) {
            $delay = (int) round($i * $step);
            // PendingDispatch: ->delay() proxies onto the job and the dispatch itself happens
            // on destruct, so this stays the ordinary `Job::dispatch(...)` house form.
            $pending = SweepPppoeSessionsBatchJob::dispatch((int) $shard, $shardIds);
            if ($delay > 0) {
                $pending->delay($delay);
            }
            // A PendingDispatch fires on destruct - drop it now so the queue push happens here,
            // not at the next loop iteration's reassignment.
            unset($pending);
            $i++;
        }

        return ['devices' => count($ids), 'shards' => $count, 'window' => $window, 'step' => $step];
    }

    /**
     * The concentrator fleet: monitored, centrally polled, RouterOS, name matching the
     * operator-configurable filter (default `%pppoe%` - how this fleet is actually named).
     *
     * `agent_id IS NULL` mirrors every other central dispatcher in this codebase: an
     * agent-assigned device is reachable from its agent, not from here, so a central API
     * connect would only ever fail and log.
     *
     * @return list<int>
     */
    private function concentratorIds(?int $limit, ?int $deviceId): array
    {
        $query = Device::query()
            ->where('monitored', true)
            ->whereNull('agent_id')
            ->where('poll_method', PollMethod::RouterOs->value)
            ->orderBy('id');

        if ($deviceId !== null) {
            // Explicit single-device runs bypass the name filter (operator knows what they asked
            // for) but still require a RouterOS-polled, monitored, centrally-reachable device.
            $query->whereKey($deviceId);
        } else {
            $filter = (string) config('mymate.pppoe.name_filter', '%pppoe%');
            // Postgres-only app (pgsql in prod AND in phpunit.xml) - ILIKE is the direct
            // expression of the case-insensitive match, and stays index-eligible via pg_trgm
            // if that ever becomes worth adding.
            $query->where('name', 'ilike', $filter);
        }

        if ($limit !== null && $limit > 0) {
            $query->limit($limit);
        }

        return $query->pluck('id')->map(fn ($id): int => (int) $id)->all();
    }

    /**
     * How many concentrators the current filter matches, without dispatching anything -
     * the console command prints it so a canary run can be sanity-checked against the fleet.
     */
    public function fleetSize(): int
    {
        $filter = (string) config('mymate.pppoe.name_filter', '%pppoe%');

        return (int) DB::table('devices')
            ->where('monitored', true)
            ->whereNull('agent_id')
            ->where('poll_method', PollMethod::RouterOs->value)
            ->where('name', 'ilike', $filter)
            ->count();
    }
}
