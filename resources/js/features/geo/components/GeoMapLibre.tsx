import { useEffect, useRef } from 'react';
// The CSP build loads its worker from a real same-origin URL (setWorkerUrl below) instead of an
// inlined blob. The blob path breaks under this project's rolldown-vite bundler - maplibre's
// GeoJSON worker source ends up referencing a main-thread variable that isn't in the worker's
// scope ("<var> is not defined"), so the basemap renders but any GeoJSON source fails. The CSP
// worker is self-contained, so it sidesteps that entirely.
import maplibregl from 'maplibre-gl/dist/maplibre-gl-csp';
import 'maplibre-gl/dist/maplibre-gl.css';
import { Protocol } from 'pmtiles';
import { useDevices } from '../../devices/api/getDevices';
import { useSites } from '../api/sites';
import { useMapChannel } from '../../topology/hooks/useMapChannel';
import { selectDevice } from '../../../lib/shellStore';
import type { Device } from '../../../types';
import type { Site } from '../api/sites';

/**
 * MapLibre GL geographic view (LTD high-scale renderer).
 *
 * A WISP's real map unit is the SITE (tower / fiber cabinet), not the individual radio, so this
 * renders one marker per site - positioned at its coordinates, sized by how much gear it carries,
 * coloured by live health (any device down -> red) - and only reveals the individual devices when
 * you zoom in (or click a site to drill into it). That keeps tens of thousands of devices legible
 * as a few thousand towers, and avoids the arbitrary proximity blobs a device-level clusterer
 * produces.
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

const geoLng = (d: Device): number | null => d.geo_longitude;
const geoLat = (d: Device): number | null => d.geo_latitude;

/**
 * Site markers with live health. `down`/`total` are recomputed from the current device snapshot
 * (not the counts the sites endpoint shipped), so a site's colour tracks status flips live.
 */
function siteMarkers(sites: Site[], devices: Device[]): PointFeatures {
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
            properties: { id: s.id, name: s.name, total: a.total, down: a.down },
        });
    }
    return { type: 'FeatureCollection', features };
}

/** Individual devices (shown only when drilled in past DEVICE_ZOOM). */
function devicePoints(devices: Device[]): PointFeatures {
    const features: GeoJSON.Feature<GeoJSON.Point>[] = [];
    for (const d of devices) {
        const lng = geoLng(d);
        const lat = geoLat(d);
        if (lng == null || lat == null) continue;
        features.push({
            type: 'Feature',
            geometry: { type: 'Point', coordinates: [lng, lat] },
            properties: { id: d.id, name: d.name, status: d.status },
        });
    }
    return { type: 'FeatureCollection', features };
}

export function GeoMapLibre({ styleUrl }: { styleUrl: string }) {
    const { data: devices } = useDevices();
    const { data: sites } = useSites();

    const containerRef = useRef<HTMLDivElement>(null);
    const mapRef = useRef<maplibregl.Map | null>(null);
    const readyRef = useRef(false);
    const fittedRef = useRef(false);
    const devicesRef = useRef<Device[]>([]);
    const sitesRef = useRef<Site[]>([]);

    const rebuild = useRef(() => {
        const map = mapRef.current;
        if (!map || !readyRef.current) return;
        const devs = devicesRef.current;
        const sts = sitesRef.current;
        (map.getSource('sites') as maplibregl.GeoJSONSource | undefined)?.setData(siteMarkers(sts, devs));
        (map.getSource('devices') as maplibregl.GeoJSONSource | undefined)?.setData(devicePoints(devs));

        // Fit to the placed sites once, so the map opens on the footprint.
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

    // Status flips repaint site health + device dots (recompute from the updated device cache).
    useMapChannel(undefined, () => rebuild.current());

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
                // --- Sites: the primary markers, shown until you drill in ---
                map.addSource('sites', { type: 'geojson', data: { type: 'FeatureCollection', features: [] } });
                map.addLayer({
                    id: 'site-markers', type: 'circle', source: 'sites', maxzoom: DEVICE_ZOOM + 1,
                    paint: {
                        // Any device down at the site -> red, else green.
                        'circle-color': ['case', ['>', ['get', 'down'], 0], STATUS_COLOR.down, STATUS_COLOR.up],
                        // Radius grows with the device count.
                        'circle-radius': ['interpolate', ['linear'], ['get', 'total'], 1, 8, 20, 14, 100, 20, 500, 28],
                        'circle-opacity': 0.85,
                        'circle-stroke-width': 2,
                        'circle-stroke-color': '#0d0d11',
                    },
                });
                map.addLayer({
                    id: 'site-count', type: 'symbol', source: 'sites', maxzoom: DEVICE_ZOOM + 1,
                    layout: {
                        'text-field': ['to-string', ['get', 'total']],
                        'text-font': ['Noto Sans Regular'], 'text-size': 11, 'text-allow-overlap': true,
                    },
                    paint: { 'text-color': '#ffffff' },
                });

                // --- Devices: revealed on drill-in (zoom >= DEVICE_ZOOM) ---
                map.addSource('devices', { type: 'geojson', data: { type: 'FeatureCollection', features: [] } });
                map.addLayer({
                    id: 'device-points', type: 'circle', source: 'devices', minzoom: DEVICE_ZOOM,
                    paint: {
                        'circle-color': ['match', ['get', 'status'], 'up', STATUS_COLOR.up, 'down', STATUS_COLOR.down, STATUS_COLOR.unknown],
                        'circle-radius': 6,
                        'circle-stroke-width': 2,
                        'circle-stroke-color': '#0d0d11',
                    },
                });

                // Click a site -> drill into it (fly + zoom so its devices appear).
                map.on('click', 'site-markers', (e) => {
                    const f = e.features?.[0];
                    if (!f) return;
                    map.easeTo({ center: (f.geometry as GeoJSON.Point).coordinates as [number, number], zoom: 14, duration: 500 });
                });
                map.on('click', 'device-points', (e) => {
                    const id = e.features?.[0]?.properties?.id;
                    if (typeof id === 'number') selectDevice(id);
                });
                for (const layer of ['site-markers', 'device-points']) {
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
        rebuild.current();
    }, [devices, sites]);

    return <div ref={containerRef} className="h-full w-full bg-[#0d0d11]" />;
}
