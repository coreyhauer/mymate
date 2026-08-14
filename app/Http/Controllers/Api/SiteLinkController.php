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
     *
     * The response is capped at `?limit=` (default 5000, max 20000) purely as a backstop. The
     * table is ~3k rows so the cap is not normally reached; it exists so that a registry that
     * grows an order of magnitude cannot turn this endpoint into an unbounded materialisation
     * of every row plus its resource. `meta.truncated` says when the cap actually bit.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        // Declared so `?media_type[]=x` / `?confidence[]=x` are a 422 rather than an array
        // reaching the query builder and 500ing on "Array to string conversion".
        $validated = $request->validate([
            'site_id' => ['nullable', 'integer'],
            'media_type' => ['nullable', 'string'],
            'confidence' => ['nullable', 'string'],
            'resolved' => ['nullable', 'boolean'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:20000'],
        ]);

        $query = SiteLink::query();

        $siteId = (int) ($validated['site_id'] ?? 0);
        if ($siteId > 0) {
            $query->where(fn ($q) => $q->where('site_a_id', $siteId)->orWhere('site_b_id', $siteId));
        }

        if (isset($validated['media_type'])) {
            $query->where('media_type', $validated['media_type']);
        }

        if (isset($validated['confidence'])) {
            $query->where('endpoint_confidence', $validated['confidence']);
        }

        if ($request->boolean('resolved')) {
            $query->whereNotNull('device_a_id')->whereNotNull('device_b_id');
        }

        $limit = (int) ($validated['limit'] ?? 5000);
        // Fetch one extra to tell "exactly at the cap" from "more than the cap".
        $links = $query->orderBy('id')->limit($limit + 1)->get();
        $truncated = $links->count() > $limit;

        return SiteLinkResource::collection($links->take($limit))
            ->additional(['meta' => ['truncated' => $truncated, 'limit' => $limit]]);
    }
}
