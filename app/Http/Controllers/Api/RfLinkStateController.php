<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\RfLinkStateResource;
use App\Models\RfLinkState;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;

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
 */
class RfLinkStateController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'since' => ['nullable', 'date'],
        ]);

        $query = RfLinkState::query();

        if (isset($validated['since'])) {
            $query->where('synced_at', '>', Carbon::parse($validated['since']));
        }

        $deviceId = $request->integer('device_id');
        if ($deviceId > 0) {
            $query->where('device_id', $deviceId);
        }

        return RfLinkStateResource::collection($query->get());
    }
}
