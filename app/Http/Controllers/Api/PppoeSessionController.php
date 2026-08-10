<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PppoeSessionResource;
use App\Models\PppoeSession;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Latest known PPPoE session state per concentrator (see the PppoeSession model) - wholesale-
 * replaced per device on each sweep, so a row's absence means the session wasn't active as of
 * that device's last sweep, not that it never existed (no history table in V1).
 *
 * `?since=` narrows to rows swept after it - accepts either an ISO8601/RFC3339 string or a raw
 * epoch-millisecond integer (as a query string, e.g. `?since=1735689600000`); a poller wanting
 * deltas should pass its last-seen `swept_at` back in on the next call, the same pattern
 * RfLinkStateController uses. `?device_id=` narrows to one concentrator. `?username=` is an
 * exact match on the PPPoE username; `?q=` is a case-insensitive substring match over the same
 * column (the search-box case) and may be combined with `?username=`.
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
        $validated = $request->validate([
            'per_page' => ['nullable', 'integer', 'min:1', 'max:2000'],
        ]);

        $query = PppoeSession::query()->with('device');

        if ($request->filled('since')) {
            $since = $this->parseSince((string) $request->query('since'));

            if ($since === null) {
                throw ValidationException::withMessages([
                    'since' => 'The since field must be a valid ISO8601 date/time or epoch-ms timestamp.',
                ]);
            }

            $query->where('swept_at', '>', $since);
        }

        $deviceId = $request->integer('device_id');
        if ($deviceId > 0) {
            $query->where('device_id', $deviceId);
        }

        $username = $request->query('username');
        if ($username !== null) {
            $query->where('username', $username);
        }

        $q = $request->query('q');
        if ($q !== null) {
            $query->where('username', 'ilike', '%'.$q.'%');
        }

        // Deterministic order before paginating - swept_at ties constantly within a device's
        // own sweep batch (and across devices swept in the same tick), same tiebreak reasoning
        // as OutageController's id-tiebreak comment.
        $perPage = (int) ($validated['per_page'] ?? 500);

        return PppoeSessionResource::collection(
            $query->orderByDesc('id')->cursorPaginate($perPage)
        );
    }

    /** Accepts ISO8601/RFC3339 strings and raw epoch-millisecond integers (given as a string). */
    private function parseSince(string $value): ?Carbon
    {
        if ($value === '') {
            return null;
        }

        if (ctype_digit($value)) {
            return Carbon::createFromTimestampMs((int) $value);
        }

        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            return null;
        }
    }
}
