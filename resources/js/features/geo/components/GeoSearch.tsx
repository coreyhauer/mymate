import { useMemo, useRef, useState } from 'react';
import { MagnifyingGlass, X } from '@phosphor-icons/react';
import type { GeoDevice, Site } from '../api/sites';

/**
 * Find-a-place box for the geo map.
 *
 * The geo view previously had no search of its own - the panel in the inspector is the *topology*
 * map's device-placement palette, which only lists devices not yet on that map and only matches
 * device name/IP, so searching a SITE name there always came back empty. This searches what the
 * geo map actually draws: sites first (that's what you're looking for when you're staring at a
 * map), then individual devices, and hands the pick back so the map can fly to it.
 *
 * Matches against data the map has already loaded, so it costs no extra request.
 */

export type GeoHit =
    | { kind: 'site'; site: Site }
    | { kind: 'device'; device: GeoDevice; siteName: string | null };

const STATUS_COLOR = { up: '#34d399', down: '#f43f5e', unknown: '#52525b' } as const;
const MAX_RESULTS = 12;

export function GeoSearch({
    sites,
    devices,
    onPick,
}: {
    sites: Site[];
    devices: GeoDevice[];
    onPick: (hit: GeoHit) => void;
}) {
    const [q, setQ] = useState('');
    const [active, setActive] = useState(0);
    const inputRef = useRef<HTMLInputElement>(null);

    const siteNameById = useMemo(() => {
        const m = new Map<number, string>();
        for (const s of sites) m.set(s.id, s.name);
        return m;
    }, [sites]);

    const hits = useMemo<GeoHit[]>(() => {
        const query = q.trim().toLowerCase();
        if (query.length < 2) return [];

        // A prefix match is almost always the one you meant ("didier" -> Didier, not
        // "...2didier"), so rank those above mid-string matches and let sites win ties.
        const score = (name: string) => {
            const n = name.toLowerCase();
            const i = n.indexOf(query);
            if (i < 0) return -1;
            return i === 0 ? 0 : 1;
        };

        const rows: { rank: number; hit: GeoHit; name: string }[] = [];

        for (const s of sites) {
            if (s.latitude == null || s.longitude == null) continue; // can't fly to an unplaced site
            const sc = score(s.name);
            if (sc >= 0) rows.push({ rank: sc, hit: { kind: 'site', site: s }, name: s.name });
        }
        for (const d of devices) {
            const sc = score(d.name);
            if (sc >= 0) {
                rows.push({
                    rank: sc + 2, // devices always below sites
                    hit: { kind: 'device', device: d, siteName: d.site_id !== null ? siteNameById.get(d.site_id) ?? null : null },
                    name: d.name,
                });
            }
        }

        rows.sort((a, b) => a.rank - b.rank || a.name.localeCompare(b.name));
        return rows.slice(0, MAX_RESULTS).map((r) => r.hit);
    }, [q, sites, devices, siteNameById]);

    function choose(hit: GeoHit) {
        onPick(hit);
        setQ('');
        setActive(0);
        inputRef.current?.blur();
    }

    function onKeyDown(e: React.KeyboardEvent<HTMLInputElement>) {
        if (e.key === 'Escape') {
            setQ('');
            inputRef.current?.blur();
            return;
        }
        if (!hits.length) return;
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            setActive((i) => (i + 1) % hits.length);
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            setActive((i) => (i - 1 + hits.length) % hits.length);
        } else if (e.key === 'Enter') {
            e.preventDefault();
            choose(hits[Math.min(active, hits.length - 1)]);
        }
    }

    const showEmpty = q.trim().length >= 2 && hits.length === 0;

    return (
        <div className="absolute left-14 top-3 z-10 w-72">
            <div className="relative">
                <MagnifyingGlass
                    weight="bold"
                    className="pointer-events-none absolute left-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-white/40"
                />
                <input
                    ref={inputRef}
                    value={q}
                    onChange={(e) => {
                        setQ(e.target.value);
                        setActive(0);
                    }}
                    onKeyDown={onKeyDown}
                    placeholder="Find a site or device..."
                    className="w-full rounded-lg bg-black/60 py-1.5 pl-8 pr-7 text-xs text-white ring-1 ring-white/15 outline-none backdrop-blur transition focus:ring-emerald-400/40 placeholder:text-white/35"
                />
                {q !== '' && (
                    <button
                        type="button"
                        onClick={() => {
                            setQ('');
                            inputRef.current?.focus();
                        }}
                        title="Clear"
                        className="absolute right-1.5 top-1/2 -translate-y-1/2 rounded p-0.5 text-white/40 transition-colors hover:text-white/80"
                    >
                        <X weight="bold" className="h-3 w-3" />
                    </button>
                )}
            </div>

            {(hits.length > 0 || showEmpty) && (
                <ul className="mt-1 max-h-80 overflow-y-auto rounded-lg bg-black/80 py-1 ring-1 ring-white/15 backdrop-blur">
                    {showEmpty ? (
                        <li className="px-3 py-2 text-xs text-white/40">No site or device matches “{q.trim()}”.</li>
                    ) : (
                        hits.map((hit, i) => {
                            const isSite = hit.kind === 'site';
                            const name = isSite ? hit.site.name : hit.device.name;
                            const down = isSite ? hit.site.devices_down ?? 0 : 0;
                            return (
                                <li key={`${hit.kind}-${isSite ? hit.site.id : hit.device.id}`}>
                                    <button
                                        type="button"
                                        onMouseEnter={() => setActive(i)}
                                        onClick={() => choose(hit)}
                                        className={`flex w-full items-center gap-2 px-3 py-1.5 text-left text-xs transition-colors ${
                                            i === active ? 'bg-white/10' : 'hover:bg-white/[0.06]'
                                        }`}
                                    >
                                        <span
                                            className="h-2 w-2 flex-none rounded-full"
                                            style={{
                                                background: isSite
                                                    ? down > 0
                                                        ? STATUS_COLOR.down
                                                        : STATUS_COLOR.up
                                                    : STATUS_COLOR[hit.device.status] ?? STATUS_COLOR.unknown,
                                            }}
                                        />
                                        <span className="min-w-0 flex-1 truncate text-white/90">{name}</span>
                                        <span className="flex-none text-[10px] uppercase tracking-wide text-white/35">
                                            {isSite
                                                ? `site · ${hit.site.device_count ?? 0}`
                                                : hit.siteName ?? 'device'}
                                        </span>
                                    </button>
                                </li>
                            );
                        })
                    )}
                </ul>
            )}
        </div>
    );
}
