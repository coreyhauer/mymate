<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PppoeSessionResource;
use App\Models\PppoeSession;
use App\Support\SqlLike;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Latest known PPPoE session state per concentrator (see the PppoeSession model) - reconciled
 * per device on each sweep, so a row's absence means the session wasn't active as of that
 * device's last sweep, not that it never existed (no history table in V1).
 *
 * FRESHNESS. A device that stops being swept - renamed out of the name filter, unmonitored,
 * credential pulled, decommissioned - stops refreshing its rows, and without a filter those
 * rows would be served as live sessions forever. Rows whose `swept_at` is older than
 * `mymate.pppoe.stale_after_minutes` (default 30) are therefore hidden BY DEFAULT. Pass
 * `?stale=1` to include them, or `?max_age_minutes=` to choose your own horizon (0 = no
 * filter). `swept_at` is on every row either way, so a caller can always judge for itself.
 *
 * DELTAS (`?since=`). Accepts an ISO8601/RFC3339 string or a raw epoch-millisecond integer
 * (e.g. `?since=1735689600000`), and narrows to rows swept after it. Two things a delta
 * consumer must know:
 *
 *  - Sweeps are sharded and staggered across ~4 minutes, and each row is stamped when its own
 *    device's transaction commits. So rows keep arriving with timestamps older than the newest
 *    `swept_at` you have already seen. Re-read with an OVERLAP of at least the stagger window
 *    plus one cadence (~10 minutes is comfortable): pass `since = last_seen_swept_at - 10min`,
 *    not `since = last_seen_swept_at`, or you will permanently miss the slower shards.
 *  - `swept_at` has one-second resolution, so a strict `>` can also skip rows sharing the
 *    cursor's second. The overlap above covers that too.
 *
 * Deduping on the overlap is cheap because ids are STABLE: the sweep upserts, so a continuing
 * session keeps its id for its whole life. A row you have already seen comes back with the
 * same id, and a disappeared session simply stops appearing (V1 emits no tombstone - that is
 * the documented seam, and the freshness filter above is what stops a departed concentrator
 * from looking permanently online).
 *
 * `?device_id=` narrows to one concentrator. `?username=` is an exact match on the PPPoE
 * username; `?q=` is a case-insensitive substring match over the same column (the search-box
 * case) and may be combined with `?username=`.
 *
 * Always cursor-paginated (`?per_page=`, default 500, max 2000) - the standard Resource-
 * collection paginated envelope (`data` + `links` + `meta.next_cursor` + `meta.path`), the same
 * shape OutageController produces when its own `?per_page=` is passed. Unlike Outage this
 * endpoint paginates unconditionally (no bare-array mode) given the fleet size this table
 * carries (~13k active sessions swept from ~2.3k concentrators every cycle).
 */
class PppoeSessionController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        // Every scalar param is declared, so `?username[]=x` (and friends) is a 422 rather than
        // an array reaching the query builder and 500ing on "Array to string conversion".
        $validated = $request->validate([
            'per_page' => ['nullable', 'integer', 'min:1', 'max:2000'],
            'since' => ['nullable', 'string'],
            'device_id' => ['nullable', 'integer'],
            'username' => ['nullable', 'string'],
            'q' => ['nullable', 'string'],
            'max_age_minutes' => ['nullable', 'integer', 'min:0'],
            'stale' => ['nullable', 'boolean'],
        ]);

        $query = PppoeSession::query()->with('device');

        if (isset($validated['since'])) {
            $since = $this->parseSince($validated['since']);

            if ($since === null) {
                throw ValidationException::withMessages([
                    'since' => 'The since field must be a valid ISO8601 date/time or epoch-ms timestamp.',
                ]);
            }

            $query->where('swept_at', '>', $since);
        }

        // Hide rows no sweep has refreshed lately - a concentrator that dropped out of the
        // sweep must not keep answering "these customers are online". See the class docblock.
        $maxAge = isset($validated['max_age_minutes'])
            ? (int) $validated['max_age_minutes']
            : (int) config('mymate.pppoe.stale_after_minutes', 30);
        if ($maxAge > 0 && ! $request->boolean('stale')) {
            $query->where('swept_at', '>=', now()->subMinutes($maxAge));
        }

        $deviceId = (int) ($validated['device_id'] ?? 0);
        if ($deviceId > 0) {
            $query->where('device_id', $deviceId);
        }

        if (isset($validated['username'])) {
            $query->where('username', $validated['username']);
        }

        if (isset($validated['q'])) {
            // Escaped: a username containing % or _ is a literal here, not a wildcard.
            $query->where('username', 'ilike', '%'.SqlLike::escape($validated['q']).'%');
        }

        // Deterministic order before paginating - swept_at ties constantly within a device's
        // own sweep batch (and across devices swept in the same tick), same tiebreak reasoning
        // as OutageController's id-tiebreak comment.
        $perPage = (int) ($validated['per_page'] ?? 500);

        return PppoeSessionResource::collection(
            $query->orderByDesc('id')->cursorPaginate($perPage)
        );
    }

    /**
     * Accepts ISO8601/RFC3339 strings and raw epoch timestamps given as a string, in either
     * seconds or milliseconds - told apart by magnitude, since a 10-digit value cannot be a
     * plausible millisecond timestamp (it would be 1970) and a 13-digit one cannot be seconds.
     * Both branches are inside the try: Carbon throws on an out-of-range epoch just as it does
     * on an unparseable string, and that must be a 422, not a 500.
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
