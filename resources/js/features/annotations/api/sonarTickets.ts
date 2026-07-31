import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { apiClient } from '../../../lib/apiClient';
import { subjectPath } from '../lib/subject';
import type { AnnotationSubject, TicketLink } from '../types';

export const sonarTicketKeys = {
    all: ['annotations', 'sonar-tickets'] as const,
    list: (subject: AnnotationSubject) => ['annotations', 'sonar-tickets', subject.kind, subject.id] as const,
};

async function fetchSonarTickets(subject: AnnotationSubject): Promise<TicketLink[]> {
    const { data } = await apiClient.get<{ data: TicketLink[] }>(`${subjectPath(subject)}/sonar-tickets`);
    return data.data;
}

/** Sonar tickets linked to a subject. Disabled until a subject is chosen. */
export function useSonarTickets(subject: AnnotationSubject | null) {
    return useQuery({
        queryKey: subject === null ? [...sonarTicketKeys.all, 'pending'] : sonarTicketKeys.list(subject),
        queryFn: () => fetchSonarTickets(subject as AnnotationSubject),
        enabled: subject !== null,
    });
}

/**
 * Link a Sonar ticket to a subject. `reference` is whatever the operator pasted - a bare
 * number, `#1234`, or a full Sonar URL; the server parses it and answers 404 (no such
 * ticket), 409 (already linked here) or 503 (Sonar unreachable) with a message worth showing.
 */
export function useLinkSonarTicket() {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: async ({ subject, reference }: { subject: AnnotationSubject; reference: string }): Promise<TicketLink> => {
            const { data } = await apiClient.post<{ data: TicketLink }>(`${subjectPath(subject)}/sonar-tickets`, { reference });
            return data.data;
        },
        onSuccess: (_link, { subject }) => qc.invalidateQueries({ queryKey: sonarTicketKeys.list(subject) }),
    });
}

/** Remove a ticket link (the Sonar ticket itself is untouched). */
export function useUnlinkSonarTicket() {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: async ({ linkId }: { subject: AnnotationSubject; linkId: number }): Promise<void> => {
            await apiClient.delete(`/sonar-tickets/${linkId}`);
        },
        onSuccess: (_void, { subject }) => qc.invalidateQueries({ queryKey: sonarTicketKeys.list(subject) }),
    });
}

/** Re-pull one ticket's fields from Sonar (what clears a `stale` row). */
export function useRefreshSonarTicket() {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: async ({ linkId }: { subject: AnnotationSubject; linkId: number }): Promise<TicketLink> => {
            const { data } = await apiClient.post<{ data: TicketLink }>(`/sonar-tickets/${linkId}/refresh`);
            return data.data;
        },
        onSuccess: (_link, { subject }) => qc.invalidateQueries({ queryKey: sonarTicketKeys.list(subject) }),
    });
}
