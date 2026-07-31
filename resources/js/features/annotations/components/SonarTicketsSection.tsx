import { useEffect, useState } from 'react';
import { ArrowClockwise, ArrowSquareOut, CircleNotch, LinkSimple, Trash } from '@phosphor-icons/react';
import { ConfirmDialog } from '../../../components/Dialog';
import { relativeTime } from '../../../lib/relativeTime';
import { pushToast } from '../../../lib/toast';
import { useLinkSonarTicket, useRefreshSonarTicket, useSonarTickets, useUnlinkSonarTicket } from '../api/sonarTickets';
import { apiMessage } from '../lib/subject';
import { actionBtn, cancelBtn, dangerIconBtn, fieldCls, iconBtn, quietLine, submitBtn } from '../lib/styles';
import type { AnnotationSubject, TicketLink } from '../types';

// Status drives the row's one strong colour: open work is emerald, waiting-on-someone is
// amber, closed goes quiet so a long history of resolved tickets doesn't compete with a live one.
const STATUS_PILL: Record<NonNullable<TicketLink['status']>, { label: string; cls: string }> = {
    OPEN: { label: 'Open', cls: 'bg-emerald-500/15 text-emerald-300 ring-emerald-400/25' },
    PENDING_INTERNAL: { label: 'Pending (int)', cls: 'bg-amber-500/15 text-amber-300 ring-amber-400/25' },
    PENDING_EXTERNAL: { label: 'Pending (ext)', cls: 'bg-amber-500/15 text-amber-300 ring-amber-400/25' },
    CLOSED: { label: 'Closed', cls: 'bg-white/5 text-white/40 ring-white/10' },
};

// Only the priorities that change what an operator does get a pill; LOW/MEDIUM are noise here.
const PRIORITY_PILL: Partial<Record<NonNullable<TicketLink['priority']>, { label: string; cls: string }>> = {
    HIGH: { label: 'High', cls: 'bg-amber-500/10 text-amber-300/90 ring-amber-400/20' },
    CRITICAL: { label: 'Critical', cls: 'bg-rose-500/15 text-rose-300 ring-rose-400/25' },
};

const pillCls = 'shrink-0 rounded-full px-1.5 py-0.5 text-[10px] font-semibold tracking-tight ring-1';

/** Assignee / group / account plus how old the ticket is - the "who has it, since when" line. */
function footerLine(t: TicketLink): string {
    const who = [t.account_name, t.assignee_name, t.group_name].filter((v): v is string => !!v && v.trim() !== '');
    const age = t.sonar_closed_at
        ? `closed ${relativeTime(t.sonar_closed_at)}`
        : t.sonar_created_at
          ? `opened ${relativeTime(t.sonar_created_at)}`
          : null;
    return [...who, age].filter((v): v is string => !!v).join(' · ');
}

function TicketRow({ subject, ticket, onUnlink }: { subject: AnnotationSubject; ticket: TicketLink; onUnlink: (t: TicketLink) => void }) {
    const refresh = useRefreshSonarTicket();
    const status = ticket.status ? STATUS_PILL[ticket.status] : null;
    const priority = ticket.priority ? PRIORITY_PILL[ticket.priority] : undefined;
    const footer = footerLine(ticket);

    return (
        <div className="group space-y-1 rounded-xl bg-white/[0.02] px-2.5 py-2 ring-1 ring-white/[0.06]">
            <div className="flex items-center gap-1.5">
                <a
                    href={ticket.url}
                    target="_blank"
                    rel="noopener noreferrer"
                    title="Open in Sonar"
                    className="flex shrink-0 items-center gap-1 font-mono text-xs font-semibold text-white/85 transition-colors duration-200 hover:text-emerald-300"
                >
                    #{ticket.ticket_id}
                    <ArrowSquareOut weight="bold" className="h-3 w-3 text-white/35" />
                </a>
                {status && <span className={`${pillCls} ${status.cls}`}>{status.label}</span>}
                {priority && <span className={`${pillCls} ${priority.cls}`}>{priority.label}</span>}

                <span className="ml-auto flex shrink-0 items-center gap-0.5">
                    {ticket.stale && (
                        <span
                            title={`Showing cached values - Sonar couldn't be reached${ticket.cached_at ? ` (cached ${relativeTime(ticket.cached_at)})` : ''}.`}
                            className="text-[10px] text-white/30"
                        >
                            cached
                        </span>
                    )}
                    <button
                        type="button"
                        onClick={() =>
                            refresh.mutate(
                                { subject, linkId: ticket.id },
                                { onError: (e) => pushToast({ title: "Couldn't refresh the ticket", detail: apiMessage(e, 'Sonar may be unreachable.'), tone: 'down' }) },
                            )
                        }
                        disabled={refresh.isPending}
                        title="Refresh from Sonar"
                        className={iconBtn}
                    >
                        <ArrowClockwise weight="bold" className={`h-3 w-3 ${refresh.isPending ? 'animate-spin' : ''}`} />
                    </button>
                    {ticket.editable && (
                        <button type="button" onClick={() => onUnlink(ticket)} title="Unlink ticket" className={dangerIconBtn}>
                            <Trash weight="bold" className="h-3 w-3" />
                        </button>
                    )}
                </span>
            </div>

            <p className="text-xs leading-relaxed break-words text-white/75">{ticket.subject ?? 'No subject'}</p>
            {footer !== '' && <p className="truncate text-[10px] text-white/35">{footer}</p>}
        </div>
    );
}

/**
 * Sonar tickets linked to a device / site / link. The composer takes whatever an operator
 * pasted (bare id, `#1234`, or a Sonar URL) and lets the server parse it; its 404 / 409 / 503
 * message is shown *inline* with the text left intact, because the fix is almost always to
 * correct the reference rather than to start over.
 */
export function SonarTicketsSection({ subject }: { subject: AnnotationSubject }) {
    const { data: tickets, isLoading, isError } = useSonarTickets(subject);
    const link = useLinkSonarTicket();
    const unlink = useUnlinkSonarTicket();
    const [composing, setComposing] = useState(false);
    const [reference, setReference] = useState('');
    const [error, setError] = useState<string | null>(null);
    const [unlinking, setUnlinking] = useState<TicketLink | null>(null);

    useEffect(() => {
        setComposing(false);
        setReference('');
        setError(null);
        setUnlinking(null);
    }, [subject.kind, subject.id]);

    function submit() {
        const value = reference.trim();
        if (value === '' || link.isPending) return;
        setError(null);
        link.mutate(
            { subject, reference: value },
            {
                onSuccess: () => {
                    setReference('');
                    setComposing(false);
                },
                // Keep the composer open and the text as typed - the operator corrects it in place.
                onError: (e) => setError(apiMessage(e, "Couldn't link that ticket.")),
            },
        );
    }

    const rows = tickets ?? [];

    return (
        <div className="space-y-2.5">
            {isLoading ? (
                <p className={`flex items-center gap-1.5 ${quietLine}`}>
                    <CircleNotch weight="bold" className="h-3 w-3 animate-spin" /> Loading tickets...
                </p>
            ) : isError ? (
                <p className={quietLine}>Sonar tickets couldn't be loaded.</p>
            ) : rows.length === 0 ? (
                <p className={quietLine}>No tickets linked.</p>
            ) : (
                <div className="space-y-1.5">
                    {rows.map((t) => (
                        <TicketRow key={t.id} subject={subject} ticket={t} onUnlink={setUnlinking} />
                    ))}
                </div>
            )}

            {composing ? (
                <div className="space-y-1.5">
                    <input
                        autoFocus
                        value={reference}
                        onChange={(e) => setReference(e.target.value)}
                        onKeyDown={(e) => {
                            if (e.key === 'Enter') {
                                e.preventDefault();
                                submit();
                            }
                        }}
                        placeholder="Ticket id, #id, or Sonar URL"
                        className={fieldCls}
                    />
                    {error && <p className="text-xs text-rose-400/90">{error}</p>}
                    <div className="flex items-center justify-end gap-1">
                        <button
                            type="button"
                            onClick={() => {
                                setComposing(false);
                                setReference('');
                                setError(null);
                            }}
                            className={cancelBtn}
                        >
                            Cancel
                        </button>
                        <button type="button" onClick={submit} disabled={link.isPending || reference.trim() === ''} className={submitBtn}>
                            {link.isPending ? 'Linking...' : 'Link ticket'}
                        </button>
                    </div>
                </div>
            ) : (
                <button type="button" onClick={() => setComposing(true)} className={`${actionBtn} w-full justify-center`}>
                    <LinkSimple weight="bold" className="h-3.5 w-3.5" /> Link ticket
                </button>
            )}

            {unlinking && (
                <ConfirmDialog
                    title="Unlink ticket"
                    icon={<Trash weight="light" className="h-5 w-5" />}
                    message={
                        <>
                            Unlink Sonar ticket <span className="font-semibold text-white/85">#{unlinking.ticket_id}</span>? The ticket itself stays in
                            Sonar - only the link from here is removed.
                        </>
                    }
                    confirmLabel="Unlink"
                    tone="danger"
                    busy={unlink.isPending}
                    onConfirm={() =>
                        unlink.mutate(
                            { subject, linkId: unlinking.id },
                            {
                                onSuccess: () => setUnlinking(null),
                                onError: (e) => {
                                    pushToast({ title: "Couldn't unlink the ticket", detail: apiMessage(e, 'Try again.'), tone: 'down' });
                                    setUnlinking(null);
                                },
                            },
                        )
                    }
                    onClose={() => setUnlinking(null)}
                />
            )}
        </div>
    );
}
