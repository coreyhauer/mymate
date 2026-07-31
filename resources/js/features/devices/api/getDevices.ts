import { useQuery } from '@tanstack/react-query';
import { apiClient } from '../../../lib/apiClient';
import { BACKUP_IN_PROGRESS, UPGRADE_IN_PROGRESS, type Device } from '../../../types';

// Query keys for the devices feature (co-located with the queries).
export const deviceKeys = {
    all: ['devices'] as const,
    list: () => [...deviceKeys.all, 'list'] as const,
    stats: () => [...deviceKeys.all, 'stats'] as const,
    detail: (id: number) => [...deviceKeys.all, 'detail', id] as const,
};

export interface DeviceStats {
    up: number;
    down: number;
    unknown: number;
    total: number;
}

/**
 * Lightweight up/down/unknown tallies for the top bar (a single GROUP BY server-side), so the
 * header is instant and independent of the full (~35 MB) device list load. Polled on the ping
 * cadence so it tracks status changes.
 */
export function useDeviceStats() {
    return useQuery({
        queryKey: deviceKeys.stats(),
        queryFn: async (): Promise<DeviceStats> => {
            const { data } = await apiClient.get<{ data: DeviceStats }>('/devices/stats');
            return data.data;
        },
        refetchInterval: 10000,
    });
}

/**
 * One device by id.
 *
 * The inspector used to resolve its selection purely by searching the full ~36 MB device list,
 * so selecting a device the list hadn't delivered yet (or didn't contain) silently fell back to
 * the "nothing selected" panel - which is why clicking a device on the GEO map could land you on
 * Map tools: the geo feed knows the device, the big list hadn't arrived. This gives the inspector
 * a direct lookup that doesn't depend on that list at all.
 *
 * `enabled` is false for a null id so deselecting doesn't fire a request.
 */
export function useDevice(id: number | null) {
    return useQuery({
        queryKey: deviceKeys.detail(id ?? 0),
        queryFn: async (): Promise<Device> => {
            const { data } = await apiClient.get<{ data: Device }>(`/devices/${id}`);
            return data.data;
        },
        enabled: id !== null,
        staleTime: 15000,
    });
}

async function fetchDevices(): Promise<Device[]> {
    const { data } = await apiClient.get<{ data: Device[] }>('/devices');
    return data.data;
}

export function useDevices() {
    return useQuery({
        queryKey: deviceKeys.list(),
        queryFn: fetchDevices,
        // While any device is mid-upgrade or mid-backup, poll so the spinner advances + resolves.
        refetchInterval: (query) =>
            (query.state.data ?? []).some(
                (d) =>
                    (d.upgrade_status && UPGRADE_IN_PROGRESS.has(d.upgrade_status)) ||
                    (d.backup_status && BACKUP_IN_PROGRESS.has(d.backup_status)),
            )
                ? 3000
                : false,
    });
}
