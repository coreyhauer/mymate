/**
 * Operator annotations that hang off a monitored object: free-text notes and linked
 * Sonar tickets. Devices, sites and links all carry them, so everything here is keyed
 * by an {@link AnnotationSubject} rather than a device id - one set of hooks and one
 * pair of components serve all three.
 */

/** What a note / ticket link is attached to. The API path segment is derived from `kind`. */
export type AnnotationSubject = { kind: 'device' | 'site' | 'link'; id: number };

/** One entry in a subject's threaded note log. */
export interface Note {
    id: number;
    body: string;
    author_id: number | null;
    /** Display name of the author; null when the account has since been removed. */
    author_name: string | null;
    created_at: string;
    updated_at: string;
    /**
     * Whether the current operator may edit/delete this note - computed server-side
     * (author or admin). Never re-derive it in the UI: the server is the authority.
     */
    editable: boolean;
}

/** A Sonar ticket linked to a subject, with the last-known ticket fields cached locally. */
export interface TicketLink {
    /** Id of the *link* row (what the unlink/refresh endpoints take), not the ticket. */
    id: number;
    ticket_id: number;
    subject: string | null;
    status: 'OPEN' | 'CLOSED' | 'PENDING_INTERNAL' | 'PENDING_EXTERNAL' | null;
    priority: 'LOW' | 'MEDIUM' | 'HIGH' | 'CRITICAL' | null;
    assignee_name: string | null;
    group_name: string | null;
    account_name: string | null;
    sonar_created_at: string | null;
    sonar_closed_at: string | null;
    /** Deep link into Sonar for this ticket. */
    url: string;
    cached_at: string | null;
    /** True when the cached fields could not be refreshed from Sonar - a hint, not an error. */
    stale: boolean;
    linked_by_name: string | null;
    created_at: string;
    editable: boolean;
}
