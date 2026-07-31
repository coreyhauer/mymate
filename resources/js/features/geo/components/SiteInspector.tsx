import { useEffect, useState, type ReactNode } from 'react';
import { Buildings, CellTower, CircleNotch, HardDrives, MapPin, Users, X } from '@phosphor-icons/react';
import { selectDevice, selectSite, setInspectorOpen, useInspectorOpen } from '../../../lib/shellStore';
import { InspectorShell } from '../../../components/InspectorShell';
import { StatusDot } from '../../../components/StatusDot';
import { NotesSection } from '../../annotations/components/NotesSection';
import { SonarTicketsSection } from '../../annotations/components/SonarTicketsSection';
import { useGeoDevices, useSite, useSites, type GeoDevice, type Site } from '../api/sites';

/**
 * Right-rail inspector for a SITE - the physical location (tower / cabinet / PoP) gear sits at.
 *
 * Opened by clicking a site marker on the geo map, and mounted in the same rail slot as the
 * device inspector (the shell only ever renders one of the two). It replaces the raw-DOM MapLibre
 * popup that used to list a site's devices: same information - health, per-device outage age,
 * customers riding a down AP - but as a real React panel, so operator notes and linked Sonar
 * tickets can hang off the site the way they already do off devices and links.
 *
 * Device data comes from the map's own compact feed (/geo/devices), so opening the panel costs no
 * extra request.
 */

const KIND_LABEL: Record<string, string> = { tower: 'Tower', cabinet: 'Cabinet', pop: 'PoP', other: 'Site' };

const KIND_ICON: Record<string, typeof MapPin> = {
    tower: CellTower,
    cabinet: HardDrives,
    pop: Buildings,
    other: MapPin,
};

/** How many device rows render before the "show more" - a big tower can carry hundreds. */
const DEVICE_PAGE = 40;

/**
 * Elapsed outage, compact ("14m", "3h 07m", "2d 4h"). Red isn't actionable on its own - a radio
 * that dropped 90 seconds ago is a blip, one that's been down 3 days is a truck roll - so every
 * down device shows how long the outage has been running.
 */
function outageFor(since: string | null): string | null {
    if (!since) return null;
    const started = new Date(since).getTime();
    if (Number.isNaN(started)) return null;

    const m = Math.max(0, Math.floor((Date.now() - started) / 60000));
    if (m < 60) return `${m}m`;
    const h = Math.floor(m / 60);
    if (h < 24) return `${h}h ${String(m % 60).padStart(2, '0')}m`;
    return `${Math.floor(h / 24)}d ${h % 24}h`;
}

/** Coordinates as a fixed-precision pair; 5 decimals is ~1 m, plenty for a tower. */
function fmtCoords(lat: number | null, lng: number | null): string | null {
    if (lat == null || lng == null) return null;
    return `${Number(lat).toFixed(5)}, ${Number(lng).toFixed(5)}`;
}

function Detail({ label, value, mono }: { label: string; value: string; mono?: boolean }) {
    return (
        <div className="min-w-0">
            <p className="text-[10px] font-medium uppercase tracking-[0.16em] text-white/30">{label}</p>
            <p className={`mt-0.5 truncate text-sm text-white/85 ${mono ? 'font-mono tabular-nums' : ''}`} title={value}>
                {value}
            </p>
        </div>
    );
}

function Section({ title, right, children }: { title: string; right?: string; children: ReactNode }) {
    return (
        <div className="space-y-2.5">
            <div className="flex items-baseline justify-between">
                <p className="text-[10px] font-medium uppercase tracking-[0.2em] text-white/30">{title}</p>
                {right ? <span className="font-mono text-xs tabular-nums text-white/60">{right}</span> : null}
            </div>
            {children}
        </div>
    );
}

function Empty({ children }: { children: ReactNode }) {
    return <p className="rounded-xl bg-white/[0.02] px-3 py-2.5 text-xs text-white/40 ring-1 ring-white/[0.06]">{children}</p>;
}

/**
 * One device at the site: status dot, name, mgmt IP (when the feed carries it), the customers
 * riding it, and - if it's down - how long for. Clicking it hands the selection to the device
 * inspector, which takes over this same rail.
 */
function DeviceRow({ device }: { device: GeoDevice }) {
    const dur = device.status === 'down' ? outageFor(device.down_since) : null;
    const affected = device.status === 'down';

    return (
        <button
            type="button"
            onClick={() => {
                selectDevice(device.id);
                setInspectorOpen(true); // surface the inspector sheet on phones/tablets
            }}
            title={`Open ${device.name}`}
            className="flex w-full items-center gap-2 rounded-lg px-1.5 py-1 text-left transition-colors duration-200 ease-fluid hover:bg-white/[0.06]"
        >
            <StatusDot status={device.status} />
            <span className="min-w-0 flex-1 truncate text-xs text-white/80">{device.name}</span>
            {device.mgmt_ip ? (
                <span className="shrink-0 font-mono text-[10px] tabular-nums text-white/35">{device.mgmt_ip}</span>
            ) : null}
            {typeof device.cust_count === 'number' && device.cust_count > 0 && (
                <span
                    title={
                        affected
                            ? `${device.cust_count} customer${device.cust_count === 1 ? '' : 's'} on this AP - affected by the outage`
                            : `${device.cust_count} customer${device.cust_count === 1 ? '' : 's'} on this AP`
                    }
                    className={`flex shrink-0 items-center gap-0.5 text-[10px] tabular-nums ${affected ? 'text-rose-300' : 'text-white/40'}`}
                >
                    <Users weight="bold" className="h-3 w-3" /> {device.cust_count}
                </span>
            )}
            {dur && (
                <span
                    title={`Down since ${new Date(device.down_since as string).toLocaleString()}`}
                    className="shrink-0 font-mono text-[10px] tabular-nums text-rose-300"
                >
                    {dur}
                </span>
            )}
        </button>
    );
}

/** Down first, then by name - triage order, the same as the popup this replaces. */
function sortForTriage(devices: GeoDevice[]): GeoDevice[] {
    return [...devices].sort(
        (a, b) => Number(b.status === 'down') - Number(a.status === 'down') || a.name.localeCompare(b.name),
    );
}

function SiteBody({ site, devices }: { site: Site; devices: GeoDevice[] }) {
    const [limit, setLimit] = useState(DEVICE_PAGE);

    // Switching site mid-scroll must not carry the expanded list onto the new one.
    useEffect(() => setLimit(DEVICE_PAGE), [site.id]);

    const devs = sortForTriage(devices);
    const downDevs = devs.filter((d) => d.status === 'down');
    const down = downDevs.length;
    const total = devs.length || site.device_count || 0;

    // A whole site dark is one event, so the header leads with the OLDEST still-open outage at it
    // - "down 3h 07m" reads as the site's age, not some arbitrary device's.
    const oldest = downDevs.map((d) => d.down_since).filter((s): s is string => !!s).sort()[0] ?? null;
    const siteFor = outageFor(oldest);
    // Customers riding the down APs here - the "how urgent" number for a whole-site event.
    const affected = downDevs.reduce((n, d) => n + (typeof d.cust_count === 'number' ? d.cust_count : 0), 0);

    const kind = (site.kind ?? 'other').toLowerCase();
    const KindIcon = KIND_ICON[kind] ?? MapPin;
    const coords = fmtCoords(site.latitude, site.longitude);
    const visible = devs.slice(0, limit);

    return (
        <>
            <div className="flex items-start justify-between gap-3">
                <div className="flex min-w-0 items-center gap-2.5">
                    <span className="grid h-9 w-9 shrink-0 place-items-center rounded-md text-white/70 ring-1 ring-white/10">
                        <KindIcon weight="light" className="h-5 w-5" />
                    </span>
                    <div className="min-w-0">
                        <div className="truncate text-base font-bold tracking-tight text-white" title={site.name}>
                            {site.name}
                        </div>
                        <div className="truncate text-[11px] text-white/40">
                            {KIND_LABEL[kind] ?? 'Site'} · {total} device{total === 1 ? '' : 's'}
                            {down > 0 ? ` · ${down} down` : ''}
                        </div>
                    </div>
                </div>
                <div className="flex shrink-0 items-center gap-1">
                    <span
                        className={`inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium ring-1 ${
                            down > 0
                                ? 'bg-rose-500/15 text-rose-300 ring-rose-400/25'
                                : total > 0
                                  ? 'bg-emerald-500/15 text-emerald-300 ring-emerald-400/25'
                                  : 'bg-white/5 text-white/45 ring-white/10'
                        }`}
                    >
                        <StatusDot status={down > 0 ? 'down' : total > 0 ? 'up' : 'unknown'} />
                        {down > 0 ? `${down} down` : total > 0 ? 'All up' : 'No gear'}
                    </span>
                    {/* Desktop dismiss - the rail has no other way back to "nothing selected". */}
                    <button
                        type="button"
                        onClick={() => selectSite(null)}
                        title="Close site"
                        className="hidden rounded-lg p-1.5 text-white/35 transition-colors duration-300 ease-fluid hover:bg-white/5 hover:text-white/80 lg:block"
                    >
                        <X weight="bold" className="h-3.5 w-3.5" />
                    </button>
                </div>
            </div>

            {/* Whole-site outage banner: how long it's been dark and how many customers that costs. */}
            {down > 0 && (siteFor || affected > 0) && (
                <p className="-mt-2 flex items-center gap-2 text-xs text-rose-300/90">
                    {siteFor && <span className="font-medium">Down {siteFor}</span>}
                    {affected > 0 && (
                        <span className="flex items-center gap-1">
                            <Users weight="bold" className="h-3.5 w-3.5" /> {affected} affected
                        </span>
                    )}
                </p>
            )}

            <div className="grid grid-cols-2 gap-x-4 gap-y-3">
                <Detail label="Coordinates" value={coords ?? 'Not placed'} mono={coords !== null} />
                <Detail label="Devices" value={String(total)} mono />
                {site.address && (
                    <div className="col-span-2">
                        <Detail label="Address" value={site.address} />
                    </div>
                )}
                {site.external_ref && <Detail label="External ref" value={site.external_ref} mono />}
            </div>

            {site.note && (
                <Section title="Site record note">
                    <p className="text-xs leading-relaxed whitespace-pre-wrap break-words text-white/70">{site.note}</p>
                </Section>
            )}

            <Section title={`Devices - ${devs.length}`} right={down > 0 ? `${down} down` : undefined}>
                {devs.length === 0 ? (
                    <Empty>No monitored devices at this site.</Empty>
                ) : (
                    <div className="space-y-0.5">
                        {visible.map((d) => (
                            <DeviceRow key={d.id} device={d} />
                        ))}
                        {devs.length > visible.length && (
                            <button
                                type="button"
                                onClick={() => setLimit((n) => n + DEVICE_PAGE)}
                                className="mt-1 w-full rounded-lg bg-white/[0.04] px-2 py-1.5 text-xs font-medium text-white/75 ring-1 ring-white/10 transition-all duration-300 ease-fluid hover:bg-white/[0.08] hover:text-white"
                            >
                                Show {Math.min(DEVICE_PAGE, devs.length - visible.length)} more of {devs.length}
                            </button>
                        )}
                    </div>
                )}
            </Section>

            {/* Operator annotations - the same two sections the device inspector uses, keyed by
                the generic annotation subject so the site slice needed no new components. */}
            <Section title="Notes">
                <NotesSection subject={{ kind: 'site', id: site.id }} />
            </Section>

            <Section title="Sonar tickets">
                <SonarTicketsSection subject={{ kind: 'site', id: site.id }} />
            </Section>
        </>
    );
}

/**
 * The site panel. Resolves its site from the already-loaded map list, falling back to a direct
 * fetch (deep link / cold cache). Loading and failure are deliberately quiet - a site that can't
 * be read is one muted line in the rail, never anything that disturbs the map.
 */
export function SiteInspector({ siteId }: { siteId: number }) {
    const inspectorOpen = useInspectorOpen();
    const { data: sites } = useSites();
    const { data: devices } = useGeoDevices();

    const listSite = sites?.find((s) => s.id === siteId);
    const { data: fetchedSite, isLoading, isError } = useSite(listSite ? null : siteId);
    const site = listSite ?? (fetchedSite?.id === siteId ? fetchedSite : undefined);

    if (!site) {
        return (
            <InspectorShell open={inspectorOpen}>
                <p className="flex items-center gap-1.5 text-xs text-white/35">
                    {isError ? (
                        "This site couldn't be loaded."
                    ) : isLoading || !sites ? (
                        <>
                            <CircleNotch weight="bold" className="h-3 w-3 animate-spin" /> Loading site...
                        </>
                    ) : (
                        'Site not found.'
                    )}
                </p>
            </InspectorShell>
        );
    }

    return (
        <InspectorShell open={inspectorOpen}>
            <SiteBody site={site} devices={(devices ?? []).filter((d) => d.site_id === siteId)} />
        </InspectorShell>
    );
}
