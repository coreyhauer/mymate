<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\SiteLinkResource;
use App\Models\SiteLink;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The raw site_links registry - every column, including the resolved-endpoint overlay
 * (device_a_id/interface_a_id/device_b_id/interface_b_id, endpoint_confidence/
 * endpoint_method/endpoint_evidence - see the SiteLink model). GeoController::backhauls()
 * only ever serves a coordinate pair per link, for the map to draw a line; this is for
 * external consumers and the map inspector that need the actual ids and endpoint
 * provenance behind that line. As with the model itself: always check
 * `endpoint_confidence` before trusting device_a_id/device_b_id for anything alert-worthy.
 */
class SiteLinkController extends Controller
{
    /**
     * All site_links (~3k rows, unpaginated - same "just return everything" contract as
     * geo/backhauls). Optional filters: `?site_id=` (either end), `?media_type=`,
     * `?confidence=` (exact `endpoint_confidence` match), and `?resolved=1` (both ends'
     * device ids are non-null).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = SiteLink::query();

        $siteId = $request->integer('site_id');
        if ($siteId > 0) {
            $query->where(fn ($q) => $q->where('site_a_id', $siteId)->orWhere('site_b_id', $siteId));
        }

        $mediaType = $request->query('media_type');
        if ($mediaType !== null) {
            $query->where('media_type', $mediaType);
        }

        $confidence = $request->query('confidence');
        if ($confidence !== null) {
            $query->where('endpoint_confidence', $confidence);
        }

        if ($request->boolean('resolved')) {
            $query->whereNotNull('device_a_id')->whereNotNull('device_b_id');
        }

        return SiteLinkResource::collection($query->get());
    }
}
