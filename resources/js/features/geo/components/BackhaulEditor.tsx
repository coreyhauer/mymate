import { useMemo, useState } from 'react';
import { Trash } from '@phosphor-icons/react';
import { useSites } from '../api/sites';
import { useCreateSiteLink, useDeleteSiteLink, useSiteLinks } from '../api/siteLinks';
import { pushToast } from '../../../lib/toast';

/**
 * Admin backhaul editor for the geo map. The lines layer reads the site_links table, which
 * used to be writable only by the artisan OSS import - so an operator who spotted a missing
 * pair (like a freshly built tower-to-tower hop) had no way to draw it, and a stale pair no
 * way to remove it. This lists the links with a delete per row and a small add form; every
 * change refetches both the link list and /geo/backhauls, so the lines follow immediately.
 */
export function BackhaulEditor({ onClose }: { onClose: () => void }) {
    const { data: links } = useSiteLinks();
    const { data: sites } = useSites();
    const create = useCreateSiteLink();
    const del = useDeleteSiteLink();
    const [aId, setAId] = useState('');
    const [bId, setBId] = useState('');
    const [media, setMedia] = useState('wireless');

    const options = useMemo(() => sites ?? [], [sites]);
    const onError = (m?: { message: string }) =>
        pushToast({ title: 'Backhaul change failed', detail: m?.message, tone: 'down' });

    const submit = () => {
        if (aId === '' || bId === '' || aId === bId) {
            pushToast({ title: 'Pick two different sites', tone: 'down' });
            return;
        }
        create.mutate(
            {
                site_a_id: Number(aId),
                site_b_id: Number(bId),
                media_type: media === '' ? null : (media as 'fiber' | 'wireless' | 'other'),
            },
            {
                onSuccess: () => {
                    setAId('');
                    setBId('');
                    pushToast({ title: 'Backhaul link added', tone: 'up' });
                },
                onError,
            },
        );
    };

    return (
        <div className="absolute bottom-14 right-3 z-[500] w-80 rounded-2xl bg-surface/95 p-3 ring-1 ring-white/10 backdrop-blur-xl">
            <div className="flex items-center justify-between px-1">
                <span className="text-xs font-semibold text-white/80">Backhaul links</span>
                <span className="text-[11px] text-white/40">{links?.length ?? 0} links</span>
            </div>
            <div className="flex justify-end px-1">
                <button onClick={onClose} className="rounded p-1 text-[11px] text-white/40 hover:text-white/80">Close</button>
            </div>

            <div className="mt-1 max-h-56 overflow-y-auto pr-1">
                {(links ?? []).map((l) => (
                    <div key={l.id} className="group flex items-center justify-between gap-2 rounded-lg px-2 py-1.5 hover:bg-white/[0.05]">
                        <span className="truncate text-xs text-white/70" title={l.external_ref ?? undefined}>
                            <span className="font-medium text-white/85">{l.site_a_name}</span>
                            <span className="mx-1 text-white/35">—</span>
                            <span className="font-medium text-white/85">{l.site_b_name}</span>
                            {l.media_type != null && <span className="ml-1.5 text-[10px] text-white/35">{l.media_type}</span>}
                        </span>
                        <button
                            onClick={() => del.mutate(l.id, { onError })}
                            className="shrink-0 rounded p-1 text-rose-500/70 opacity-0 transition-opacity hover:bg-white/10 hover:text-rose-400 focus:opacity-100 group-hover:opacity-100"
                            title="Delete link"
                        >
                            <Trash weight="bold" className="h-3.5 w-3.5" />
                        </button>
                    </div>
                ))}
                {links?.length === 0 && <p className="px-2 py-3 text-xs text-white/35">No backhaul links yet.</p>}
            </div>

            {/* Add form: two sites + medium. A site without coordinates still saves fine - the
                line layer renders a pair only once both ends are placed. */}
            <div className="mt-2 flex items-center gap-1.5">
                <SiteSelect value={aId} onChange={setAId} options={options} />
                <span className="text-xs text-white/35">to</span>
                <SiteSelect value={bId} onChange={setBId} options={options} />
            </div>
            <div className="mt-1.5 flex items-center gap-1.5">
                <select
                    value={media}
                    onChange={(e) => setMedia(e.target.value)}
                    className="flex-1 rounded-lg bg-white/[0.04] px-2 py-1.5 text-xs text-white ring-1 ring-white/10 outline-none"
                >
                    <option value="wireless">wireless</option>
                    <option value="fiber">fiber</option>
                    <option value="other">other</option>
                    <option value="">(unknown)</option>
                </select>
                <button
                    onClick={submit}
                    disabled={create.isPending}
                    className="rounded-lg bg-emerald-500/20 px-3 py-1.5 text-xs font-semibold text-emerald-200 ring-1 ring-emerald-400/40 transition-colors hover:bg-emerald-500/30 disabled:opacity-40"
                >
                    {create.isPending ? 'Adding…' : 'Add link'}
                </button>
            </div>
        </div>
    );
}

function SiteSelect({ value, onChange, options }: { value: string; onChange: (v: string) => void; options: { id: number; name: string }[] }) {
    return (
        <select
            value={value}
            onChange={(e) => onChange(e.target.value)}
            className="flex-1 rounded-lg bg-white/[0.04] px-2 py-1.5 text-xs text-white ring-1 ring-white/10 outline-none [&>option]:bg-slate-800"
        >
            <option value="">Site…</option>
            {options.map((s) => (
                <option key={s.id} value={s.id}>{s.name}</option>
            ))}
        </select>
    );
}
