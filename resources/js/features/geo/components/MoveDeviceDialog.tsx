import { useMemo, useState } from 'react';
import { createPortal } from 'react-dom';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { MagnifyingGlass, X } from '@phosphor-icons/react';
import { apiClient } from '../../../lib/apiClient';
import { useSites, type Site } from '../api/sites';

/**
 * Move one device to a different site.
 *
 * Deliberately a search-and-confirm rather than a dropdown: there are ~2,700 sites, and
 * the mistake this guards against is picking a neighbouring tower with a near-identical
 * name. Related sites really are distinct places - Anderson and Anderson14 are 12 km
 * apart, Marshall Flom and Flom 5 km - so the dialog prints the distance from the current
 * site next to each candidate. A move of 400 miles and a move of 400 metres should not
 * look the same at the moment of clicking.
 */

function distanceKm(a: Site | null, b: Site | null): number | null {
    if (!a?.latitude || !a?.longitude || !b?.latitude || !b?.longitude) return null;
    const R = 6371;
    const rad = (d: number) => (d * Math.PI) / 180;
    const dLat = rad(Number(b.latitude) - Number(a.latitude));
    const dLon = rad(Number(b.longitude) - Number(a.longitude));
    const h =
        Math.sin(dLat / 2) ** 2 +
        Math.cos(rad(Number(a.latitude))) * Math.cos(rad(Number(b.latitude))) * Math.sin(dLon / 2) ** 2;
    return 2 * R * Math.asin(Math.sqrt(h));
}

function fmtDistance(km: number | null): string {
    if (km === null) return '';
    const mi = km * 0.621371;
    return mi < 1 ? `${Math.round(mi * 5280)} ft away` : `${mi.toFixed(mi < 10 ? 1 : 0)} mi away`;
}

export function MoveDeviceDialog({
    deviceId,
    deviceName,
    currentSiteId,
    onClose,
}: {
    deviceId: number;
    deviceName: string;
    currentSiteId: number | null;
    onClose: () => void;
}) {
    const { data: sites } = useSites();
    const qc = useQueryClient();
    const [q, setQ] = useState('');
    const [picked, setPicked] = useState<Site | null>(null);

    const current = useMemo(
        () => (sites ?? []).find((s) => s.id === currentSiteId) ?? null,
        [sites, currentSiteId],
    );

    const matches = useMemo(() => {
        const needle = q.trim().toLowerCase();
        const pool = (sites ?? []).filter((s) => s.id !== currentSiteId);
        const hits = needle ? pool.filter((s) => s.name.toLowerCase().includes(needle)) : pool;
        return hits
            .map((s) => ({ site: s, km: distanceKm(current, s) }))
            .sort((a, b) => (a.km ?? 1e9) - (b.km ?? 1e9))
            .slice(0, 40);
    }, [sites, q, currentSiteId, current]);

    const move = useMutation({
        mutationFn: async (siteId: number) => {
            const { data } = await apiClient.patch(`/devices/${deviceId}`, { site_id: siteId });
            return data;
        },
        onSuccess: () => {
            qc.invalidateQueries({ queryKey: ['geo'] });
            qc.invalidateQueries({ queryKey: ['devices'] });
            qc.invalidateQueries({ queryKey: ['site-proposals'] });
            onClose();
        },
    });

    const failed = move.isError
        ? ((move.error as { response?: { status?: number } })?.response?.status === 403
            ? 'You are not permitted to move devices between sites.'
            : "Couldn't move the device.")
        : null;

    return createPortal(
        <div className="fixed inset-0 z-[1000] flex items-center justify-center bg-black/60 p-4" onClick={onClose}>
            <div
                onClick={(e) => e.stopPropagation()}
                className="flex max-h-[70vh] w-full max-w-md flex-col overflow-hidden rounded-xl border border-white/10 bg-[#12151a] shadow-2xl"
            >
                <div className="flex items-center gap-2 border-b border-white/[0.08] px-3 py-2.5">
                    <div className="min-w-0 flex-1">
                        <div className="truncate text-[12.5px] text-white/90">Move {deviceName}</div>
                        <div className="truncate text-[11px] text-white/40">
                            currently at {current?.name ?? 'no site'}
                        </div>
                    </div>
                    <button type="button" onClick={onClose} className="rounded p-1 text-white/40 hover:bg-white/[0.06] hover:text-white/80">
                        <X weight="bold" className="h-4 w-4" />
                    </button>
                </div>

                <div className="border-b border-white/[0.08] px-3 py-2">
                    <div className="flex items-center gap-2 rounded-md border border-white/10 bg-white/[0.04] px-2 py-1.5">
                        <MagnifyingGlass weight="bold" className="h-3.5 w-3.5 shrink-0 text-white/35" />
                        <input
                            autoFocus
                            value={q}
                            onChange={(e) => { setQ(e.target.value); setPicked(null); }}
                            placeholder="Search sites…"
                            className="w-full bg-transparent text-[12px] text-white/85 outline-none placeholder:text-white/25"
                        />
                    </div>
                </div>

                <div className="min-h-0 flex-1 overflow-y-auto">
                    {matches.map(({ site, km }) => (
                        <button
                            key={site.id}
                            type="button"
                            onClick={() => setPicked(site)}
                            className={`flex w-full items-center gap-2 px-3 py-1.5 text-left transition-colors ${
                                picked?.id === site.id ? 'bg-emerald-500/12' : 'hover:bg-white/[0.05]'
                            }`}
                        >
                            <span className="min-w-0 flex-1 truncate text-[12px] text-white/85">{site.name}</span>
                            <span className="shrink-0 text-[10px] tabular-nums text-white/35">{fmtDistance(km)}</span>
                        </button>
                    ))}
                    {matches.length === 0 && <div className="px-3 py-4 text-[11.5px] text-white/40">No sites match.</div>}
                </div>

                {failed && <div className="border-t border-white/[0.08] px-3 py-2 text-[11.5px] text-rose-300">{failed}</div>}

                <div className="flex items-center gap-2 border-t border-white/[0.08] px-3 py-2.5">
                    <div className="min-w-0 flex-1 truncate text-[11.5px] text-white/50">
                        {picked ? (
                            <>
                                Move to <span className="text-emerald-300">{picked.name}</span>
                                <span className="ml-1 text-white/35">{fmtDistance(distanceKm(current, picked))}</span>
                            </>
                        ) : (
                            'Pick a site'
                        )}
                    </div>
                    <button
                        type="button"
                        disabled={!picked || move.isPending}
                        onClick={() => picked && move.mutate(picked.id)}
                        className="rounded-md bg-emerald-500/15 px-3 py-1.5 text-[11.5px] text-emerald-300 transition-colors hover:bg-emerald-500/25 disabled:opacity-35"
                    >
                        {move.isPending ? 'Moving…' : 'Move device'}
                    </button>
                </div>
            </div>
        </div>,
        document.body,
    );
}
