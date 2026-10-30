import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { apiClient } from '../../../lib/apiClient';

/** One row of /site-links: an existing site-to-site backhaul, labeled with its ends. */
export interface SiteLinkRow {
    id: number;
    site_a_id: number;
    site_b_id: number;
    media_type: 'fiber' | 'wireless' | 'other' | null;
    external_ref: string | null;
    site_a_name: string | null;
    site_b_name: string | null;
}

export const siteLinkKeys = {
    all: ['site-links'] as const,
    list: () => [...siteLinkKeys.all, 'list'] as const,
};

/** All site-to-site backhaul links (the geo map draws these, run through /geo/backhauls). */
export function useSiteLinks() {
    return useQuery({
        queryKey: siteLinkKeys.list(),
        queryFn: async (): Promise<SiteLinkRow[]> => {
            const { data } = await apiClient.get<{ data: SiteLinkRow[] }>('/site-links');
            return data.data;
        },
        staleTime: 5 * 60 * 1000, // topology changes rarely
    });
}

type CreateSiteLinkInput = {
    site_a_id: number;
    site_b_id: number;
    media_type?: 'fiber' | 'wireless' | 'other' | null;
};

/** Create a backhaul link between two sites. Invalidates the link list and the geo feed. */
export function useCreateSiteLink() {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: async (body: CreateSiteLinkInput): Promise<SiteLinkRow> => {
            const { data } = await apiClient.post<{ data: SiteLinkRow }>('/site-links', body);
            return data.data;
        },
        onSuccess: () => invalidateBackhaulQueries(qc),
    });
}

export function useDeleteSiteLink() {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: async (id: number): Promise<void> => {
            await apiClient.delete(`/site-links/${id}`);
        },
        onSuccess: () => invalidateBackhaulQueries(qc),
    });
}

// The line layer's payload comes from /geo/backhauls, which resolves the ends' live
// coordinates - so both the link list and the drawn lines must refetch after a change.
function invalidateBackhaulQueries(qc: ReturnType<typeof useQueryClient>) {
    qc.invalidateQueries({ queryKey: siteLinkKeys.all });
    qc.invalidateQueries({ queryKey: ['geo', 'backhauls'] });
}
