import { useMemo, useState } from 'react';
import { ArrowDown, ArrowUp, BellSlash, CheckCircle, GlobeHemisphereWest, Warning } from '@phosphor-icons/react';
import { useAckDevice, useOutages } from '../api/getOutages';
import { StatusDot } from '../../../components/StatusDot';
import { relativeTime } from '../../../lib/relativeTime';
import { focusDeviceOnGeo, selectDevice, setView } from '../../../lib/shellStore';
import type { Outage } from '../../../types';

type Filter = 'all' | 'open' | 'closed';

/**
 * Sortable outage table (NOC request, 2026-08-04): the Dude's down-list workflow needs
 * sorting by any column - especially site, IP, name and the device's note - plus a
 * "mark all seen" watermark so newly-arriving outages stand out while a sort is active,
 * and a per-row jump straight to the geo map.
 */
type SortCol = 'device' | 'ip' | 'site' | 'note' | 'started' | 'state';
type SortDir = 'asc' | 'desc';

function fmtDuration(s: number | null): string {
    if (s === null) return '-';
    if (s < 60) return `${s}s`;
    const m = Math.floor(s / 60);
    if (m < 60) return `${m}m ${s % 60}s`;
    const h = Math.floor(m / 60);
    return `${h}h ${m % 60}m`;
}

/** Octet-aware IP ordering, so 10.9.x sorts before 10.80.x. */
function ipKey(ip: string | null): string {
    if (!ip) return '';
    return ip.split('.').map((o) => o.padStart(3, '0')).join('.');
}

/**
 * The "seen" watermark - per-browser (localStorage), the same shape as the Dude's
 * select-to-see: Mark all seen stamps the current open outages; anything that starts
 * after the stamp renders highlighted until the next mark.
 */
const SEEN_KEY = 'mymate.outages.seen.v1';

function loadSeen(): Set<number> {
    try {
        const raw = window.localStorage.getItem(SEEN_KEY);
        return raw ? new Set(JSON.parse(raw) as number[]) : new Set();
    } catch {
        return new Set();
    }
}

const pill = (active: boolean) =>
    `rounded-full px-3 py-1 text-xs font-medium transition-colors duration-200 ${
        active ? 'bg-white/10 text-white/90 ring-1 ring-white/15' : 'text-white/45 hover:text-white/75'
    }`;

function SortHeader({
    col, label, sort, dir, onSort, className = '',
}: {
    col: SortCol; label: string; sort: SortCol; dir: SortDir; onSort: (c: SortCol) => void; className?: string;
}) {
    const active = sort === col;
    return (
        <th className={`px-4 py-2.5 font-medium ${className}`}>
            <button
                type="button"
                onClick={() => onSort(col)}
                className={`inline-flex items-center gap-1 uppercase tracking-wide transition-colors duration-200 ${
                    active ? 'text-white/85' : 'text-white/40 hover:text-white/70'
                }`}
            >
                {label}
                {active && (dir === 'asc' ? <ArrowUp weight="bold" className="h-3 w-3" /> : <ArrowDown weight="bold" className="h-3 w-3" />)}
            </button>
        </th>
    );
}

export function OutagesView() {
    const [filter, setFilter] = useState<Filter>('all');
    const [showAcked, setShowAcked] = useState(false);
    const [showCpe, setShowCpe] = useState(false);
    const [sort, setSort] = useState<SortCol>('started');
    const [dir, setDir] = useState<SortDir>('desc');
    const [seen, setSeen] = useState<Set<number>>(() => (typeof window === 'undefined' ? new Set() : loadSeen()));
    const { data: outages, isLoading } = useOutages(filter === 'all' ? undefined : filter, showAcked, showCpe);
    const ack = useAckDevice();

    function onSort(c: SortCol) {
        if (sort === c) {
            setDir((d) => (d === 'asc' ? 'desc' : 'asc'));
        } else {
            setSort(c);
            setDir(c === 'started' ? 'desc' : 'asc');
        }
    }

    const sorted = useMemo(() => {
        const rows = [...(outages ?? [])];
        const mul = dir === 'asc' ? 1 : -1;
        const key: Record<SortCol, (o: Outage) => string | number> = {
            device: (o) => (o.device_name ?? '').toLowerCase(),
            ip: (o) => ipKey(o.mgmt_ip),
            site: (o) => (o.site_name ?? '￿').toLowerCase(), // siteless rows sink to the bottom
            note: (o) => (o.device_note ?? '￿').toLowerCase(),
            started: (o) => o.started_at,
            state: (o) => (o.ongoing ? 0 : 1),
        };
        const k = key[sort];
        rows.sort((a, b) => {
            const ka = k(a), kb = k(b);
            if (ka < kb) return -1 * mul;
            if (ka > kb) return 1 * mul;
            return a.started_at < b.started_at ? 1 : -1; // stable tiebreak: newest first
        });
        return rows;
    }, [outages, sort, dir]);

    const openOutages = useMemo(() => (outages ?? []).filter((o) => o.ongoing), [outages]);
    const newCount = openOutages.filter((o) => !seen.has(o.id)).length;

    function markAllSeen() {
        // The stamp is the CURRENT set of open outages (not a union with history):
        // resolved ones age out of it naturally, and the next new arrival stands alone.
        const next = new Set(openOutages.map((o) => o.id));
        setSeen(next);
        try {
            window.localStorage.setItem(SEEN_KEY, JSON.stringify([...next]));
        } catch {
            /* private browsing etc - highlight still works for this session */
        }
    }

    function open(deviceId: number) {
        selectDevice(deviceId);
        setView('map');
    }

    function toggleAck(deviceId: number, acked: boolean) {
        ack.mutate({ deviceId, acked });
    }

    return (
        <div className="h-full overflow-y-auto p-6 lg:p-8">
            <div className="animate-rise">
                <header className="mb-6 flex flex-wrap items-center justify-between gap-3">
                    <div className="flex items-center gap-3">
                        <span className="grid h-9 w-9 place-items-center rounded-xl bg-amber-500/15 text-amber-300 ring-1 ring-amber-400/20">
                            <Warning weight="light" className="h-5 w-5" />
                        </span>
                        <div>
                            <h1 className="text-base font-bold tracking-tight text-white">Outages</h1>
                            <p className="text-xs text-white/40">Device down-events with durations - click a column to sort</p>
                        </div>
                        {newCount > 0 && (
                            <span className="rounded-full bg-amber-500/15 px-2.5 py-1 text-xs font-semibold tabular-nums text-amber-300 ring-1 ring-amber-400/25">
                                {newCount} new
                            </span>
                        )}
                    </div>
                    <div className="flex items-center gap-2">
                        <button
                            onClick={markAllSeen}
                            disabled={openOutages.length === 0}
                            title="Stamp every currently-down device as seen - anything that goes down after this stands out as NEW"
                            className="inline-flex items-center gap-1.5 rounded-full bg-white/5 px-3 py-1 text-xs font-medium text-white/70 ring-1 ring-white/10 transition-colors duration-200 hover:bg-white/10 hover:text-white/90 disabled:opacity-40"
                        >
                            <CheckCircle weight="light" className="h-4 w-4" />
                            Mark all seen
                        </button>
                        <button
                            onClick={() => setShowCpe((v) => !v)}
                            className={pill(showCpe)}
                            title="Customer fiber ONUs / monitored customer routers are hidden by default so the list stays infrastructure-only"
                        >
                            {showCpe ? 'Showing ONUs' : 'Show ONUs'}
                        </button>
                        <button
                            onClick={() => setShowAcked((v) => !v)}
                            className={pill(showAcked)}
                            title="Acknowledged devices (suspended/cancelled/polling-disabled) are hidden by default"
                        >
                            {showAcked ? 'Showing acked' : 'Show acked'}
                        </button>
                        <div className="flex items-center gap-0.5 rounded-full bg-white/5 p-0.5 ring-1 ring-white/10">
                            {(['all', 'open', 'closed'] as Filter[]).map((f) => (
                                <button key={f} onClick={() => setFilter(f)} className={pill(filter === f)}>
                                    {f === 'all' ? 'All' : f === 'open' ? 'Ongoing' : 'Resolved'}
                                </button>
                            ))}
                        </div>
                    </div>
                </header>

                {isLoading ? (
                    <p className="px-1 text-sm text-white/40">Loading...</p>
                ) : !outages || outages.length === 0 ? (
                    <div className="grid place-items-center rounded-2xl bg-white/[0.02] py-16 text-center ring-1 ring-white/[0.06]">
                        <p className="text-sm text-white/45">No outages recorded{filter !== 'all' ? ` (${filter})` : ''}.</p>
                    </div>
                ) : (
                    <div className="overflow-x-auto rounded-2xl ring-1 ring-white/[0.06]">
                        <table className="w-full min-w-[62rem] text-left text-sm">
                            <thead className="bg-white/[0.03] text-[11px] text-white/40">
                                <tr>
                                    <SortHeader col="device" label="Device" sort={sort} dir={dir} onSort={onSort} />
                                    <SortHeader col="ip" label="IP" sort={sort} dir={dir} onSort={onSort} />
                                    <SortHeader col="site" label="Site" sort={sort} dir={dir} onSort={onSort} />
                                    <SortHeader col="note" label="Note" sort={sort} dir={dir} onSort={onSort} />
                                    <SortHeader col="started" label="Started" sort={sort} dir={dir} onSort={onSort} />
                                    <th className="px-4 py-2.5 font-medium uppercase tracking-wide">Duration</th>
                                    <SortHeader col="state" label="State" sort={sort} dir={dir} onSort={onSort} />
                                    <th className="px-4 py-2.5 text-right font-medium uppercase tracking-wide">Actions</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-white/[0.04]">
                                {sorted.map((o) => {
                                    const isNew = o.ongoing && !seen.has(o.id);
                                    return (
                                        <tr
                                            key={o.id}
                                            onClick={() => open(o.device_id)}
                                            className={`cursor-pointer transition-colors duration-200 hover:bg-white/[0.03] ${
                                                o.acknowledged ? 'opacity-45' : ''
                                            } ${isNew ? 'bg-amber-500/[0.06]' : ''}`}
                                        >
                                            <td className="px-4 py-2.5 font-medium text-white/85">
                                                <span className="flex items-center gap-2">
                                                    {isNew && (
                                                        <span className="rounded-full bg-amber-500/20 px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wide text-amber-300">
                                                            new
                                                        </span>
                                                    )}
                                                    {o.device_name ?? `device ${o.device_id}`}
                                                    {o.is_cpe && (
                                                        <span className="rounded-full bg-cyan-500/10 px-1.5 py-0.5 text-[10px] font-medium uppercase tracking-wide text-cyan-300/70">
                                                            onu
                                                        </span>
                                                    )}
                                                    {o.acknowledged && (
                                                        <span className="rounded-full bg-white/10 px-1.5 py-0.5 text-[10px] font-medium uppercase tracking-wide text-white/50">
                                                            acked
                                                        </span>
                                                    )}
                                                </span>
                                            </td>
                                            <td className="px-4 py-2.5 font-mono text-xs tabular-nums text-white/55">{o.mgmt_ip ?? '-'}</td>
                                            <td className="max-w-[14rem] truncate px-4 py-2.5 text-white/60" title={o.site_name ?? undefined}>
                                                {o.site_name ?? '-'}
                                            </td>
                                            <td className="max-w-[16rem] truncate px-4 py-2.5 text-white/50" title={o.device_note ?? undefined}>
                                                {o.device_note ?? ''}
                                            </td>
                                            <td className="px-4 py-2.5 tabular-nums text-white/55">{relativeTime(o.started_at)}</td>
                                            <td className="px-4 py-2.5 tabular-nums text-white/70">
                                                {o.ongoing ? 'ongoing' : fmtDuration(o.duration_s)}
                                            </td>
                                            <td className="px-4 py-2.5">
                                                <span className="flex items-center gap-1.5 text-white/60">
                                                    <StatusDot status={o.ongoing ? 'down' : 'up'} />
                                                    {o.ongoing ? 'Down' : 'Recovered'}
                                                </span>
                                            </td>
                                            <td className="px-4 py-2.5 text-right">
                                                <span className="inline-flex items-center gap-1.5">
                                                    <button
                                                        onClick={(e) => {
                                                            e.stopPropagation();
                                                            focusDeviceOnGeo(o.device_id);
                                                        }}
                                                        title="Jump to this device on the geo map"
                                                        className="inline-flex items-center gap-1 rounded-md px-2 py-1 text-xs font-medium text-white/55 ring-1 ring-white/10 transition-colors duration-200 hover:bg-white/5 hover:text-white/85"
                                                    >
                                                        <GlobeHemisphereWest weight="light" className="h-3.5 w-3.5" />
                                                        Geo
                                                    </button>
                                                    <button
                                                        onClick={(e) => {
                                                            e.stopPropagation();
                                                            toggleAck(o.device_id, !o.acknowledged);
                                                        }}
                                                        disabled={ack.isPending}
                                                        title={o.acknowledged ? 'Clear acknowledgement' : 'Acknowledge - hide from actionable outages'}
                                                        className="inline-flex items-center gap-1 rounded-md px-2 py-1 text-xs font-medium text-white/55 ring-1 ring-white/10 transition-colors duration-200 hover:bg-white/5 hover:text-white/85 disabled:opacity-40"
                                                    >
                                                        <BellSlash weight="light" className="h-3.5 w-3.5" />
                                                        {o.acknowledged ? 'Unack' : 'Ack'}
                                                    </button>
                                                </span>
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>
        </div>
    );
}
