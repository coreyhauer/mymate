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
