import { useState } from 'react';
import { CheckCircle, XCircle, MapPin, ArrowRight } from '@phosphor-icons/react';
import { useSiteProposals, useDecideProposal, type SiteProposal } from '../api/proposals';

/**
 * The review queue for suggested device->site corrections.
 *
 * Every row shows its EVIDENCE, not a score. The detector has been wrong in four
 * distinct ways (link subnets that span two sites, hard-coded /29 offsets, similar
 * surnames belonging to different people, public IPs that carry no locality), so a
 * reviewer needs to see why a claim was made rather than trust a confidence label.
 *
 * Rejections matter as much as approvals: together they are the only honest basis for
 * measuring per-signal precision before any rule is ever promoted to applying itself.
 */

const STATUSES = ['open', 'approved', 'rejected', 'superseded'] as const;

function Evidence({ p }: { p: SiteProposal }) {
    const s = p.signals ?? {};
    return (
        <div className="flex flex-wrap items-center gap-1.5">
            {s.subnet && (
                <span
                    title={`${s.subnet.agree} of ${s.subnet.peers} neighbours in this device's ${s.subnet.mask} sit at the suggested site`}
                    className="rounded bg-sky-500/10 px-1.5 py-0.5 text-[10px] tabular-nums text-sky-300"
                >
                    {s.subnet.mask} {s.subnet.agree}/{s.subnet.peers}
                </span>
            )}
            {s.name?.agrees_with_suggestion && (
                <span
                    title="The device name also matches the suggested site"
                    className="rounded bg-emerald-500/10 px-1.5 py-0.5 text-[10px] text-emerald-300"
                >
                    name matches
                </span>
            )}
        </div>
    );
}

function Row({ p, canDecide }: { p: SiteProposal; canDecide: boolean }) {
    const decide = useDecideProposal();
    const [note, setNote] = useState('');
    const [noteOpen, setNoteOpen] = useState(false);
    const busy = decide.isPending;

    return (
        <div className="border-b border-white/[0.06] px-3 py-2.5 last:border-b-0 hover:bg-white/[0.02]">
            <div className="flex items-start gap-3">
                <span
                    title={p.confidence === 'high' ? 'Subnet neighbours and the device name agree' : 'Subnet neighbours only'}
                    className={`mt-0.5 shrink-0 rounded px-1.5 py-0.5 text-[10px] font-semibold uppercase ${
                        p.confidence === 'high' ? 'bg-amber-500/15 text-amber-300' : 'bg-white/[0.06] text-white/45'
                    }`}
                >
                    {p.confidence}
                </span>

                <div className="min-w-0 flex-1">
                    <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
                        <span className="truncate text-[12.5px] text-white/90">{p.device_name ?? `device #${p.device_id}`}</span>
                        {p.device_ip && <span className="font-mono text-[10px] text-white/35">{p.device_ip}</span>}
                    </div>

                    <div className="mt-1 flex flex-wrap items-center gap-1.5 text-[11.5px]">
                        <MapPin weight="fill" className="h-3 w-3 shrink-0 text-white/30" />
                        <span className="text-rose-300/80 line-through decoration-rose-300/40">{p.current_site_name ?? 'none'}</span>
                        <ArrowRight weight="bold" className="h-3 w-3 shrink-0 text-white/30" />
                        <span className="text-emerald-300">{p.suggested_site_name ?? 'none'}</span>
                    </div>

                    <div className="mt-1.5"><Evidence p={p} /></div>

                    {p.status !== 'open' && (
                        <div className="mt-1 text-[10.5px] text-white/40">
                            {p.status} {p.decided_by_name ? `by ${p.decided_by_name}` : ''}
                            {p.decision_note ? ` - "${p.decision_note}"` : ''}
                        </div>
                    )}

                    {noteOpen && (
                        <input
                            value={note}
                            onChange={(e) => setNote(e.target.value)}
                            placeholder="Why? (optional, recorded with the decision)"
                            className="mt-2 w-full rounded-md border border-white/10 bg-white/[0.04] px-2 py-1 text-[11.5px] text-white/85 outline-none focus:border-white/25"
                        />
                    )}
                </div>

                {canDecide && p.status === 'open' && (
                    <div className="flex shrink-0 items-center gap-1">
                        <button
                            type="button"
                            disabled={busy}
                            onClick={() => decide.mutate({ id: p.id, decision: 'approve', note: note || undefined })}
                            title="Apply this move. It is written to the audit log and can be reverted."
                            className="flex items-center gap-1 rounded-md bg-emerald-500/12 px-2 py-1 text-[11px] text-emerald-300 transition-colors hover:bg-emerald-500/20 disabled:opacity-40"
                        >
                            <CheckCircle weight="bold" className="h-3.5 w-3.5" /> Approve
                        </button>
                        <button
                            type="button"
                            disabled={busy}
                            onClick={() => {
                                if (!noteOpen) { setNoteOpen(true); return; }
                                decide.mutate({ id: p.id, decision: 'reject', note: note || undefined });
                            }}
                            title={noteOpen ? 'Reject this suggestion' : 'Reject - opens a box for an optional reason'}
                            className="flex items-center gap-1 rounded-md bg-white/[0.05] px-2 py-1 text-[11px] text-white/60 transition-colors hover:bg-rose-500/15 hover:text-rose-300 disabled:opacity-40"
                        >
                            <XCircle weight="bold" className="h-3.5 w-3.5" /> Reject
                        </button>
                    </div>
                )}
            </div>
        </div>
    );
}

export function ProposalsView() {
    const [status, setStatus] = useState<(typeof STATUSES)[number]>('open');
    const { data, isLoading } = useSiteProposals(status);
    const rows = data?.data ?? [];

    return (
        <div className="flex h-full flex-col overflow-hidden">
            <div className="flex flex-wrap items-center gap-2 border-b border-white/[0.06] px-3 py-2">
                <span className="text-[13px] font-medium text-white/85">Site placement review</span>
                <span className="text-[11px] text-white/40">
                    suggested corrections from subnet adjacency - nothing moves until you approve it
                </span>
                <div className="ml-auto flex items-center gap-1">
                    {STATUSES.map((s) => (
                        <button
                            key={s}
                            type="button"
                            onClick={() => setStatus(s)}
                            className={`rounded-md px-2 py-1 text-[11px] capitalize transition-colors ${
                                status === s ? 'bg-white/[0.10] text-white/90' : 'text-white/45 hover:bg-white/[0.05]'
                            }`}
                        >
                            {s}
                            {data?.counts?.[s] ? <span className="ml-1 tabular-nums text-white/35">{data.counts[s]}</span> : null}
                        </button>
                    ))}
                </div>
            </div>

            {data && !data.can_decide && (
                <div className="border-b border-white/[0.06] bg-amber-500/[0.06] px-3 py-1.5 text-[11px] text-amber-200/80">
                    You can review these suggestions but not apply them - moving devices between sites is restricted.
                </div>
            )}

            <div className="min-h-0 flex-1 overflow-y-auto">
                {isLoading && <div className="px-3 py-6 text-[12px] text-white/40">Loading…</div>}
                {!isLoading && rows.length === 0 && (
                    <div className="px-3 py-6 text-[12px] text-white/40">Nothing {status}.</div>
                )}
                {rows.map((p) => (
                    <Row key={p.id} p={p} canDecide={data?.can_decide ?? false} />
                ))}
            </div>
        </div>
    );
}
