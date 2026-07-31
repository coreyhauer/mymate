import type { ReactNode } from 'react';
import { X } from '@phosphor-icons/react';
import { setInspectorOpen } from '../lib/shellStore';

/**
 * Responsive container for a right-rail inspector: a static right-hand column at `lg`+, and a
 * slide-over sheet with a backdrop + close button below it. `open` drives the sheet; at `lg`+ the
 * panel is always shown and `open` is ignored.
 *
 * Extracted so every inspector (device, site) shares one shell and can't drift in width, glass
 * treatment or sheet behaviour. `DeviceInspector` still carries its own private copy of this
 * markup - swapping it for this component is a safe follow-up, not a change this panel needs.
 */
export function InspectorShell({ open, children }: { open: boolean; children: ReactNode }) {
    return (
        <>
            {/* Backdrop - phone/tablet only; dims the map behind the sheet. */}
            <div
                className={`fixed inset-0 z-30 bg-black/50 backdrop-blur-sm transition-opacity duration-300 ease-fluid lg:hidden ${
                    open ? 'opacity-100' : 'pointer-events-none opacity-0'
                }`}
                onClick={() => setInspectorOpen(false)}
            />
            <aside
                className={`fixed inset-y-0 right-0 z-40 flex w-[min(24rem,88vw)] flex-col gap-5 overflow-y-auto border-l border-white/10 bg-[#0d0d11]/95 p-5 pt-[max(1.25rem,env(safe-area-inset-top))] pb-[max(1.25rem,env(safe-area-inset-bottom))] shadow-[0_30px_80px_-20px_rgba(0,0,0,0.9)] backdrop-blur-2xl transition-transform duration-300 ease-fluid lg:static lg:z-10 lg:w-[22rem] lg:shrink-0 lg:translate-x-0 lg:bg-white/[0.02] lg:shadow-none ${
                    open ? 'translate-x-0' : 'translate-x-full'
                }`}
            >
                {/* Close - phone/tablet only; a top bar so it never overlaps the header. */}
                <div className="flex justify-end lg:hidden">
                    <button
                        onClick={() => setInspectorOpen(false)}
                        title="Close"
                        className="rounded-lg p-1.5 text-white/40 transition-colors duration-300 ease-fluid hover:bg-white/5 hover:text-white/80"
                    >
                        <X weight="bold" className="h-4 w-4" />
                    </button>
                </div>
                {children}
            </aside>
        </>
    );
}
