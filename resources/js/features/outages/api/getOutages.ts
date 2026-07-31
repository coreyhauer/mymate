import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { apiClient } from '../../../lib/apiClient';
import type { Outage } from '../../../types';

export const outageKeys = {
    all: ['outages'] as const,
    list: (state: string, includeAcked: boolean, includeCpe: boolean) =>
        ['outages', state, includeAcked, includeCpe] as const,
};

async function fetchOutages(state?: 'open' | 'closed', includeAcked = false, includeCpe = false): Promise<Outage[]> {
    const params: Record<string, string> = {};
    if (state) params.state = state;
    if (includeAcked) params.include_acked = '1';
    if (includeCpe) params.include_cpe = '1';
    const { data } = await apiClient.get<{ data: Outage[] }>('/outages', { params });
    return data.data;
}

/** The outage timeline, optionally filtered to open/closed. Acknowledged (known-down)
 *  devices are hidden unless `includeAcked`; customer CPE (fiber ONUs, device_type=ont)
 *  is hidden unless `includeCpe`, keeping the main list infrastructure-only. Polls so it
 *  stays fresh as devices go down/recover (outages aren't pushed over the socket). */
export function useOutages(state?: 'open' | 'closed', includeAcked = false, includeCpe = false) {
    return useQuery({
        queryKey: outageKeys.list(state ?? 'all', includeAcked, includeCpe),
        queryFn: () => fetchOutages(state, includeAcked, includeCpe),
        refetchInterval: 15000,
    });
}

/** Acknowledge / clear a device straight from the outage row. Invalidates the outage
 *  queries (list + nav badge, which read the same key) on success. */
export function useAckDevice() {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: ({ deviceId, acked, note }: { deviceId: number; acked: boolean; note?: string }) =>
            acked
                ? apiClient.post(`/devices/${deviceId}/ack`, note ? { note } : {})
                : apiClient.delete(`/devices/${deviceId}/ack`),
        onSuccess: () => {
            qc.invalidateQueries({ queryKey: outageKeys.all });
        },
    });
}
