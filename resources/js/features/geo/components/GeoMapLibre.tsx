import { useEffect, useRef } from 'react';
import maplibregl from 'maplibre-gl';
import 'maplibre-gl/dist/maplibre-gl.css';
import { Protocol } from 'pmtiles';
import { useDevices } from '../../devices/api/getDevices';
import { useLinks } from '../../topology/api/getLinks';
import { useMapChannel } from '../../topology/hooks/useMapChannel';
import { linkColorConcrete, linkWidth } from '../../topology/lib/linkColor';
import { selectDevice } from '../../../lib/shellStore';
import type { Device, InterfaceUtilFrame, Link } from '../../../types';

/**
 * MapLibre GL geographic view (LTD high-scale renderer): the whole fleet on a self-hosted
 * vector basemap, drawn on the GPU so tens of thousands of devices and their backhaul links
 * stay smooth where the Leaflet/DOM view would stall.
 *
 * - Devices are a clustered GeoJSON source, coloured by status, placed at their own pin or
 *   their site's coordinates (geo_latitude/geo_longitude, resolved server-side).
 * - Backhaul links are line features between the two endpoints' coordinates, coloured green->red
 *   by live utilisation (the same ramp the topology edges use) and dashed for wireless. They
 *   recolour in place on each InterfaceUtilUpdated frame - no refetch, no scene rebuild.
 *
 * The basemap style + its pmtiles/glyphs/sprite are all same-origin (see build-basemap.sh), so
 * nothing here talks to a third party.
 */

// pmtiles:// protocol handler is process-wide; register it once.
let pmtilesRegistered = false;
function ensurePmtilesProtocol(): void {
    if (pmtilesRegistered) return;
    maplibregl.addProtocol('pmtiles', new Protocol().tile);
    pmtilesRegistered = true;
}

const STATUS_COLOR = { up: '#34d399', down: '#f43f5e', unknown: '#52525b' } as const;

const geoLng = (d: Device): number | null => d.geo_longitude;
const geoLat = (d: Device): number | null => d.geo_latitude;

type DeviceFeatures = GeoJSON.FeatureCollection<GeoJSON.Point>;
type LinkFeatures = GeoJSON.FeatureCollection<GeoJSON.LineString>;

/** Devices with resolved coordinates -> GeoJSON points (clustered by the source). */
function devicePoints(devices: Device[]): DeviceFeatures {
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

/**
 * Backhaul links -> GeoJSON lines coloured by live utilisation. Live per-interface throughput
 * (from InterfaceUtilUpdated frames) overrides the snapshot carried on the link, so a line's
 * colour tracks load without refetching the link list.
 */
function linkLines(
    links: Link[],
    coordById: Map<number, [number, number]>,
    statusById: Map<number, string>,
    liveFrames: Map<number, InterfaceUtilFrame>,
): LinkFeatures {
    const bpsOut = (ifaceId: number | null, snapshot: number | null | undefined): number | null =>
        (ifaceId != null ? liveFrames.get(ifaceId)?.bps_out : undefined) ?? snapshot ?? null;
    const bpsIn = (ifaceId: number | null, snapshot: number | null | undefined): number | null =>
        (ifaceId != null ? liveFrames.get(ifaceId)?.bps_in : undefined) ?? snapshot ?? null;
    const maxNum = (a: number | null, b: number | null): number | null => {
        const xs = [a, b].filter((x): x is number => x != null);
        return xs.length ? Math.max(...xs) : null;
    };

    const features: GeoJSON.Feature<GeoJSON.LineString>[] = [];
    for (const l of links) {
        const a = coordById.get(l.a_device_id);
        const b = coordById.get(l.b_device_id);
        if (!a || !b || (a[0] === b[0] && a[1] === b[1])) continue; // need two distinct placed ends

        const abBps = maxNum(bpsOut(l.a_interface_id, l.a_interface?.bps_out), bpsIn(l.b_interface_id, l.b_interface?.bps_in));
        const baBps = maxNum(bpsOut(l.b_interface_id, l.b_interface?.bps_out), bpsIn(l.a_interface_id, l.a_interface?.bps_in));
        const utilAb = abBps != null && l.eff_ab_mbps ? (abBps / (l.eff_ab_mbps * 1e6)) * 100 : null;
        const utilBa = baBps != null && l.eff_ba_mbps ? (baBps / (l.eff_ba_mbps * 1e6)) * 100 : null;
        const util = maxNum(utilAb, utilBa);
        const down = statusById.get(l.a_device_id) === 'down' || statusById.get(l.b_device_id) === 'down';

        features.push({
            type: 'Feature',
            geometry: { type: 'LineString', coordinates: [a, b] },
            properties: {
                color: linkColorConcrete(util, down),
                width: linkWidth(util),
                wireless: l.media_type === 'wireless',
            },
        });
    }
    return { type: 'FeatureCollection', features };
}

export function GeoMapLibre({ styleUrl }: { styleUrl: string }) {
    const { data: devices } = useDevices();
    const { data: links } = useLinks();

    const containerRef = useRef<HTMLDivElement>(null);
    const mapRef = useRef<maplibregl.Map | null>(null);
    const readyRef = useRef(false); // style + our sources/layers are in place
    const fittedRef = useRef(false);
    const liveFramesRef = useRef<Map<number, InterfaceUtilFrame>>(new Map());
    // Latest data kept in refs so the util-frame handler can rebuild without re-subscribing.
    const devicesRef = useRef<Device[]>([]);
    const linksRef = useRef<Link[]>([]);

    // Recompute both sources from current refs and push to the map (no-op until ready).
    const rebuild = useRef(() => {
        const map = mapRef.current;
        if (!map || !readyRef.current) return;
        const devs = devicesRef.current;
        const coordById = new Map<number, [number, number]>();
        const statusById = new Map<number, string>();
        for (const d of devs) {
            const lng = geoLng(d);
            const lat = geoLat(d);
            if (lng != null && lat != null) coordById.set(d.id, [lng, lat]);
            statusById.set(d.id, d.status);
        }
        (map.getSource('devices') as maplibregl.GeoJSONSource | undefined)?.setData(devicePoints(devs));
        (map.getSource('backhauls') as maplibregl.GeoJSONSource | undefined)?.setData(
            linkLines(linksRef.current, coordById, statusById, liveFramesRef.current),
        );

        // Fit to the fleet once there are placed devices.
        if (!fittedRef.current && coordById.size > 0) {
            fittedRef.current = true;
            const bounds = new maplibregl.LngLatBounds();
            for (const c of coordById.values()) bounds.extend(c);
            map.fitBounds(bounds, { padding: 60, maxZoom: 12, duration: 0 });
        }
    });

    // Live per-interface throughput -> merge frames + recolour lines in place.
    useMapChannel((payload) => {
        for (const dev of payload.devices) {
            for (const f of dev.interfaces) liveFramesRef.current.set(f.interface_id, f);
        }
        rebuild.current();
    });

    // Init the map once.
    useEffect(() => {
        if (!containerRef.current || mapRef.current) return;
        ensurePmtilesProtocol();
        const map = new maplibregl.Map({
            container: containerRef.current,
            style: styleUrl,
            center: [-94, 44],
            zoom: 4,
            attributionControl: { compact: true },
        });
        map.addControl(new maplibregl.NavigationControl({ showCompass: false }), 'top-left');
        mapRef.current = map;

        map.on('load', () => {
            // Backhauls first so device points sit above the lines.
            map.addSource('backhauls', { type: 'geojson', data: { type: 'FeatureCollection', features: [] } });
            map.addLayer({
                id: 'backhaul-solid', type: 'line', source: 'backhauls',
                filter: ['!=', ['get', 'wireless'], true],
                layout: { 'line-cap': 'round', 'line-join': 'round' },
                paint: { 'line-color': ['get', 'color'], 'line-width': ['get', 'width'], 'line-opacity': 0.85 },
            });
            map.addLayer({
                id: 'backhaul-wireless', type: 'line', source: 'backhauls',
                filter: ['==', ['get', 'wireless'], true],
                layout: { 'line-cap': 'round', 'line-join': 'round' },
                paint: { 'line-color': ['get', 'color'], 'line-width': ['get', 'width'], 'line-opacity': 0.85, 'line-dasharray': [2, 1.5] },
            });

            // Devices as a clustered source.
            map.addSource('devices', {
                type: 'geojson',
                data: { type: 'FeatureCollection', features: [] },
                cluster: true,
                clusterRadius: 48,
                clusterMaxZoom: 13,
            });
            map.addLayer({
                id: 'clusters', type: 'circle', source: 'devices', filter: ['has', 'point_count'],
                paint: {
                    'circle-color': ['step', ['get', 'point_count'], '#2563eb', 25, '#7c3aed', 100, '#db2777'],
                    'circle-radius': ['step', ['get', 'point_count'], 14, 25, 18, 100, 24],
                    'circle-opacity': 0.85,
                    'circle-stroke-width': 2,
                    'circle-stroke-color': '#0d0d11',
                },
            });
            map.addLayer({
                id: 'cluster-count', type: 'symbol', source: 'devices', filter: ['has', 'point_count'],
                layout: { 'text-field': ['get', 'point_count_abbreviated'], 'text-font': ['Noto Sans Regular'], 'text-size': 12 },
                paint: { 'text-color': '#ffffff' },
            });
            map.addLayer({
                id: 'device-points', type: 'circle', source: 'devices', filter: ['!', ['has', 'point_count']],
                paint: {
                    'circle-color': ['match', ['get', 'status'], 'up', STATUS_COLOR.up, 'down', STATUS_COLOR.down, STATUS_COLOR.unknown],
                    'circle-radius': 6,
                    'circle-stroke-width': 2,
                    'circle-stroke-color': '#0d0d11',
                },
            });

            // Zoom into a cluster on click.
            map.on('click', 'clusters', (e) => {
                const feat = map.queryRenderedFeatures(e.point, { layers: ['clusters'] })[0];
                const clusterId = feat?.properties?.cluster_id;
                const src = map.getSource('devices') as maplibregl.GeoJSONSource | undefined;
                if (clusterId == null || !src) return;
                void src.getClusterExpansionZoom(clusterId).then((zoom) => {
                    map.easeTo({ center: (feat.geometry as GeoJSON.Point).coordinates as [number, number], zoom });
                });
            });
            // Select a device on click.
            map.on('click', 'device-points', (e) => {
                const id = e.features?.[0]?.properties?.id;
                if (typeof id === 'number') selectDevice(id);
            });
            for (const layer of ['clusters', 'device-points']) {
                map.on('mouseenter', layer, () => { map.getCanvas().style.cursor = 'pointer'; });
                map.on('mouseleave', layer, () => { map.getCanvas().style.cursor = ''; });
            }

            readyRef.current = true;
            rebuild.current();
        });

        return () => { map.remove(); mapRef.current = null; readyRef.current = false; fittedRef.current = false; };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [styleUrl]);

    // Push new query data into the map whenever devices/links change (status flips, refetch).
    useEffect(() => {
        devicesRef.current = devices ?? [];
        linksRef.current = links ?? [];
        rebuild.current();
    }, [devices, links]);

    return <div ref={containerRef} className="h-full w-full bg-[#0d0d11]" />;
}
