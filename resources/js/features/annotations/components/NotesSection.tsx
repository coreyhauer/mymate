import { useEffect, useState, type KeyboardEvent } from 'react';
import { CircleNotch, NotePencil, PencilSimple, Trash } from '@phosphor-icons/react';
import { ConfirmDialog } from '../../../components/Dialog';
import { relativeTime } from '../../../lib/relativeTime';
import { pushToast } from '../../../lib/toast';
import { useCreateNote, useDeleteNote, useNotes, useUpdateNote } from '../api/notes';
import { apiMessage } from '../lib/subject';
import { actionBtn, cancelBtn, dangerIconBtn, fieldCls, iconBtn, quietLine, submitBtn } from '../lib/styles';
import type { AnnotationSubject, Note } from '../types';

/** Absolute timestamp for a tooltip - the exact time behind the "2h ago". */
function absolute(iso: string): string {
    const t = new Date(iso);
    return Number.isNaN(t.getTime()) ? iso : t.toLocaleString();
}

/** Cmd/Ctrl+Enter submits a composer textarea, matching the rest of the app's quick-entry feel. */
function isSubmitChord(e: KeyboardEvent<HTMLTextAreaElement>): boolean {
    return e.key === 'Enter' && (e.metaKey || e.ctrlKey);
}

/** Shared textarea + Cancel/Save footer used by both the "add" and the inline "edit" composer. */
function Composer({
    value,
    onChange,
    onSubmit,
    onCancel,
    busy,
    placeholder,
    confirmLabel,
    error,
}: {
    value: string;
    onChange: (v: string) => void;
    onSubmit: () => void;
    onCancel: () => void;
    busy: boolean;
    placeholder: string;
    confirmLabel: string;
    error?: string | null;
}) {
    return (
        <div className="space-y-1.5">
            <textarea
                autoFocus
                rows={3}
                value={value}
                onChange={(e) => onChange(e.target.value)}
                onKeyDown={(e) => {
                    if (isSubmitChord(e)) {
                        e.preventDefault();
                        onSubmit();
                    }
                }}
                placeholder={placeholder}
                className={`${fieldCls} resize-y leading-relaxed`}
            />
            {error && <p className="text-xs text-rose-400/90">{error}</p>}
            <div className="flex items-center justify-end gap-1">
                <span className="mr-auto text-[10px] text-white/25">⌘/Ctrl + ⏎ to save</span>
                <button type="button" onClick={onCancel} className={cancelBtn}>
                    Cancel
                </button>
                <button type="button" onClick={onSubmit} disabled={busy || value.trim() === ''} className={submitBtn}>
                    {busy ? 'Saving...' : confirmLabel}
                </button>
            </div>
        </div>
    );
}

/** One entry in the thread: byline, body (plain text, line breaks kept), and its own edit state. */
function NoteRow({ subject, note, onDelete }: { subject: AnnotationSubject; note: Note; onDelete: (n: Note) => void }) {
    const [editing, setEditing] = useState(false);
    const [draft, setDraft] = useState(note.body);
    const [error, setError] = useState<string | null>(null);
    const update = useUpdateNote();

    // A refetch can land while this row is open - re-seed the draft when the note itself changes.
    useEffect(() => {
        if (!editing) setDraft(note.body);
    }, [note.body, editing]);

    function save() {
        const body = draft.trim();
        if (body === '' || update.isPending) return;
        setError(null);
        update.mutate(
            { subject, noteId: note.id, body },
            {
                onSuccess: () => setEditing(false),
                onError: (e) => setError(apiMessage(e, "Couldn't save the note.")),
            },
        );
    }

    const edited = note.updated_at !== note.created_at;

    return (
        <div className="group space-y-1">
            <div className="flex items-baseline gap-1.5">
                <span className="min-w-0 truncate text-[11px] font-medium text-white/70">{note.author_name ?? 'Unknown'}</span>
                <span className="shrink-0 text-[10px] text-white/30" title={absolute(note.created_at)}>
                    {relativeTime(note.created_at)}
                    {edited ? ' · edited' : ''}
                </span>
                {note.editable && !editing && (
                    <span className="ml-auto flex shrink-0 items-center opacity-0 transition-opacity duration-200 group-hover:opacity-100 focus-within:opacity-100">
                        <button type="button" onClick={() => setEditing(true)} title="Edit note" className={iconBtn}>
                            <PencilSimple weight="bold" className="h-3 w-3" />
                        </button>
                        <button type="button" onClick={() => onDelete(note)} title="Delete note" className={dangerIconBtn}>
                            <Trash weight="bold" className="h-3 w-3" />
                        </button>
                    </span>
                )}
            </div>

            {editing ? (
                <Composer
                    value={draft}
                    onChange={setDraft}
                    onSubmit={save}
                    onCancel={() => {
                        setEditing(false);
                        setDraft(note.body);
                        setError(null);
                    }}
                    busy={update.isPending}
                    placeholder="Note..."
                    confirmLabel="Save"
                    error={error}
                />
            ) : (
                // Plain text only - operator notes are never rendered as HTML.
                <p className="text-xs leading-relaxed whitespace-pre-wrap break-words text-white/75">{note.body}</p>
            )}
        </div>
    );
}

/**
 * Threaded operator notes for a device / site / link - newest first, with inline edit and a
 * confirmed delete on the entries the server says this operator owns (`editable`). Kept
 * deliberately quiet: a failed load or an empty thread is one muted line, never a card that
 * shouts at someone triaging an outage.
 */
export function NotesSection({ subject }: { subject: AnnotationSubject }) {
    const { data: notes, isLoading, isError } = useNotes(subject);
    const create = useCreateNote();
    const del = useDeleteNote();
    const [composing, setComposing] = useState(false);
    const [draft, setDraft] = useState('');
    const [error, setError] = useState<string | null>(null);
    const [deleting, setDeleting] = useState<Note | null>(null);

    // Switching device/site/link mid-compose must not carry the draft onto the new subject.
    useEffect(() => {
        setComposing(false);
        setDraft('');
        setError(null);
        setDeleting(null);
    }, [subject.kind, subject.id]);

    function add() {
        const body = draft.trim();
        if (body === '' || create.isPending) return;
        setError(null);
        create.mutate(
            { subject, body },
            {
                onSuccess: () => {
                    setDraft('');
                    setComposing(false);
                },
                onError: (e) => setError(apiMessage(e, "Couldn't add the note.")),
            },
        );
    }

    const entries = notes ?? [];

    return (
        <div className="space-y-2.5">
            {isLoading ? (
                <p className={`flex items-center gap-1.5 ${quietLine}`}>
                    <CircleNotch weight="bold" className="h-3 w-3 animate-spin" /> Loading notes...
                </p>
            ) : isError ? (
                <p className={quietLine}>Notes couldn't be loaded.</p>
            ) : entries.length === 0 ? (
                <p className={quietLine}>No notes yet.</p>
            ) : (
                <div className="space-y-3">
                    {entries.map((n) => (
                        <NoteRow key={n.id} subject={subject} note={n} onDelete={setDeleting} />
                    ))}
                </div>
            )}

            {composing ? (
                <Composer
                    value={draft}
                    onChange={setDraft}
                    onSubmit={add}
                    onCancel={() => {
                        setComposing(false);
                        setDraft('');
                        setError(null);
                    }}
                    busy={create.isPending}
                    placeholder="What changed, what to watch for..."
                    confirmLabel="Add note"
                    error={error}
                />
            ) : (
                <button type="button" onClick={() => setComposing(true)} className={`${actionBtn} w-full justify-center`}>
                    <NotePencil weight="bold" className="h-3.5 w-3.5" /> Add note
                </button>
            )}

            {deleting && (
                <ConfirmDialog
                    title="Delete note"
                    icon={<Trash weight="light" className="h-5 w-5" />}
                    message="Delete this note? It can't be recovered."
                    confirmLabel="Delete"
                    tone="danger"
                    busy={del.isPending}
                    onConfirm={() =>
                        del.mutate(
                            { subject, noteId: deleting.id },
                            {
                                onSuccess: () => setDeleting(null),
                                onError: (e) => {
                                    pushToast({ title: "Couldn't delete the note", detail: apiMessage(e, 'Try again.'), tone: 'down' });
                                    setDeleting(null);
                                },
                            },
                        )
                    }
                    onClose={() => setDeleting(null)}
                />
            )}
        </div>
    );
}
