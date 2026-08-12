import { useEffect, useMemo, useRef, useState, type ReactNode } from 'react';
import { createPortal } from 'react-dom';
import maplibregl from 'maplibre-gl/dist/maplibre-gl-csp';
import { CaretRight, Users, ArrowsLeftRight, Path as PathIcon } from '@phosphor-icons/react';
import { useCanMoveDevices } from '../../auth/api/auth';
import { MoveDeviceDialog } from './MoveDeviceDialog';
import { StatusDot } from '../../../components/StatusDot';
import { NotesSection } from '../../annotations/components/NotesSection';
import { SonarTicketsSection } from '../../annotations/components/SonarTicketsSection';
import { useNotes } from '../../annotations/api/notes';
import { useSonarTickets } from '../../annotations/api/sonarTickets';
import type { AnnotationSubject } from '../../annotations/types';
import { selectDevice, setInspectorOpen, showPathFor, usePathSiteId } from '../../../lib/shellStore';
import { useGeoDevices, useBackhaulPath, type GeoDevice, type Site } from '../api/sites';

/**
 * The device list for a clicked site, as a MapLibre popup ANCHORED OVER ITS TOWER.
 *
 * Devices at a site all share the site's coordinates, so a list is how you actually "expand" a
 * site into its gear - and an operator staring at a map wants that list on the map, next to the
 * marker they just clicked, not in a panel on the far side of the screen. The right rail stays
 * what it has always been: the single DEVICE card (DeviceInspector), opened by clicking a row here.
 *
 * The body is REAL REACT rendered through a portal into the popup's container element, not a
 * hand-built DOM string: the site's notes and Sonar tickets are the same components the device
 * inspector uses and need the query-client context, which only a portal keeps. One rendering
 * model for the whole popup - no half-DOM/half-React seam.
 */

const KIND_LABEL: Record<string, string> = { tower: 'Tower', cabinet: 'Cabinet', pop: 'PoP', other: 'Site' };

/** How many device rows render before the "show more" - a big tower can carry hundreds. */
const DEVICE_CAP = 40;

/**
 * Elapsed outage, compact ("14m", "3h 07m", "2d 4h"). Red isn't actionable on its own - a radio
 * that dropped 90 seconds ago is a blip, one that's been down 3 days is a truck roll - so the
 * popup prints how long the outage has been running next to every down device.
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

/** Down first, then by name - triage order. */
function sortForTriage(devices: GeoDevice[]): GeoDevice[] {
    return [...devices].sort(
        (a, b) => Number(b.status === 'down') - Number(a.status === 'down') || a.name.localeCompare(b.name),
    );
}

/**
 * One device at the site: status dot, name, mgmt IP, the customers riding it, and - if it's down -
 * how long for. Clicking it hands the selection to the device inspector in the right rail and
 * dismisses the popup, the way the original did.
 */
function DeviceRow({ device, siteId, onPick }: { device: GeoDevice; siteId: number | null; onPick: () => void }) {
    const dur = device.status === 'down' ? outageFor(device.down_since) : null;
    const affected = device.status === 'down';
    const canMove = useCanMoveDevices();
    const [moving, setMoving] = useState(false);

    return (
        <div className="group flex w-full items-center rounded-md transition-colors duration-200 ease-fluid hover:bg-white/[0.06]">
        <button
            type="button"
            onClick={() => {
                selectDevice(device.id);
                setInspectorOpen(true); // surface the inspector sheet on phones/tablets
                onPick();
            }}
            title={`Open ${device.name}`}
            className="flex min-w-0 flex-1 items-center gap-2 px-1 py-1 text-left"
        >
            <StatusDot status={device.status} />
            <span className="min-w-0 flex-1 truncate text-[11.5px] text-white/85">{device.name}</span>
            {device.mgmt_ip && (
                <span className="shrink-0 font-mono text-[10px] tabular-nums text-white/35">{device.mgmt_ip}</span>
            )}
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
        {canMove && (
            <button
                type="button"
                onClick={(e) => { e.stopPropagation(); setMoving(true); }}
                title="Move this device to a different site"
                className="mr-1 shrink-0 rounded p-1 text-white/45 transition-colors hover:bg-white/[0.10] hover:text-emerald-300"
            >
                <ArrowsLeftRight weight="bold" className="h-3 w-3" />
            </button>
        )}
        {moving && (
            <MoveDeviceDialog
                deviceId={device.id}
                deviceName={device.name}
                currentSiteId={siteId}
                onClose={() => setMoving(false)}
            />
        )}
        </div>
    );
}

/**
 * A `> Label (count)` disclosure that expands in place. Collapsed is the default and the body
 * isn't mounted until it opens, so clicking through towers never fires a notes/tickets request
 * per site - the cost is paid only by the operator who asks for it.
 */
function Collapsible({
    label,
    count,
    open,
    onToggle,
    children,
}: {
    label: string;
    count: string | null;
    open: boolean;
    onToggle: () => void;
    children: ReactNode;
}) {
    return (
        <div>
            <button
                type="button"
                onClick={onToggle}
                className="flex w-full items-center gap-1.5 rounded-md px-1 py-1 text-left text-[11px] font-medium text-white/55 transition-colors duration-200 ease-fluid hover:bg-white/[0.06] hover:text-white/85"
            >
                <CaretRight
                    weight="bold"
                    className={`h-3 w-3 shrink-0 transition-transform duration-200 ease-fluid ${open ? 'rotate-90' : ''}`}
                />
                {label}
                {count !== null && <span className="tabular-nums text-white/35">({count})</span>}
            </button>
            {open && <div className="px-1 pb-1.5 pt-1">{children}</div>}
        </div>
    );
}

/**
 * "Path" - highlight this site's backhaul chain back to the fiber drain that feeds it.
 *
 * The question during an outage is rarely "is this tower down" but "what else is behind the
 * same break", and a typical tower here is NINE wireless hops from fiber. Pressing this draws
 * the chain on the map and states where it lands.
 *
 * It deliberately reports WHY there is no path rather than just going quiet: a site with no
 * links at all is a data gap to fix, whereas no reachable drain means that region's fiber
 * hasn't been imported yet. Those need different actions, so they must not look identical.
 */
function PathControl({ siteId }: { siteId: number }) {
    const active = usePathSiteId() === siteId;
    const { data, isFetching } = useBackhaulPath(active ? siteId : null);
    const path = data?.data ?? null;

    const detail = !active
        ? null
        : isFetching && !data
          ? 'Tracing...'
          : path
            ? path.hops === 0
                ? 'This site is the fiber drain.'
                : `${path.hops} hop${path.hops === 1 ? '' : 's'} to ${path.drain?.name ?? 'fiber'}`
            : data?.reason === 'site_has_no_links'
              ? 'No backhaul links recorded for this site.'
              : 'No fiber drain reachable - the fiber for this region may not be imported yet.';

    return (
        <div className="mt-1">
            <button
                type="button"
                onClick={() => showPathFor(siteId)}
                aria-pressed={active}
                className={`flex items-center gap-1.5 rounded-md px-1.5 py-1 text-[11px] font-medium ring-1 transition-colors duration-200 ease-fluid ${
                    active
                        ? 'bg-amber-400/15 text-amber-200 ring-amber-300/30'
                        : 'bg-white/[0.04] text-white/70 ring-white/10 hover:bg-white/[0.08] hover:text-white'
                }`}
                title="Highlight the backhaul path from here to the fiber drain"
            >
                <PathIcon weight="bold" className="h-3 w-3" />
                Path
            </button>
            {detail && <p className="mt-1 text-[11px] text-white/50">{detail}</p>}
        </div>
    );
}

/**
 * The popup body. Devices come from the map's own compact feed (/geo/devices), so opening a site
 * costs no extra request and the list re-renders as statuses change under it.
 */
function SitePopupBody({ site, onClose }: { site: Site; onClose: () => void }) {
    const { data: allDevices } = useGeoDevices();
    const [limit, setLimit] = useState(DEVICE_CAP);
    const [notesOpen, setNotesOpen] = useState(false);
    const [ticketsOpen, setTicketsOpen] = useState(false);
    // Sticky "has ever been expanded" flags: they keep the query enabled after a collapse so the
    // header keeps showing the count the operator already paid for.
    const [notesAsked, setNotesAsked] = useState(false);
    const [ticketsAsked, setTicketsAsked] = useState(false);

    const subject = useMemo<AnnotationSubject>(() => ({ kind: 'site', id: site.id }), [site.id]);
    // Both hooks are disabled (subject null) until their section is opened; once open they share
    // the query key with the section's own hook, so expanding costs exactly one request.
    const { data: notes } = useNotes(notesAsked ? subject : null);
    const { data: tickets } = useSonarTickets(ticketsAsked ? subject : null);

    const devs = useMemo(() => sortForTriage((allDevices ?? []).filter((d) => d.site_id === site.id)), [allDevices, site.id]);
    const downDevs = devs.filter((d) => d.status === 'down');
    const down = downDevs.length;
    const total = devs.length;

    // A whole site dark is one event, so the header leads with the OLDEST still-open outage at it
    // - "Down 3h 07m" reads as the site's age, not some arbitrary device's.
    const oldest = downDevs.map((d) => d.down_since).filter((s): s is string => !!s).sort()[0] ?? null;
    const siteFor = outageFor(oldest);
    // Customers riding the down APs here - the "how urgent" number for a whole-site event.
    const affected = downDevs.reduce((n, d) => n + (typeof d.cust_count === 'number' ? d.cust_count : 0), 0);

    const kind = (site.kind ?? 'other').toLowerCase();
    const visible = devs.slice(0, limit);

    // Open tickets are the ones worth a count in a collapsed header; a long tail of closed ones
    // shouldn't read as work outstanding.
    const openTickets = tickets?.filter((t) => t.status !== 'CLOSED').length ?? 0;
    const ticketCount = tickets ? (openTickets > 0 ? `${openTickets} open` : String(tickets.length)) : null;

    return (
        // Capped height with the list scrolling INSIDE it: a 40-radio tower must never grow a
        // popup taller than the viewport.
        <div className="flex max-h-[min(60vh,26rem)] w-[19.5rem] flex-col gap-1.5">
            {/* pr-5 keeps the name clear of MapLibre's own close button, which sits top-right. */}
            <div className="shrink-0 pr-5">
                <div className="truncate text-[13px] font-semibold text-white" title={site.name}>
                    {site.name}
                </div>
                <div className="truncate text-[11px] text-white/45">
                    {KIND_LABEL[kind] ?? 'Site'} · {total} device{total === 1 ? '' : 's'}
                    {down > 0 ? ` · ${down} down` : ''}
                </div>
                {down > 0 && (siteFor || affected > 0) && (
                    <p className="flex items-center gap-2 text-[11px] text-rose-300/90">
                        {siteFor && <span className="font-medium">Down {siteFor}</span>}
                        {affected > 0 && (
                            <span className="flex items-center gap-1">
                                <Users weight="bold" className="h-3 w-3" /> {affected} affected
                            </span>
                        )}
                    </p>
                )}
                <PathControl siteId={site.id} />
            </div>

            <div className="-mr-1.5 min-h-0 flex-1 overflow-y-auto pr-1.5">
                <div className="border-t border-white/10 pt-1">
                    {devs.length === 0 ? (
                        <p className="px-1 py-1 text-[11px] text-white/35">No monitored devices at this site.</p>
                    ) : (
                        <>
                            {visible.map((d) => (
                                <DeviceRow key={d.id} device={d} siteId={site.id} onPick={onClose} />
                            ))}
                            {devs.length > visible.length && (
                                <button
                                    type="button"
                                    onClick={() => setLimit((n) => n + DEVICE_CAP)}
                                    className="mt-1 w-full rounded-md bg-white/[0.04] px-2 py-1 text-[11px] font-medium text-white/70 ring-1 ring-white/10 transition-all duration-300 ease-fluid hover:bg-white/[0.08] hover:text-white"
                                >
                                    Show {Math.min(DEVICE_CAP, devs.length - visible.length)} more of {devs.length}
                                </button>
                            )}
                        </>
                    )}
                </div>

                {/* Operator annotations - the same two components the device inspector uses, keyed
                    by the generic annotation subject, collapsed until asked for. */}
                <div className="mt-1.5 border-t border-white/10 pt-1">
                    <Collapsible
                        label="Notes"
                        count={notes ? String(notes.length) : null}
                        open={notesOpen}
                        onToggle={() => {
                            setNotesAsked(true);
                            setNotesOpen((o) => !o);
                        }}
                    >
                        <NotesSection subject={subject} />
                    </Collapsible>
                    <Collapsible
                        label="Sonar tickets"
                        count={ticketCount}
                        open={ticketsOpen}
                        onToggle={() => {
                            setTicketsAsked(true);
                            setTicketsOpen((o) => !o);
                        }}
                    >
                        <SonarTicketsSection subject={subject} />
                    </Collapsible>
                </div>
            </div>
        </div>
    );
}

/**
 * Mounts a MapLibre popup at `coords` and portals the React body into it.
 *
 * Lifecycle: the popup is created with an EMPTY container element, and `createPortal` renders the
 * body into that element from this component's place in the React tree (so context - query client,
 * toasts - is the app's). Unmounting this component removes the popup and, with it, the portal;
 * MapLibre's own close (the x button) calls `onClose` so the owner drops the state. The
 * `removing` latch keeps our teardown from firing that callback and wiping a selection the map
 * has just made - clicking straight from one tower to another must not close the new popup.
 * Callers key this by site id, so switching sites is a clean unmount + mount, never two popups.
 */
export function SitePopup({
    map,
    site,
    coords,
    onClose,
}: {
    map: maplibregl.Map;
    site: Site;
    coords: [number, number];
    onClose: () => void;
}) {
    const [container] = useState(() => document.createElement('div'));
    const closeRef = useRef(onClose);
    closeRef.current = onClose;

    useEffect(() => {
        const popup = new maplibregl.Popup({
            maxWidth: '360px',
            offset: 14,
            // The map's own click handler decides when a click-away closes this, so MapLibre's
            // closeOnClick can't race it and swallow the popup the same click just opened.
            closeOnClick: false,
            focusAfterOpen: false,
        })
            .setLngLat(coords)
            .setDOMContent(container)
            .addTo(map);

        let removing = false;
        popup.on('close', () => {
            if (!removing) closeRef.current();
        });

        return () => {
            removing = true;
            popup.remove();
        };
    }, [map, container, coords]);

    return createPortal(<SitePopupBody site={site} onClose={() => closeRef.current()} />, container);
}
