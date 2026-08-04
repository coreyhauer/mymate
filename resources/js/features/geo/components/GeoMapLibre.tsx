import { useEffect, useRef, useState } from 'react';
// The CSP build loads its worker from a real same-origin URL (setWorkerUrl below) instead of an
// inlined blob. The blob path breaks under this project's rolldown-vite bundler - maplibre's
// GeoJSON worker source ends up referencing a main-thread variable that isn't in the worker's
// scope ("<var> is not defined"), so the basemap renders but any GeoJSON source fails. The CSP
// worker is self-contained, so it sidesteps that entirely.
import maplibregl from 'maplibre-gl/dist/maplibre-gl-csp';
import 'maplibre-gl/dist/maplibre-gl.css';
import { Protocol } from 'pmtiles';
import { useBackhauls, useGeoDevices, useGeoTickets, useSites, type Backhaul, type GeoDevice, type GeoTicketSite, type Site } from '../api/sites';
import { useMapChannel } from '../../topology/hooks/useMapChannel';
import { selectDevice, setInspectorOpen } from '../../../lib/shellStore';
import { GeoSearch, type GeoHit } from './GeoSearch';
import { SitePopup } from './SitePopup';

/**
 * MapLibre GL geographic view (LTD high-scale renderer).
 *
 * A WISP's real map unit is the SITE (tower / fiber cabinet), not the individual radio, so this
 * renders one marker per site - positioned at its coordinates, sized by how much gear it carries,
 * coloured by live health (any device down -> red). Zoomed out, sites cluster into regional
 * groups; zoom in and they resolve to individual sites; zoom in further the individual devices
 * appear. Device data comes from a compact geo feed (/geo/devices), not the full device resource,
 * so opening the map doesn't pull megabytes.
 *
 * Clicking a site opens SitePopup ANCHORED OVER THE TOWER - the device list (plus notes and
 * Sonar tickets) belongs on the map next to the marker the operator just clicked, not in a rail
 * on the far side of the screen (operator preference, restored 2026-08-04 after a deploy
 * regressed it to the right-rail SiteInspector). The rail remains the DEVICE card only.
 *
 * Basemap style + its pmtiles/glyphs/sprite are all same-origin (see build-basemap.sh).
 */

// maplibre-gl's own pre-built worker, served same-origin (see deploy/build/build-basemap.sh).
maplibregl.setWorkerUrl('/vendor/maplibre-gl-csp-worker.js');

// pmtiles:// protocol handler is process-wide; register it once.
let pmtilesRegistered = false;
function ensurePmtilesProtocol(): void {
    if (pmtilesRegistered) return;
    maplibregl.addProtocol('pmtiles', new Protocol().tile);
    pmtilesRegistered = true;
}

const STATUS_COLOR = { up: '#34d399', down: '#f43f5e', unknown: '#52525b' } as const;
const DEVICE_ZOOM = 11; // at/above this, individual devices show and site markers fade out
const TICKET_PURPLE = '#a855f7'; // the ticket layer's one loud colour - nothing else on the map is purple

/**
 * The ticket-layer icon, drawn on a canvas (2x for retina) rather than shipped as a sprite:
 * the basemap's glyph fonts have no emoji, and a runtime-drawn image needs no asset pipeline.
 * A classic perforated ticket stub in bright purple with a white border - unmistakable against
 * the green/red health markers.
 */
function ticketIconImage(): ImageData {
    const w = 52, h = 36; // 2x, rendered at 26x18 via pixelRatio
    const canvas = document.createElement('canvas');
    canvas.width = w;
    canvas.height = h;
    const ctx = canvas.getContext('2d')!;
    const r = 7;

    // Manual rounded-rect path (roundRect() is missing from older Safari + this tsconfig's lib).
    const x0 = 3, y0 = 3, x1 = w - 3, y1 = h - 3;
    ctx.beginPath();
    ctx.moveTo(x0 + r, y0);
    ctx.lineTo(x1 - r, y0);
    ctx.arcTo(x1, y0, x1, y0 + r, r);
    ctx.lineTo(x1, y1 - r);
    ctx.arcTo(x1, y1, x1 - r, y1, r);
    ctx.lineTo(x0 + r, y1);
    ctx.arcTo(x0, y1, x0, y1 - r, r);
    ctx.lineTo(x0, y0 + r);
    ctx.arcTo(x0, y0, x0 + r, y0, r);
    ctx.closePath();
    ctx.fillStyle = TICKET_PURPLE;
    ctx.fill();
    ctx.lineWidth = 3;
    ctx.strokeStyle = '#ffffff';
    ctx.stroke();

    // Side notches, punched out so the map shows through - what makes it read as a ticket.
    ctx.globalCompositeOperation = 'destination-out';
    for (const x of [3, w - 3]) {
        ctx.beginPath();
        ctx.arc(x, h / 2, 5.5, 0, Math.PI * 2);
        ctx.fill();
    }
    ctx.globalCompositeOperation = 'source-over';

    // Perforation line down the stub.
    ctx.strokeStyle = 'rgba(255,255,255,0.85)';
    ctx.lineWidth = 2;
    ctx.setLineDash([3.5, 3.5]);
    ctx.beginPath();
    ctx.moveTo(w * 0.66, 7);
    ctx.lineTo(w * 0.66, h - 7);
    ctx.stroke();

    return ctx.getImageData(0, 0, w, h);
}

const escapeHtml = (s: string): string =>
    s.replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]!);

/** Hover card for a site's open tickets - all content escaped (Sonar subjects land in the DOM). */
function ticketHoverHtml(site: GeoTicketSite): string {
    const rows = site.tickets.slice(0, 5).map((t) => {
        const meta = [t.status, t.priority, t.account_name ?? t.via_device].filter((v): v is string => !!v).join(' · ');
        return `<div style="margin-top:4px">
            <span style="color:#d8b4fe;font-weight:600">#${t.ticket_id}</span>
            ${meta ? `<span style="color:rgba(255,255,255,.55)"> ${escapeHtml(meta)}</span>` : ''}
            <div style="color:rgba(255,255,255,.85)">${escapeHtml(t.subject ?? '(no subject)')}</div>
        </div>`;
    });
    const more = site.tickets.length > 5 ? `<div style="color:rgba(255,255,255,.5);margin-top:4px">+${site.tickets.length - 5} more…</div>` : '';
    return `<div style="max-width:280px;font-size:12px;line-height:1.35">
        <div style="font-weight:600">${escapeHtml(site.name)}</div>${rows.join('')}${more}
        <div style="color:rgba(255,255,255,.45);margin-top:5px">${site.tickets.length === 1 ? 'click to open in Sonar' : 'click for details'}</div>
    </div>`;
}

/**
 * Priority filter for the ticket layer. "High" is the urgent view (HIGH + CRITICAL); "Low" is
 * its complement (LOW / MEDIUM / unprioritised), so the two partitions add up to "All" and a
 * ticket can never vanish from both filtered views.
 */
type TicketFilter = 'all' | 'high' | 'low';
const TICKET_FILTERS: { key: TicketFilter; label: string; title: string }[] = [
    { key: 'all', label: 'All', title: 'Every open ticket' },
    { key: 'high', label: 'High', title: 'HIGH and CRITICAL priority' },
    { key: 'low', label: 'Low', title: 'LOW, MEDIUM and unprioritised' },
];

function filterTicketSites(sites: GeoTicketSite[], filter: TicketFilter): GeoTicketSite[] {
    if (filter === 'all') return sites;
    const isHigh = (p: string | null) => p === 'HIGH' || p === 'CRITICAL';
    return sites
        .map((s) => ({ ...s, tickets: s.tickets.filter((t) => isHigh(t.priority) === (filter === 'high')) }))
        .filter((s) => s.tickets.length > 0);
}

/** Ticket-layer markers: one per site that has at least one open Sonar ticket. */
function ticketPoints(sites: GeoTicketSite[]): PointFeatures {
    return {
        type: 'FeatureCollection',
        features: sites.map((s) => ({
            type: 'Feature',
            geometry: { type: 'Point', coordinates: [s.lng, s.lat] },
            properties: { site_id: s.site_id, count: s.tickets.length },
        })),
    };
}

type PointFeatures = GeoJSON.FeatureCollection<GeoJSON.Point>;

/**
 * Site markers with live health. Each carries `down`/`total` computed from the current device
 * feed, so a site's colour tracks status; the source's clusterProperties sum those across a
 * cluster so a regional group reads red when any of its sites has a device down.
 */
function siteMarkers(sites: Site[], devices: GeoDevice[]): PointFeatures {
    const agg = new Map<number, { total: number; down: number }>();
    for (const d of devices) {
        if (d.site_id == null) continue;
        const a = agg.get(d.site_id) ?? { total: 0, down: 0 };
        a.total++;
        if (d.status === 'down') a.down++;
        agg.set(d.site_id, a);
    }
    const features: GeoJSON.Feature<GeoJSON.Point>[] = [];
    for (const s of sites) {
        if (s.latitude == null || s.longitude == null) continue;
        // Only sites that actually have monitored devices right now - a site whose gear is all
        // decommissioned/acked (0 monitored) is not drawn, rather than shown from a stale count.
        const a = agg.get(s.id);
        if (!a || a.total === 0) continue;
        features.push({
            type: 'Feature',
            geometry: { type: 'Point', coordinates: [s.longitude, s.latitude] },
            // up/down split so the marker label can show both (cluster sums them).
            properties: { id: s.id, name: s.name, total: a.total, down: a.down, up: a.total - a.down },
        });
    }
    return { type: 'FeatureCollection', features };
}

type LineFeatures = GeoJSON.FeatureCollection<GeoJSON.LineString>;

/** Backhaul links -> lines between the two sites' coordinates (real topology from the OSS). */
function backhaulLines(links: Backhaul[]): LineFeatures {
    return {
        type: 'FeatureCollection',
        features: links.map((l) => ({
            type: 'Feature',
            geometry: { type: 'LineString', coordinates: [l.a, l.b] },
            properties: { wireless: l.media_type === 'wireless' },
        })),
    };
}

/** Individual devices (shown only when drilled in past DEVICE_ZOOM). */
function devicePoints(devices: GeoDevice[]): PointFeatures {
    return {
        type: 'FeatureCollection',
        features: devices.map((d) => ({
            type: 'Feature',
            geometry: { type: 'Point', coordinates: [d.lng, d.lat] },
            properties: { id: d.id, name: d.name, status: d.status },
        })),
    };
}

export function GeoMapLibre({ styleUrl, weatherUrl }: { styleUrl: string; weatherUrl: string | null }) {
    const { data: devices } = useGeoDevices();
    const { data: sites } = useSites();
    const { data: backhauls } = useBackhauls();
    useMapChannel(); // keep the device cache fresh for the top-bar counts on this page

    const containerRef = useRef<HTMLDivElement>(null);
    const mapRef = useRef<maplibregl.Map | null>(null);
    const readyRef = useRef(false);
    const fittedRef = useRef(false);
    const devicesRef = useRef<GeoDevice[]>([]);
    const sitesRef = useRef<Site[]>([]);
    const backhaulsRef = useRef<Backhaul[]>([]);
    const [ready, setReady] = useState(false); // map style + our layers are in place
    const [weatherOn, setWeatherOn] = useState(false);
    const [ticketsOn, setTicketsOn] = useState(false);
    const [ticketFilter, setTicketFilter] = useState<TicketFilter>('all');
    const { data: ticketSites } = useGeoTickets(ticketsOn); // fetched only while the layer is on
    const ticketSitesRef = useRef<GeoTicketSite[]>([]);

    // The clicked site's popup, keyed by site so switching towers is a clean remount. Held as a
    // ref-wrapped opener (same pattern as rebuild) so map handlers registered once at init can
    // always reach the current sites list.
    const [popupSite, setPopupSite] = useState<{ site: Site; coords: [number, number] } | null>(null);
    const openSitePopup = useRef((siteId: number) => {
        const s = sitesRef.current.find((x) => x.id === siteId);
        if (s && s.latitude != null && s.longitude != null) {
            setPopupSite({ site: s, coords: [Number(s.longitude), Number(s.latitude)] });
        }
    });

    const rebuild = useRef(() => {
        const map = mapRef.current;
        if (!map || !readyRef.current) return;
        const devs = devicesRef.current;
        const sts = sitesRef.current;
        (map.getSource('sites') as maplibregl.GeoJSONSource | undefined)?.setData(siteMarkers(sts, devs));
        (map.getSource('devices') as maplibregl.GeoJSONSource | undefined)?.setData(devicePoints(devs));
        (map.getSource('backhauls') as maplibregl.GeoJSONSource | undefined)?.setData(backhaulLines(backhaulsRef.current));

        if (!fittedRef.current) {
            const bounds = new maplibregl.LngLatBounds();
            let any = false;
            for (const s of sts) {
                if (s.latitude != null && s.longitude != null) { bounds.extend([s.longitude, s.latitude]); any = true; }
            }
            if (any) {
                fittedRef.current = true;
                map.fitBounds(bounds, { padding: 60, maxZoom: 9, duration: 0 });
            }
        }
    });

    useEffect(() => {
        if (!containerRef.current || mapRef.current) return;
        ensurePmtilesProtocol();

        let cancelled = false;
        const origin = window.location.origin;
        const abs = (u: string) => (u && u.startsWith('/') ? origin + u : u);

        void fetch(styleUrl)
            .then((r) => r.json())
            .then((style: maplibregl.StyleSpecification) => {
                if (cancelled || !containerRef.current) return;
                if (typeof style.sprite === 'string') style.sprite = abs(style.sprite);
                if (typeof style.glyphs === 'string') style.glyphs = abs(style.glyphs);
                for (const src of Object.values(style.sources ?? {})) {
                    const url = (src as { url?: string }).url;
                    if (url?.startsWith('pmtiles:///')) (src as { url?: string }).url = 'pmtiles://' + origin + url.slice('pmtiles://'.length);
                }
                initMap(style);
            })
            .catch((e) => console.error('geo: style fetch failed', e));

        function initMap(style: maplibregl.StyleSpecification) {
            const map = new maplibregl.Map({
                container: containerRef.current!,
                style,
                center: [-94, 44],
                zoom: 4,
                attributionControl: { compact: true },
            });
            map.addControl(new maplibregl.NavigationControl({ showCompass: false }), 'top-left');
            mapRef.current = map;
            map.on('error', (e) => console.error('geo: map error', e.error));

            map.on('load', () => {
                // --- Backhauls: real site-to-site topology, drawn under the markers ---
                map.addSource('backhauls', { type: 'geojson', data: { type: 'FeatureCollection', features: [] } });
                map.addLayer({
                    id: 'backhaul-solid', type: 'line', source: 'backhauls', filter: ['!=', ['get', 'wireless'], true],
                    layout: { 'line-cap': 'round' }, paint: { 'line-color': '#5b8def', 'line-width': 1.5, 'line-opacity': 0.6 },
                });
                map.addLayer({
                    id: 'backhaul-wireless', type: 'line', source: 'backhauls', filter: ['==', ['get', 'wireless'], true],
                    layout: { 'line-cap': 'round' }, paint: { 'line-color': '#8b9cb3', 'line-width': 1.5, 'line-opacity': 0.55, 'line-dasharray': [2, 1.5] },
                });

                // --- Sites: primary markers, clustered into regional groups when zoomed out ---
                map.addSource('sites', {
                    type: 'geojson',
                    data: { type: 'FeatureCollection', features: [] },
                    cluster: true,
                    clusterRadius: 44,
                    clusterMaxZoom: 8, // decluster into individual sites fairly early so they're clickable
                    // Sum up/down/total across a cluster so its colour + label aggregate.
                    clusterProperties: { down: ['+', ['get', 'down']], up: ['+', ['get', 'up']], total: ['+', ['get', 'total']] },
                });
                // Any device down in the marker (or, for a cluster, in any of its sites) -> red.
                const health: maplibregl.ExpressionSpecification = ['case', ['>', ['get', 'down'], 0], STATUS_COLOR.down, STATUS_COLOR.up];
                // Label: up count, then the down count appended only when > 0. Kept white (not
                // green/red) so it reads on both the green (healthy) and red (has-down) circle -
                // the circle's colour already conveys health; the red down-count would vanish on red.
                const upDownLabel: maplibregl.ExpressionSpecification = ['case',
                    ['>', ['get', 'down'], 0],
                    ['concat', ['to-string', ['get', 'up']], '   ', ['to-string', ['get', 'down']]],
                    ['to-string', ['get', 'up']],
                ];

                map.addLayer({
                    id: 'site-clusters', type: 'circle', source: 'sites', filter: ['has', 'point_count'], maxzoom: DEVICE_ZOOM + 1,
                    paint: {
                        'circle-color': health,
                        'circle-radius': ['interpolate', ['linear'], ['get', 'total'], 1, 12, 100, 20, 1000, 30, 5000, 40],
                        'circle-opacity': 0.8, 'circle-stroke-width': 2, 'circle-stroke-color': '#0d0d11',
                    },
                });
                map.addLayer({
                    id: 'site-cluster-count', type: 'symbol', source: 'sites', filter: ['has', 'point_count'], maxzoom: DEVICE_ZOOM + 1,
                    layout: { 'text-field': upDownLabel, 'text-font': ['Noto Sans Regular'], 'text-size': 12, 'text-allow-overlap': true },
                    paint: { 'text-color': '#ffffff' },
                });
                map.addLayer({
                    id: 'site-markers', type: 'circle', source: 'sites', filter: ['!', ['has', 'point_count']], maxzoom: DEVICE_ZOOM + 1,
                    paint: {
                        'circle-color': health,
                        'circle-radius': ['interpolate', ['linear'], ['get', 'total'], 1, 7, 20, 12, 100, 18],
                        'circle-opacity': 0.85, 'circle-stroke-width': 2, 'circle-stroke-color': '#0d0d11',
                    },
                });
                map.addLayer({
                    id: 'site-count', type: 'symbol', source: 'sites', filter: ['!', ['has', 'point_count']], maxzoom: DEVICE_ZOOM + 1,
                    layout: { 'text-field': upDownLabel, 'text-font': ['Noto Sans Regular'], 'text-size': 11, 'text-allow-overlap': true },
                    paint: { 'text-color': '#ffffff' },
                });

                // --- Devices: revealed on drill-in (zoom >= DEVICE_ZOOM) ---
                map.addSource('devices', { type: 'geojson', data: { type: 'FeatureCollection', features: [] } });
                map.addLayer({
                    id: 'device-points', type: 'circle', source: 'devices', minzoom: DEVICE_ZOOM,
                    paint: {
                        'circle-color': ['match', ['get', 'status'], 'up', STATUS_COLOR.up, 'down', STATUS_COLOR.down, STATUS_COLOR.unknown],
                        'circle-radius': 6, 'circle-stroke-width': 2, 'circle-stroke-color': '#0d0d11',
                    },
                });

                // Hover a site -> name tooltip.
                const hover = new maplibregl.Popup({ closeButton: false, closeOnClick: false, offset: 12 });
                map.on('mouseenter', 'site-markers', (e) => {
                    map.getCanvas().style.cursor = 'pointer';
                    const f = e.features?.[0];
                    if (f) hover.setLngLat((f.geometry as GeoJSON.Point).coordinates as [number, number]).setText(String(f.properties?.name ?? 'Site')).addTo(map);
                });
                map.on('mouseleave', 'site-markers', () => { map.getCanvas().style.cursor = ''; hover.remove(); });

                // Hover a device -> its name (+ its site). Site markers stop being drawn above
                // DEVICE_ZOOM + 1, so without this the map goes silent on hover exactly when you've
                // zoomed in far enough to be looking at individual radios.
                map.on('mouseenter', 'device-points', (e) => {
                    map.getCanvas().style.cursor = 'pointer';
                    const f = e.features?.[0];
                    if (!f) return;
                    // In the zoom band where site markers and device dots BOTH render, devices sit
                    // at the site's exact coordinates - without this guard the device tooltip fires
                    // after the site's and overwrites it, so hovering a tower shows "some radio ·
                    // site" instead of the site. The site marker wins; devices get their own hover
                    // once the markers fade out past DEVICE_ZOOM + 1.
                    if (map.queryRenderedFeatures(e.point, { layers: ['site-markers'] }).length > 0) return;
                    const dev = devicesRef.current.find((d) => d.id === Number(f.properties?.id));
                    const siteName = dev?.site_id != null ? sitesRef.current.find((s) => s.id === dev.site_id)?.name : undefined;
                    // setText (not setHTML) - device names are operator-entered and land in the DOM.
                    const custs = typeof dev?.cust_count === 'number' && dev.cust_count > 0
                        ? ` \u{1F465} ${dev.cust_count}` : '';
                    const label = String(f.properties?.name ?? 'Device') + (siteName ? ` · ${siteName}` : '') + custs;
                    hover.setLngLat((f.geometry as GeoJSON.Point).coordinates as [number, number]).setText(label).addTo(map);
                });
                map.on('mouseleave', 'device-points', () => { map.getCanvas().style.cursor = ''; hover.remove(); });

                // Click a cluster -> zoom to expand it. Click a site -> open the site inspector
                // (its devices, notes and Sonar tickets); coincident device coords make that
                // panel, not the map, the real "expand" of a site.
                map.on('click', 'site-clusters', (e) => {
                    const f = map.queryRenderedFeatures(e.point, { layers: ['site-clusters'] })[0];
                    const clusterId = f?.properties?.cluster_id;
                    const src = map.getSource('sites') as maplibregl.GeoJSONSource | undefined;
                    if (clusterId == null || !src) return;
                    void src.getClusterExpansionZoom(clusterId).then((zoom) => {
                        map.easeTo({ center: (f.geometry as GeoJSON.Point).coordinates as [number, number], zoom });
                    });
                });
                map.on('click', 'site-markers', (e) => {
                    const f = e.features?.[0];
                    if (!f) return;
                    hover.remove();
                    openSitePopup.current(Number(f.properties?.id));
                });
                // Click-away closes the site popup - but only when the click landed on none of
                // our interactive layers, so the click that OPENS a popup (or picks a device or
                // ticket) never immediately closes it.
                map.on('click', (e) => {
                    const layers = ['site-markers', 'device-points', 'ticket-icons'].filter((l) => !!map.getLayer(l));
                    if (map.queryRenderedFeatures(e.point, { layers }).length === 0) setPopupSite(null);
                });
                map.on('click', 'device-points', (e) => {
                    // Same stacking rule as hover: when the click also hit a site marker, the site
                    // popup is the intent - don't ALSO open the device inspector underneath it.
                    if (map.queryRenderedFeatures(e.point, { layers: ['site-markers'] }).length > 0) return;
                    const id = e.features?.[0]?.properties?.id;
                    if (typeof id === 'number') {
                        selectDevice(id);
                        setInspectorOpen(true);
                    }
                });
                for (const layer of ['site-clusters', 'device-points']) {
                    map.on('mouseenter', layer, () => { map.getCanvas().style.cursor = 'pointer'; });
                    map.on('mouseleave', layer, () => { map.getCanvas().style.cursor = ''; });
                }

                readyRef.current = true;
                setReady(true);
                rebuild.current();
            });
        }

        return () => {
            cancelled = true;
            mapRef.current?.remove();
            mapRef.current = null;
            readyRef.current = false;
            fittedRef.current = false;
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [styleUrl]);

    useEffect(() => {
        devicesRef.current = devices ?? [];
        sitesRef.current = sites ?? [];
        backhaulsRef.current = backhauls ?? [];
        rebuild.current();
    }, [devices, sites, backhauls]);

    // Weather radar overlay: fetch the latest RainViewer frame and add a raster layer under the
    // markers, refreshing every few minutes while enabled. Removed cleanly when toggled off.
    useEffect(() => {
        const map = mapRef.current;
        if (!map || !ready || !weatherOn || !weatherUrl) return;
        let cancelled = false;
        const load = async () => {
            try {
                const r = await fetch(weatherUrl).then((res) => res.json());
                const frames = [...(r.radar?.past ?? []), ...(r.radar?.nowcast ?? [])];
                const frame = frames[frames.length - 1];
                if (cancelled || !frame) return;
                const tiles = [`${r.host}${frame.path}/256/{z}/{x}/{y}/2/1_1.png`];
                const src = map.getSource('weather') as maplibregl.RasterTileSource | undefined;
                if (src) { src.setTiles(tiles); return; }
                // RainViewer's 256px radar tiles only exist to zoom 7 (z8+ returns a "Zoom Level
                // Not Supported" placeholder image); cap maxzoom so MapLibre overzooms the z7 tile
                // instead - fine for coarse precipitation radar.
                map.addSource('weather', { type: 'raster', tiles, tileSize: 256, maxzoom: 7 });
                // Below our overlay layers so device/site markers stay on top.
                map.addLayer({ id: 'weather', type: 'raster', source: 'weather', paint: { 'raster-opacity': 0.6 } }, 'backhaul-solid');
            } catch (e) {
                console.error('weather: radar load failed', e);
            }
        };
        void load();
        const timer = window.setInterval(() => void load(), 5 * 60 * 1000);
        return () => {
            cancelled = true;
            window.clearInterval(timer);
            if (map.getLayer('weather')) map.removeLayer('weather');
            if (map.getSource('weather')) map.removeSource('weather');
        };
    }, [weatherOn, ready, weatherUrl]);

    // Sonar-ticket layer: bright-purple ticket stubs on every site with an open ticket, at all
    // zooms (the point is spotting them from the wide view). Hover shows the tickets; click
    // opens the ticket in Sonar (or the site inspector when there are several). Added/removed
    // whole with the toggle, weather-style, so the default map carries no extra layers.
    useEffect(() => {
        const map = mapRef.current;
        if (!map || !ready || !ticketsOn) return;

        if (!map.hasImage('ticket-stub')) map.addImage('ticket-stub', ticketIconImage(), { pixelRatio: 2 });
        map.addSource('tickets', { type: 'geojson', data: ticketPoints(ticketSitesRef.current) });
        map.addLayer({
            id: 'ticket-icons', type: 'symbol', source: 'tickets',
            layout: {
                'icon-image': 'ticket-stub',
                'icon-allow-overlap': true,
                'icon-anchor': 'bottom',
                'icon-offset': [10, -8], // float above-right so the health marker stays readable
                // A count on the stub when a site carries more than one open ticket.
                'text-field': ['case', ['>', ['get', 'count'], 1], ['to-string', ['get', 'count']], ''],
                'text-font': ['Noto Sans Regular'],
                'text-size': 11,
                'text-offset': [0.55, -1.55],
                'text-allow-overlap': true,
            },
            paint: { 'text-color': '#ffffff' },
        });

        const hover = new maplibregl.Popup({ closeButton: false, closeOnClick: false, offset: 14, maxWidth: '300px' });
        const enter = (e: maplibregl.MapLayerMouseEvent) => {
            map.getCanvas().style.cursor = 'pointer';
            const f = e.features?.[0];
            const site = ticketSitesRef.current.find((s) => s.site_id === Number(f?.properties?.site_id));
            if (f && site) {
                hover.setLngLat((f.geometry as GeoJSON.Point).coordinates as [number, number])
                    .setHTML(ticketHoverHtml(site)) // content is escaped in ticketHoverHtml
                    .addTo(map);
            }
        };
        const leave = () => { map.getCanvas().style.cursor = ''; hover.remove(); };
        const click = (e: maplibregl.MapLayerMouseEvent) => {
            const site = ticketSitesRef.current.find((s) => s.site_id === Number(e.features?.[0]?.properties?.site_id));
            if (!site) return;
            if (site.tickets.length === 1) {
                window.open(site.tickets[0].url, '_blank', 'noopener');
            } else {
                // Several tickets: the site popup's Sonar section lists them all, on the map.
                hover.remove();
                openSitePopup.current(site.site_id);
            }
        };
        map.on('mouseenter', 'ticket-icons', enter);
        map.on('mouseleave', 'ticket-icons', leave);
        map.on('click', 'ticket-icons', click);

        return () => {
            map.off('mouseenter', 'ticket-icons', enter);
            map.off('mouseleave', 'ticket-icons', leave);
            map.off('click', 'ticket-icons', click);
            hover.remove();
            if (map.getLayer('ticket-icons')) map.removeLayer('ticket-icons');
            if (map.getSource('tickets')) map.removeSource('tickets');
        };
    }, [ticketsOn, ready]);

    // Keep the ticket source current as the query refetches or the priority filter changes
    // (the layer effect above only runs on toggle; data updates flow in here, the same split
    // rebuild() uses for the other sources). The ref holds the FILTERED list, so hover cards
    // and click-through only ever see the tickets the operator asked to look at.
    useEffect(() => {
        ticketSitesRef.current = filterTicketSites(ticketSites ?? [], ticketFilter);
        const map = mapRef.current;
        (map?.getSource('tickets') as maplibregl.GeoJSONSource | undefined)?.setData(ticketPoints(ticketSitesRef.current));
    }, [ticketSites, ticketFilter]);

    /**
     * Fly to a search hit. A site lands at a zoom where its own marker has split out of any
     * cluster and opens its inspector, so "find Didier" answers the question in one action
     * rather than dropping you on a cluster bubble you then have to dig into. A device zooms in
     * past DEVICE_ZOOM (where individual device dots render) and opens its inspector.
     */
    function flyToHit(hit: GeoHit): void {
        const map = mapRef.current;
        if (!map) return;

        if (hit.kind === 'site') {
            const { site } = hit;
            if (site.latitude == null || site.longitude == null) return;
            const coords: [number, number] = [Number(site.longitude), Number(site.latitude)];
            // Stop just SHORT of DEVICE_ZOOM + 1: the site layers are drawn only below that zoom
            // (maxzoom is an exclusive bound), so landing exactly on it hides the very marker you
            // just searched for. At DEVICE_ZOOM both the site marker and its devices are visible.
            map.flyTo({ center: coords, zoom: Math.max(map.getZoom(), DEVICE_ZOOM), speed: 1.6 });
            openSitePopup.current(site.id);
        } else {
            const { device } = hit;
            map.flyTo({ center: [device.lng, device.lat], zoom: Math.max(map.getZoom(), DEVICE_ZOOM + 2), speed: 1.6 });
            selectDevice(device.id);
            setInspectorOpen(true);
        }
    }

    return (
        <div className="relative h-full w-full">
            <div ref={containerRef} className="h-full w-full bg-[#0d0d11]" />
            {popupSite && mapRef.current && ready && (
                <SitePopup
                    key={popupSite.site.id}
                    map={mapRef.current}
                    site={popupSite.site}
                    coords={popupSite.coords}
                    onClose={() => setPopupSite(null)}
                />
            )}
            <GeoSearch sites={sites ?? []} devices={devices ?? []} onPick={flyToHit} />
            <div className="absolute right-3 top-3 z-10 flex gap-2">
                {ticketsOn && (
                    <div className="flex overflow-hidden rounded-lg ring-1 ring-purple-400/40 backdrop-blur">
                        {TICKET_FILTERS.map((f) => (
                            <button
                                key={f.key}
                                type="button"
                                onClick={() => setTicketFilter(f.key)}
                                title={f.title}
                                className={`px-2.5 py-1.5 text-xs font-medium transition-colors ${
                                    ticketFilter === f.key
                                        ? 'bg-purple-500/30 text-purple-100'
                                        : 'bg-black/50 text-white/60 hover:bg-black/70'
                                }`}
                            >
                                {f.label}
                            </button>
                        ))}
                    </div>
                )}
                <button
                    type="button"
                    onClick={() => setTicketsOn((v) => !v)}
                    title="Show sites with open Sonar tickets"
                    className={`rounded-lg px-3 py-1.5 text-xs font-medium ring-1 backdrop-blur transition-colors ${
                        ticketsOn
                            ? 'bg-purple-500/25 text-purple-200 ring-purple-400/50'
                            : 'bg-black/50 text-white/70 ring-white/15 hover:bg-black/70'
                    }`}
                >
                    Tickets
                </button>
                {weatherUrl && (
                    <button
                        type="button"
                        onClick={() => setWeatherOn((v) => !v)}
                        title="Toggle weather radar"
                        className={`rounded-lg px-3 py-1.5 text-xs font-medium ring-1 backdrop-blur transition-colors ${
                            weatherOn
                                ? 'bg-sky-500/20 text-sky-200 ring-sky-400/40'
                                : 'bg-black/50 text-white/70 ring-white/15 hover:bg-black/70'
                        }`}
                    >
                        Weather
                    </button>
                )}
            </div>
        </div>
    );
}
