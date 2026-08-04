<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Geo-overlay support (GitHub #11): the tile/map config the SPA needs, and a server-side
 * geocoder proxy (address -> lat/lng) so the browser never talks to a third party directly.
 */
class GeoController extends Controller
{
    /** Tile URL + attribution for the Leaflet overlay; geo is disabled when tile_url is empty. */
    public function config(): JsonResponse
    {
        $tileUrl = (string) config('mymate.map.tile_url', '');

        $styleUrl = (string) config('mymate.map.basemap.style_url', '');

        return response()->json(['data' => [
            'enabled' => $tileUrl !== '' || $styleUrl !== '',
            'tile_url' => $tileUrl,
            'attribution' => (string) config('mymate.map.tile_attribution', ''),
            'geocoder_enabled' => (string) config('mymate.map.geocoder_url', '') !== '',
            // Vector basemap: when a style URL is set the SPA renders the MapLibre GL view
            // (clustered devices + utilisation-coloured backhauls) instead of Leaflet raster.
            'basemap' => $styleUrl === '' ? null : ['style_url' => $styleUrl],
            // Optional weather-radar overlay: the RainViewer-style JSON the toggle reads. Null = off.
            'weather_radar_url' => ($w = (string) config('mymate.map.weather.radar_url', '')) !== '' ? $w : null,
        ]]);
    }

    /**
     * Compact placed-device feed for the geo map. Just what the map draws - id, name, status,
     * site, effective coordinates (own pin, else the site's, resolved in SQL), and how long a
     * down device has been down - so the map doesn't have to pull the full multi-megabyte device
     * resource just to plot dots and colour sites. Monitored devices only (a paused/acked device
     * isn't on the live map).
     *
     * `down_since` is the still-open outage's `started_at` (the precise "went down" moment; a
     * device's `last_change` is overwritten on recovery too, so it can't answer this). Read as a
     * scalar subquery rather than a join: a device should only ever have one open outage, but a
     * racing poller could briefly leave two, and a join would then emit the device twice and
     * double-count it in the site's device/down tallies. MIN() also picks the earliest start,
     * which is the honest answer for how long the thing has actually been dark. Backed by the
     * partial index on open outages, so this stays cheap against a million-row history.
     */
    public function devices(): JsonResponse
    {
        // ap_customer_counts is keyed by ap_ip and joins 1:1 to devices.mgmt_ip (both unique), so
        // a leftJoin here can't multiply device rows the way an outage join would. cust_count is
        // null when the AP was never polled (no row) - the map shows that as unknown, NOT zero,
        // so a down AP with no data doesn't read as "safe to ignore".
        $rows = DB::table('devices as d')
            ->leftJoin('sites as s', 's.id', '=', 'd.site_id')
            ->leftJoin('ap_customer_counts as c', 'c.ap_ip', '=', 'd.mgmt_ip')
            ->where('d.monitored', true)
            ->whereRaw('COALESCE(d.latitude, s.latitude) IS NOT NULL')
            ->selectRaw('d.id, d.name, d.status, d.site_id, d.mgmt_ip,
                COALESCE(d.latitude, s.latitude) AS lat,
                COALESCE(d.longitude, s.longitude) AS lng,
                c.cust_count, c.down_count AS cust_down,
                (SELECT MIN(o.started_at) FROM outages o
                    WHERE o.device_id = d.id AND o.ended_at IS NULL) AS down_since')
            ->get()
            ->map(fn ($r) => [
                'id' => (int) $r->id,
                'name' => $r->name,
                'status' => $r->status,
                'site_id' => $r->site_id !== null ? (int) $r->site_id : null,
                'mgmt_ip' => $r->mgmt_ip,
                'lat' => (float) $r->lat,
                'lng' => (float) $r->lng,
                'cust_count' => $r->cust_count !== null ? (int) $r->cust_count : null,
                'cust_down' => $r->cust_down !== null ? (int) $r->cust_down : null,
                'down_since' => $r->down_since !== null
                    ? Carbon::parse($r->down_since)->toIso8601String()
                    : null,
            ]);

        return response()->json(['data' => $rows]);
    }

    /**
     * Site-to-site backhaul links as coordinate pairs, for the geo map. Both ends resolved to
     * their site's coordinates in SQL; only links whose sites are both placed are returned.
     */
    public function backhauls(): JsonResponse
    {
        $rows = DB::table('site_links as l')
            ->join('sites as a', 'a.id', '=', 'l.site_a_id')
            ->join('sites as b', 'b.id', '=', 'l.site_b_id')
            ->whereNotNull('a.latitude')->whereNotNull('b.latitude')
            ->selectRaw('l.id, l.media_type, a.longitude AS a_lng, a.latitude AS a_lat, b.longitude AS b_lng, b.latitude AS b_lat')
            ->get()
            ->map(fn ($r) => [
                'id' => (int) $r->id,
                'media_type' => $r->media_type,
                'a' => [(float) $r->a_lng, (float) $r->a_lat],
                'b' => [(float) $r->b_lng, (float) $r->b_lat],
            ]);

        return response()->json(['data' => $rows]);
    }

    /**
     * Open Sonar tickets rolled up to their tower, for the geo map's ticket layer. A ticket
     * linked to a device belongs to that device's site; one linked to a site belongs to the
     * site itself. One row per placed site, carrying every non-CLOSED ticket it hosts, each
     * with its Sonar deep-link (same template as SonarTicketLinkResource). Link-attached
     * tickets are deliberately absent: the map's unit is the site marker.
     */
    public function tickets(): JsonResponse
    {
        $template = (string) config('mymate.sonar.ticket_url_template');
        $open = static function ($query): void {
            $query->where(function ($w): void {
                $w->whereNull('t.status')->orWhere('t.status', '!=', 'CLOSED');
            });
        };

        $siteLinked = DB::table('sonar_ticket_links as t')
            ->join('sites as s', 's.id', '=', 't.notable_id')
            ->where('t.notable_type', \App\Models\Site::class)
            ->whereNotNull('s.latitude')->whereNotNull('s.longitude')
            ->where($open)
            ->selectRaw("s.id AS site_id, s.name AS site_name, s.latitude AS lat, s.longitude AS lng,
                t.ticket_id, t.subject, t.status, t.priority, t.account_name, NULL AS via_device");

        $deviceLinked = DB::table('sonar_ticket_links as t')
            ->join('devices as d', 'd.id', '=', 't.notable_id')
            ->join('sites as s', 's.id', '=', 'd.site_id')
            ->where('t.notable_type', \App\Models\Device::class)
            ->whereNotNull('s.latitude')->whereNotNull('s.longitude')
            ->where($open)
            ->selectRaw("s.id AS site_id, s.name AS site_name, s.latitude AS lat, s.longitude AS lng,
                t.ticket_id, t.subject, t.status, t.priority, t.account_name, d.name AS via_device");

        $bySite = [];
        foreach ($siteLinked->unionAll($deviceLinked)->get() as $r) {
            $siteId = (int) $r->site_id;
            $bySite[$siteId] ??= [
                'site_id' => $siteId,
                'name' => $r->site_name,
                'lat' => (float) $r->lat,
                'lng' => (float) $r->lng,
                'tickets' => [],
            ];
            $bySite[$siteId]['tickets'][] = [
                'ticket_id' => (int) $r->ticket_id,
                'url' => str_replace('{id}', (string) $r->ticket_id, $template),
                'subject' => $r->subject,
                'status' => $r->status,
                'priority' => $r->priority,
                'account_name' => $r->account_name,
                'via_device' => $r->via_device,
            ];
        }

        return response()->json(['data' => array_values($bySite)]);
    }

    /** Geocode an address to coordinates via the configured provider (proxied + best-effort). */
    public function geocode(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));
        $url = (string) config('mymate.map.geocoder_url', '');
        if ($q === '' || $url === '') {
            return response()->json(['data' => null]);
        }

        try {
            // Fixed, trusted host (like the update check) - a valid User-Agent is required by
            // Nominatim's policy. Any failure just yields "no result", never an error.
            $res = Http::timeout(8)
                ->withHeaders(['User-Agent' => 'my-mate-geocoder'])
                ->acceptJson()
                ->get($url, ['q' => $q, 'format' => 'json', 'limit' => 1]);

            $hit = $res->successful() ? ($res->json()[0] ?? null) : null;
            if ($hit === null || ! isset($hit['lat'], $hit['lon'])) {
                return response()->json(['data' => null]);
            }

            return response()->json(['data' => [
                'lat' => (float) $hit['lat'],
                'lng' => (float) $hit['lon'],
                'label' => (string) ($hit['display_name'] ?? $q),
            ]]);
        } catch (\Throwable) {
            return response()->json(['data' => null]);
        }
    }
}
