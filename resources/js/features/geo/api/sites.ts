import { useQuery } from '@tanstack/react-query';
import { apiClient } from '../../../lib/apiClient';

export interface Site {
    id: number;
    name: string;
    kind: string;
    latitude: number | null;
    longitude: number | null;
    address: string | null;
    external_ref: string | null;
    note: string | null;
    device_count?: number;
    devices_down?: number;
}

export const siteKeys = {
    all: ['sites'] as const,
    list: () => [...siteKeys.all, 'list'] as const,
    detail: (id: number) => [...siteKeys.all, 'detail', id] as const,
};

/** A device as the geo map needs it - compact, so the map doesn't pull the full device list. */
export interface GeoDevice {
    id: number;
    name: string;
    status: 'up' | 'down' | 'unknown';
    site_id: number | null;
    lat: number;
    lng: number;
    /** Start of the still-open outage (ISO) - null when the device isn't currently down. */
    down_since: string | null;
    /**
     * Sonar customers whose CPE last attached through this AP. null = the AP was never polled
     * (unknown), so a down AP with no data must NOT be treated as "0 customers / non-urgent".
     * 0 = polled, no billed customers. Only APs carry this; non-AP devices are always null.
     */
    cust_count: number | null;
    /** Of cust_count, how many are currently down (their CPE not seen in the last poll). */
    cust_down: number | null;
    /**
     * Management IP, when the feed carries it. Optional: /geo/devices is deliberately compact and
     * doesn't select it today, so the site inspector renders the IP only if it ever appears -
     * never an empty column.
     */
    mgmt_ip?: string | null;
}

/**
 * Compact placed-device feed for the geo map (id/name/status/site/coords only) - a fraction of
 * the full /devices payload. Refetched periodically so site health + device dots stay current.
 */
export function useGeoDevices() {
    return useQuery({
        queryKey: ['geo', 'devices'],
        queryFn: async (): Promise<GeoDevice[]> => {
            const { data } = await apiClient.get<{ data: GeoDevice[] }>('/geo/devices');
            return data.data;
        },
        refetchInterval: 15000,
    });
}

/** A site-to-site backhaul link as coordinate pairs (from the OSS backbone topology). */
export interface Backhaul {
    id: number;
    media_type: string | null;
    a: [number, number]; // [lng, lat]
    b: [number, number];
}

/** Site-to-site backhaul links for the geo map (real topology, imported from the OSS). */
export function useBackhauls() {
    return useQuery({
        queryKey: ['geo', 'backhauls'],
        queryFn: async (): Promise<Backhaul[]> => {
            const { data } = await apiClient.get<{ data: Backhaul[] }>('/geo/backhauls');
            return data.data;
        },
        staleTime: 5 * 60 * 1000, // topology changes rarely
    });
}

/** One open Sonar ticket as the map's ticket layer needs it. */
export interface GeoTicket {
    ticket_id: number;
    /** Sonar deep-link (same template SonarTicketsSection uses). */
    url: string;
    subject: string | null;
    status: string | null;
    priority: string | null;
    account_name: string | null;
    /** Device the ticket is linked to, when it came in via a device rather than the site. */
    via_device: string | null;
}

/** A placed site carrying at least one open Sonar ticket. */
export interface GeoTicketSite {
    site_id: number;
    name: string;
    lat: number;
    lng: number;
    tickets: GeoTicket[];
}

/**
 * Sites with open Sonar tickets, for the toggleable ticket layer. Only fetched while the layer
 * is on; refetched periodically so the icons track the 15-min server-side ticket refresh.
 */
export function useGeoTickets(enabled: boolean) {
    return useQuery({
        queryKey: ['geo', 'tickets'],
        queryFn: async (): Promise<GeoTicketSite[]> => {
            const { data } = await apiClient.get<{ data: GeoTicketSite[] }>('/geo/tickets');
            return data.data;
        },
        enabled,
        refetchInterval: 2 * 60 * 1000,
    });
}

/** All sites with their device + down counts (the geo map's primary markers). */
export function useSites() {
    return useQuery({
        queryKey: siteKeys.list(),
        queryFn: async (): Promise<Site[]> => {
            const { data } = await apiClient.get<{ data: Site[] }>('/sites');
            return data.data;
        },
    });
}

/**
 * One site. The inspector normally resolves its site out of the already-loaded list; this is the
 * fallback for when it isn't loaded yet (deep link, cache miss), the same shape the device
 * inspector uses. Disabled until a site is selected.
 */
export function useSite(id: number | null) {
    return useQuery({
        queryKey: siteKeys.detail(id ?? 0),
        queryFn: async (): Promise<Site> => {
            const { data } = await apiClient.get<{ data: Site }>(`/sites/${id}`);
            return data.data;
        },
        enabled: id !== null,
    });
}

/**
 * The backhaul path from a site back to the fiber drain that feeds it (/geo/path).
 *
 * `data: null` with a `reason` is a legitimate answer - a site can have no links at all, or no
 * drain reachable because its region's fiber has not been imported yet. The UI must say which
 * rather than silently drawing nothing, because "we can't tell" and "there is no path" mean
 * very different things mid-outage.
 */
export interface BackhaulPathSite {
    id: number;
    name: string;
    lat: number | null;
    lng: number | null;
    is_drain: boolean;
}

export interface BackhaulPathLink {
    id: number;
    media_type: string | null;
    device_a: string | null;
    device_b: string | null;
}

export interface BackhaulPath {
    sites: BackhaulPathSite[];
    links: BackhaulPathLink[];
    hops: number;
    drain: { id: number; name: string } | null;
    truncated: boolean;
}

export type PathReason = 'no_site' | 'no_drain_reachable' | 'site_has_no_links';

export function useBackhaulPath(siteId: number | null) {
    return useQuery({
        queryKey: ['geo', 'path', siteId],
        enabled: siteId !== null,
        staleTime: 60_000,
        queryFn: async () => {
            const { data } = await apiClient.get<{ data: BackhaulPath | null; reason?: PathReason }>(
                `/geo/path?site_id=${siteId}`,
            );
            return data;
        },
    });
}
