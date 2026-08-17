<?php

namespace App\Http\Controllers\Api;

use App\Actions\Polling\ReadWireless;
use App\Http\Controllers\Controller;
use App\Http\Resources\WirelessRegistrationResource;
use App\Models\WirelessRegistration;
use App\Support\SinceParam;
use App\Support\SqlLike;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

/**
 * Per-client wireless registration-table state (see the WirelessRegistration model), captured
 * by the metrics poll from RouterOS's registration table(s) - the only place a MyMate device's
 * associated-client MACs exist at all.
 *
 * Each row is one client AS SEEN ON one radio. An AP's row is its associated station; a
 * station-mode CPE's row is the one AP it registered to - the same physical client can
 * therefore legitimately appear once per radio it talks to.
 *
 * FRESHNESS. A radio that stops being polled - unmonitored, credential pulled, powered off -
 * stops refreshing its rows, and (since an empty read is treated as untrusted here - see
 * ReadWireless - not even a radio that has genuinely gone client-less prunes its own rows) a
 * long-vanished client must never be served as a live one. Rows whose `last_seen_at` is older
 * than `mymate.wireless.stale_after_minutes` (default 30) are hidden BY DEFAULT; pass `?stale=1`
 * to include them, or `?max_age_minutes=` to pick your own horizon (0 = no filter, the same
 * thing `?stale=1` means - the convention PppoeSessionController already uses).
 * `first_seen_at` / `last_seen_at` are on every row regardless.
 *
 * Freshness is a FILTER, not a storage policy: hiding a row here does not delete it. Rows live
 * until `mymate.wireless.retention_days` (default 90, `mymate:wireless:reap`), so a caller that
 * wants history - "which AP has this MAC been seen on, and when" - asks for it with
 * `?max_age_minutes=0` and ranks on `last_seen_at`.
 *
 * DELTAS (`?since=`) take an ISO8601/RFC3339 string or a raw epoch timestamp in seconds or
 * milliseconds, and narrow to rows seen after it. Same overlap advice as OspfNeighborController:
 * polling is sharded and `last_seen_at` has one-second resolution, so re-read with an overlap
 * rather than advancing the cursor to the newest value you have seen. Ids are stable for as
 * long as a client keeps appearing (the poll upserts), so deduping the overlap is trivial.
 *
 * FILTERS. `?device_id=` narrows to one radio's view. `?mac=` is an exact match on the client's
 * MAC, normalized the same lowercase-colon way rows are stored so "AA:BB:CC:DD:EE:FF",
 * "aabb.ccdd.eeff" and "aa:bb:cc:dd:ee:ff" all find the same row. `?q=` is a case-insensitive
 * substring match over the MAC, the interface name, and the device name together.
 *
 * Always cursor-paginated (`?per_page=`, default 500, max 2000), the same envelope
 * OspfNeighborController produces.
 */
class WirelessRegistrationController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        // Every scalar param is declared, so `?device_id[]=x` and friends are a 422 rather than
        // an array reaching the query builder and 500ing on "Array to string conversion".
        $validated = $request->validate([
            'per_page' => ['nullable', 'integer', 'min:1', 'max:2000'],
            'since' => ['nullable', 'string'],
            'device_id' => ['nullable', 'integer'],
            'mac' => ['nullable', 'string'],
            'q' => ['nullable', 'string'],
            'max_age_minutes' => ['nullable', 'integer', 'min:0'],
            'stale' => ['nullable', 'boolean'],
        ]);

        $query = WirelessRegistration::query()->with('device');

        if (isset($validated['since'])) {
            $since = SinceParam::parse($validated['since']);

            if ($since === null) {
                throw ValidationException::withMessages([
                    'since' => 'The since field must be a valid ISO8601 date/time or epoch timestamp.',
                ]);
            }

            $query->where('last_seen_at', '>', $since);
        }

        // Hide clients nothing has refreshed lately - see the class docblock.
        $maxAge = isset($validated['max_age_minutes'])
            ? (int) $validated['max_age_minutes']
            : (int) config('mymate.wireless.stale_after_minutes', 30);
        if ($maxAge > 0 && ! $request->boolean('stale')) {
            $query->where('last_seen_at', '>=', now()->subMinutes($maxAge));
        }

        $deviceId = (int) ($validated['device_id'] ?? 0);
        if ($deviceId > 0) {
            $query->where('device_id', $deviceId);
        }

        if (isset($validated['mac'])) {
            // Same normalization the persist step stores rows under, so a caller can paste a
            // MAC in whatever separator/case their own tool prints it in.
            $query->where('mac_address', ReadWireless::normalizeMac($validated['mac']));
        }

        if (isset($validated['q'])) {
            // Escaped: a value containing % or _ is matched literally, not as a wildcard.
            $needle = '%'.SqlLike::escape($validated['q']).'%';
            $query->where(function ($q) use ($needle): void {
                $q->where('mac_address', 'ilike', $needle)
                    ->orWhere('interface', 'ilike', $needle)
                    ->orWhereHas('device', function ($dq) use ($needle): void {
                        $dq->where('name', 'ilike', $needle);
                    });
            });
        }

        $perPage = (int) ($validated['per_page'] ?? 500);

        // Deterministic order before paginating - last_seen_at ties across every row a poll
        // batch writes, so the id tiebreak is what makes the cursor stable.
        return WirelessRegistrationResource::collection(
            $query->orderByDesc('id')->cursorPaginate($perPage)
        );
    }
}
