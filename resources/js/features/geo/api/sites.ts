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
};

/** A device as the geo map needs it - compact, so the map doesn't pull the full device list. */
export interface GeoDevice {
    id: number;
    name: string;
    status: 'up' | 'down' | 'unknown';
    site_id: number | null;
    lat: number;
    lng: number;
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
