import type { AnnotationSubject } from '../types';

/** The REST collection segment for a subject kind (`device` -> `devices`). */
export function subjectSegment(kind: AnnotationSubject['kind']): string {
    return `${kind}s`;
}

/** Base path for a subject's annotations, e.g. `/devices/12`. */
export function subjectPath(subject: AnnotationSubject): string {
    return `/${subjectSegment(subject.kind)}/${subject.id}`;
}

/**
 * The server's message for a failed call. Laravel puts it in `message`; a validation
 * failure puts the first field error in `errors`. Falls back to `fallback` so the UI
 * never renders "undefined".
 */
export function apiMessage(e: unknown, fallback: string): string {
    const data = (e as { response?: { data?: { message?: string; errors?: Record<string, string[]> } } })?.response?.data;
    const firstFieldError = data?.errors ? Object.values(data.errors)[0]?.[0] : undefined;
    return data?.message ?? firstFieldError ?? fallback;
}
