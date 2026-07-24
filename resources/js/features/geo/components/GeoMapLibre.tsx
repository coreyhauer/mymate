import { useEffect, useRef } from 'react';
// The CSP build loads its worker from a real same-origin URL (setWorkerUrl below) instead of an
// inlined blob. The blob path breaks under this project's rolldown-vite bundler - maplibre's
// GeoJSON worker source ends up referencing a main-thread variable that isn't in the worker's
// scope ("<var> is not defined"), so the basemap renders but any GeoJSON source fails. The CSP
// worker is self-contained, so it sidesteps that entirely.
import maplibregl from 'maplibre-gl/dist/maplibre-gl-csp';
import 'maplibre-gl/dist/maplibre-gl.css';
import { Protocol } from 'pmtiles';
import { useBackhauls, useGeoDevices, useSites, type Backhaul, type GeoDevice, type Site } from '../api/sites';
import { useMapChannel } from '../../topology/hooks/useMapChannel';
import { selectDevice } from '../../../lib/shellStore';

/**
 * MapLibre GL geographic view (LTD high-scale renderer).
 *
 * A WISP's real map unit is the SITE (tower / fiber cabinet), not the individual radio, so this
 * renders one marker per site - positioned at its coordinates, sized by how much gear it carries,
 * coloured by live health (any device down -> red). Zoomed out, sites cluster into regional
 * groups; zoom in and they resolve to individual sites; zoom in further (or click a site) and the
 * individual devices appear. Device data comes from a compact geo feed (/geo/devices), not the
 * full device resource, so opening the map doesn't pull megabytes.
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
        const a = agg.get(s.id) ?? { total: s.device_count ?? 0, down: s.devices_down ?? 0 };
        if (a.total === 0) continue; // an empty site adds no signal to the map
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

/**
 * Popup for a clicked site: its name, then every device at it with a red/green status dot.
 * Devices at a site share the site's coordinates, so a list is how you actually "expand" a site
 * into its gear. Clicking a device opens its inspector. Built as DOM (with per-item handlers) so
 * it lives inside MapLibre's popup.
 */
function openSiteDevices(map: maplibregl.Map, siteName: string, coords: [number, number], devices: GeoDevice[]): void {
    const devs = [...devices].sort(
        (a, b) => Number(b.status === 'down') - Number(a.status === 'down') || a.name.localeCompare(b.name),
    );
    const down = devs.filter((d) => d.status === 'down').length;

    const wrap = document.createElement('div');
    wrap.style.cssText = 'font:12px/1.4 system-ui,sans-serif;color:#e5e7eb;min-width:200px;';
    const head = document.createElement('div');
    head.style.cssText = 'font-weight:600;font-size:13px;color:#fff;margin-bottom:6px;';
    head.textContent = siteName;
    const sub = document.createElement('span');
    sub.style.cssText = 'font-weight:400;color:#9ca3af;';
    sub.textContent = ` · ${devs.length} device${devs.length === 1 ? '' : 's'}${down ? ` · ${down} down` : ''}`;
    head.appendChild(sub);
    wrap.appendChild(head);

    const list = document.createElement('div');
    list.style.cssText = 'max-height:240px;overflow:auto;display:flex;flex-direction:column;';
    const popup = new maplibregl.Popup({ maxWidth: '300px', offset: 12 });
    for (const d of devs) {
        const item = document.createElement('button');
        item.type = 'button';
        item.style.cssText = 'display:flex;align-items:center;gap:8px;padding:4px 6px;background:none;border:0;color:inherit;cursor:pointer;text-align:left;border-radius:6px;';
        item.onmouseenter = () => (item.style.background = 'rgba(255,255,255,.06)');
        item.onmouseleave = () => (item.style.background = 'none');
        const dot = document.createElement('span');
        dot.style.cssText = `width:8px;height:8px;border-radius:50%;flex:0 0 auto;background:${STATUS_COLOR[d.status] ?? STATUS_COLOR.unknown};`;
        const nm = document.createElement('span');
        nm.style.cssText = 'overflow:hidden;text-overflow:ellipsis;white-space:nowrap;';
        nm.textContent = d.name;
        item.append(dot, nm);
        item.addEventListener('click', () => { selectDevice(d.id); popup.remove(); });
        list.appendChild(item);
    }
    if (devs.length === 0) {
        const empty = document.createElement('div');
        empty.style.cssText = 'color:#9ca3af;padding:2px 6px;';
        empty.textContent = 'No monitored devices at this site.';
        list.appendChild(empty);
    }
    wrap.appendChild(list);
    popup.setLngLat(coords).setDOMContent(wrap).addTo(map);
}

export function GeoMapLibre({ styleUrl }: { styleUrl: string }) {
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

                // Click a cluster -> zoom to expand it. Click a site -> pop its devices (name + a
                // red/green status row each); coincident device coords make a list the real "expand".
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
                    const siteId = Number(f.properties?.id);
                    openSiteDevices(map, String(f.properties?.name ?? 'Site'),
                        (f.geometry as GeoJSON.Point).coordinates as [number, number],
                        devicesRef.current.filter((d) => d.site_id === siteId));
                });
                map.on('click', 'device-points', (e) => {
                    const id = e.features?.[0]?.properties?.id;
                    if (typeof id === 'number') selectDevice(id);
                });
                for (const layer of ['site-clusters', 'device-points']) {
                    map.on('mouseenter', layer, () => { map.getCanvas().style.cursor = 'pointer'; });
                    map.on('mouseleave', layer, () => { map.getCanvas().style.cursor = ''; });
                }

                readyRef.current = true;
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

    return <div ref={containerRef} className="h-full w-full bg-[#0d0d11]" />;
}
