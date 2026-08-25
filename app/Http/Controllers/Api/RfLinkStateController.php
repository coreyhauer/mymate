<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\RfLinkStateResource;
use App\Models\RfLinkState;
use App\Support\SinceParam;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

/**
 * Latest per-device RF/link-health state (see the RfLinkState model) - materialised from
 * LibreNMS by App\Actions\Rf\PullLibreNmsRfMetrics on a ~5-minute cadence. Without `?since=`
 * this returns every row (~16k, one per device); a poller wanting deltas should pass
 * `?since=` (any date/timestamp string) to only get rows synced after it - polling more
 * often than the puller's own ~5-minute cadence just re-fetches nothing new. `?device_id=`
 * narrows to one device.
 *
 * Judge staleness by `source_lastupdate` (when LibreNMS itself last saw the sensor), not
 * `synced_at` (when My Mate wrote the row) - the same distinction SiteLink::rfHealth() and
 * the RfLinkState model docblock draw.
 *
 * The response carries a `?limit=` backstop (default 50000) so this can never become an
 * unbounded materialisation of the whole table as the fleet grows; `meta.truncated` reports
 * whether it bit. `data` is unchanged - the cap is additive to the existing contract.
 */
class RfLinkStateController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'since' => ['nullable', 'string'],
            'device_id' => ['nullable', 'integer'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100000'],
        ]);

        $query = RfLinkState::query();

        if (isset($validated['since'])) {
            // Shared with the other ?since= endpoints so they cannot drift apart on what they
            // accept. Notably this tolerates an ISO8601 offset whose "+" was not
            // percent-encoded (query-string decoding turns it into a space), which the plain
            // `date` rule rejected - a 422 for a perfectly well-formed timestamp, on an
            // endpoint whose entire purpose is being polled.
            $since = SinceParam::parse($validated['since']);

            if ($since === null) {
                throw ValidationException::withMessages([
                    'since' => 'The since field must be a valid ISO8601 date/time or epoch timestamp.',
                ]);
            }

            $query->where('synced_at', '>', $since);
        }

        $deviceId = (int) ($validated['device_id'] ?? 0);
        if ($deviceId > 0) {
            $query->where('device_id', $deviceId);
        }

        // Backstop only - see the class docblock. One row per device (~16k today), so an
        // unfiltered call is already the biggest this gets; the cap keeps a fleet that grows
        // an order of magnitude from turning this into an unbounded materialisation.
        $limit = (int) ($validated['limit'] ?? 50000);
        $rows = $query->orderBy('device_id')->limit($limit + 1)->get();
        $truncated = $rows->count() > $limit;

        return RfLinkStateResource::collection($rows->take($limit))
            ->additional(['meta' => ['truncated' => $truncated, 'limit' => $limit]]);
    }
}
