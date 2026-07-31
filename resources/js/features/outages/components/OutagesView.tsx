import { useState } from 'react';
import { BellSlash, Warning } from '@phosphor-icons/react';
import { useAckDevice, useOutages } from '../api/getOutages';
import { StatusDot } from '../../../components/StatusDot';
import { relativeTime } from '../../../lib/relativeTime';
import { selectDevice, setView } from '../../../lib/shellStore';

type Filter = 'all' | 'open' | 'closed';

function fmtDuration(s: number | null): string {
    if (s === null) return '-';
    if (s < 60) return `${s}s`;
    const m = Math.floor(s / 60);
    if (m < 60) return `${m}m ${s % 60}s`;
    const h = Math.floor(m / 60);
    return `${h}h ${m % 60}m`;
}

const pill = (active: boolean) =>
    `rounded-full px-3 py-1 text-xs font-medium transition-colors duration-200 ${
        active ? 'bg-white/10 text-white/90 ring-1 ring-white/15' : 'text-white/45 hover:text-white/75'
    }`;

export function OutagesView() {
    const [filter, setFilter] = useState<Filter>('all');
    const [showAcked, setShowAcked] = useState(false);
    const [showCpe, setShowCpe] = useState(false);
    const { data: outages, isLoading } = useOutages(filter === 'all' ? undefined : filter, showAcked, showCpe);
    const ack = useAckDevice();

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
                <header className="mb-6 flex items-center justify-between gap-3">
                    <div className="flex items-center gap-3">
                        <span className="grid h-9 w-9 place-items-center rounded-xl bg-amber-500/15 text-amber-300 ring-1 ring-amber-400/20">
                            <Warning weight="light" className="h-5 w-5" />
                        </span>
                        <div>
                            <h1 className="text-base font-bold tracking-tight text-white">Outages</h1>
                            <p className="text-xs text-white/40">Device down-events with durations, newest first</p>
                        </div>
                    </div>
                    <div className="flex items-center gap-2">
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
                        <table className="w-full min-w-[38rem] text-left text-sm">
                            <thead className="bg-white/[0.03] text-[11px] uppercase tracking-wide text-white/40">
                                <tr>
                                    <th className="px-4 py-2.5 font-medium">Device</th>
                                    <th className="px-4 py-2.5 font-medium">Started</th>
                                    <th className="px-4 py-2.5 font-medium">Duration</th>
                                    <th className="px-4 py-2.5 font-medium">State</th>
                                    <th className="px-4 py-2.5 text-right font-medium">Ack</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-white/[0.04]">
                                {outages.map((o) => (
                                    <tr
                                        key={o.id}
                                        onClick={() => open(o.device_id)}
                                        className={`cursor-pointer transition-colors duration-200 hover:bg-white/[0.03] ${
                                            o.acknowledged ? 'opacity-45' : ''
                                        }`}
                                    >
                                        <td className="px-4 py-2.5 font-medium text-white/85">
                                            <span className="flex items-center gap-2">
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
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>
        </div>
    );
}
