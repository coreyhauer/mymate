<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\OspfNeighborResource;
use App\Models\OspfNeighbor;
use App\Support\SqlLike;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Per-neighbour OSPF adjacency state (see the OspfNeighbor model), captured by the metrics poll
 * from `/routing/ospf/neighbor/print`. RouterOS publishes no OSPF-MIB over SNMP, so this is the
 * only source of per-neighbour truth in the stack.
 *
 * Each row is one adjacency AS SEEN BY one device. Adjacencies are reported from both ends, so
 * a link between two monitored routers legitimately produces two rows, and they can disagree -
 * one side Full while the other is stuck in ExStart is a real diagnostic state, not a
 * duplicate.
 *
 * FRESHNESS. A device that stops being polled - unmonitored, credential pulled, OSPF removed,
 * decommissioned - stops refreshing its rows, and a long-dead adjacency must never be served as
 * a live one. Rows whose `last_seen_at` is older than `mymate.ospf.stale_after_minutes`
 * (default 30) are hidden BY DEFAULT; pass `?stale=1` to include them, or `?max_age_minutes=`
 * to pick your own horizon (0 = no filter). `last_seen_at` is on every row regardless.
 *
 * DELTAS (`?since=`) take an ISO8601/RFC3339 string or a raw epoch timestamp in seconds or
 * milliseconds, and narrow to rows seen after it. The same overlap advice as
 * PppoeSessionController applies: polling is sharded, each row is stamped when its own device's
 * transaction commits, and `last_seen_at` has one-second resolution - so re-read with an
 * overlap rather than advancing the cursor to the newest value you have seen. Ids are stable
 * for the life of an adjacency (the poll upserts), so deduping the overlap is trivial.
 *
 * FILTERS. `?device_id=` narrows to one device's view. `?router_id=` is an exact match on the
 * neighbour's router-id. `?state=` is an exact, case-insensitive match on the reported state
 * ("full", "2-way", ...); `?full=0` is the shortcut for "every adjacency that is NOT up", which
 * is the alerting case. `?q=` is a case-insensitive substring match over the router-id and the
 * neighbour address together.
 *
 * Always cursor-paginated (`?per_page=`, default 500, max 2000), the same envelope
 * PppoeSessionController produces.
 */
class OspfNeighborController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        // Every scalar param is declared, so `?router_id[]=x` and friends are a 422 rather than
        // an array reaching the query builder and 500ing on "Array to string conversion".
        $validated = $request->validate([
            'per_page' => ['nullable', 'integer', 'min:1', 'max:2000'],
            'since' => ['nullable', 'string'],
            'device_id' => ['nullable', 'integer'],
            'router_id' => ['nullable', 'string'],
            'state' => ['nullable', 'string'],
            'full' => ['nullable', 'boolean'],
            'q' => ['nullable', 'string'],
            'max_age_minutes' => ['nullable', 'integer', 'min:0'],
            'stale' => ['nullable', 'boolean'],
        ]);

        $query = OspfNeighbor::query()->with('device');

        if (isset($validated['since'])) {
            $since = $this->parseSince($validated['since']);

            if ($since === null) {
                throw ValidationException::withMessages([
                    'since' => 'The since field must be a valid ISO8601 date/time or epoch timestamp.',
                ]);
            }

            $query->where('last_seen_at', '>', $since);
        }

        // Hide adjacencies nothing has refreshed lately - see the class docblock.
        $maxAge = isset($validated['max_age_minutes'])
            ? (int) $validated['max_age_minutes']
            : (int) config('mymate.ospf.stale_after_minutes', 30);
        if ($maxAge > 0 && ! $request->boolean('stale')) {
            $query->where('last_seen_at', '>=', now()->subMinutes($maxAge));
        }

        $deviceId = (int) ($validated['device_id'] ?? 0);
        if ($deviceId > 0) {
            $query->where('device_id', $deviceId);
        }

        if (isset($validated['router_id'])) {
            $query->where('router_id', $validated['router_id']);
        }

        if (isset($validated['state'])) {
            // Case-insensitive exact match: RouterOS casing varies between versions, and an
            // operator typing "full" should not have to know which one they are talking to.
            $query->whereRaw('lower(state) = ?', [mb_strtolower($validated['state'])]);
        }

        if (array_key_exists('full', $validated) && $validated['full'] !== null) {
            $query->where('is_full', $request->boolean('full'));
        }

        if (isset($validated['q'])) {
            // Escaped: a value containing % or _ is matched literally, not as a wildcard.
            $needle = '%'.SqlLike::escape($validated['q']).'%';
            $query->where(function ($q) use ($needle): void {
                $q->where('router_id', 'ilike', $needle)
                    ->orWhere('neighbor_address', 'ilike', $needle);
            });
        }

        $perPage = (int) ($validated['per_page'] ?? 500);

        // Deterministic order before paginating - last_seen_at ties across every row a poll
        // batch writes, so the id tiebreak is what makes the cursor stable.
        return OspfNeighborResource::collection(
            $query->orderByDesc('id')->cursorPaginate($perPage)
        );
    }

    /**
     * Accepts ISO8601/RFC3339 strings and raw epoch timestamps given as a string, in either
     * seconds or milliseconds - told apart by magnitude. Both branches sit inside the try:
     * Carbon throws on an out-of-range epoch just as it does on an unparseable string, and
     * that must be a 422, not a 500.
     */
    private function parseSince(string $value): ?Carbon
    {
        if ($value === '') {
            return null;
        }

        try {
            if (ctype_digit($value)) {
                return strlen($value) > 11
                    ? Carbon::createFromTimestampMs((int) $value)
                    : Carbon::createFromTimestamp((int) $value);
            }

            return Carbon::parse($value);
        } catch (Throwable) {
            return null;
        }
    }
}
