import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { apiClient } from '../../../lib/apiClient';

/**
 * Suggested device->site corrections and the decisions taken on them.
 *
 * Reading is open to any operator; deciding is gated server-side, and the list endpoint
 * reports `can_decide` so the UI can show the queue read-only rather than offering
 * buttons that would 403.
 */

export interface ProposalSignals {
    subnet?: { mask: string; agree: number; peers: number };
    name?: { agrees_with_suggestion: boolean };
}

export interface SiteProposal {
    id: number;
    device_id: number;
    device_name: string | null;
    device_ip: string | null;
    current_site_id: number | null;
    current_site_name: string | null;
    suggested_site_id: number | null;
    suggested_site_name: string | null;
    confidence: 'high' | 'medium';
    rationale: string;
    signals: ProposalSignals | null;
    status: string;
    decided_by_name: string | null;
    decided_at: string | null;
    decision_note: string | null;
}

export interface ProposalsPayload {
    data: SiteProposal[];
    counts: Record<string, number>;
    can_decide: boolean;
}

export const proposalKeys = {
    all: ['site-proposals'] as const,
    list: (status: string) => ['site-proposals', status] as const,
};

export function useSiteProposals(status = 'open') {
    return useQuery({
        queryKey: proposalKeys.list(status),
        queryFn: async (): Promise<ProposalsPayload> => {
            const { data } = await apiClient.get<ProposalsPayload>('/site-proposals', { params: { status } });
            return data;
        },
        staleTime: 30_000,
    });
}

/**
 * Approve or reject. Both invalidate the device caches as well as the queue: an approval
 * moves a device, so the map and any open site popup are stale the moment it lands.
 */
export function useDecideProposal() {
    const qc = useQueryClient();

    return useMutation({
        mutationFn: async ({ id, decision, note }: { id: number; decision: 'approve' | 'reject'; note?: string }) => {
            const { data } = await apiClient.post(`/site-proposals/${id}/${decision}`, { note });
            return data;
        },
        onSuccess: () => {
            qc.invalidateQueries({ queryKey: proposalKeys.all });
            qc.invalidateQueries({ queryKey: ['geo'] });
            qc.invalidateQueries({ queryKey: ['devices'] });
        },
    });
}
