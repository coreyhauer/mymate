import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { apiClient } from '../../../lib/apiClient';
import { subjectPath } from '../lib/subject';
import type { AnnotationSubject, Note } from '../types';

export const noteKeys = {
    all: ['annotations', 'notes'] as const,
    /** One subject's thread. Keyed by primitives so an inline `{kind,id}` literal is stable. */
    list: (subject: AnnotationSubject) => ['annotations', 'notes', subject.kind, subject.id] as const,
};

async function fetchNotes(subject: AnnotationSubject): Promise<Note[]> {
    const { data } = await apiClient.get<{ data: Note[] }>(`${subjectPath(subject)}/notes`);
    return data.data;
}

/** A subject's note thread, newest first (server-ordered). Disabled until a subject is chosen. */
export function useNotes(subject: AnnotationSubject | null) {
    return useQuery({
        queryKey: subject === null ? [...noteKeys.all, 'pending'] : noteKeys.list(subject),
        queryFn: () => fetchNotes(subject as AnnotationSubject),
        enabled: subject !== null,
    });
}

/** Add a note to a subject. */
export function useCreateNote() {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: async ({ subject, body }: { subject: AnnotationSubject; body: string }): Promise<Note> => {
            const { data } = await apiClient.post<{ data: Note }>(`${subjectPath(subject)}/notes`, { body });
            return data.data;
        },
        onSuccess: (_note, { subject }) => qc.invalidateQueries({ queryKey: noteKeys.list(subject) }),
    });
}

/**
 * Edit a note's text. The note is addressed globally (`/notes/{id}`), but the subject
 * rides along so the right thread is refetched afterwards.
 */
export function useUpdateNote() {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: async ({ noteId, body }: { subject: AnnotationSubject; noteId: number; body: string }): Promise<Note> => {
            const { data } = await apiClient.patch<{ data: Note }>(`/notes/${noteId}`, { body });
            return data.data;
        },
        onSuccess: (_note, { subject }) => qc.invalidateQueries({ queryKey: noteKeys.list(subject) }),
    });
}

/** Delete a note. */
export function useDeleteNote() {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: async ({ noteId }: { subject: AnnotationSubject; noteId: number }): Promise<void> => {
            await apiClient.delete(`/notes/${noteId}`);
        },
        onSuccess: (_void, { subject }) => qc.invalidateQueries({ queryKey: noteKeys.list(subject) }),
    });
}
