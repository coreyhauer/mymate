// Shared chrome for the annotation sections so they read as part of the inspector
// (same glass/ring/emerald language as DeviceInspector's own controls).

/** Full-width secondary action ("Add note", "Link ticket"). */
export const actionBtn =
    'flex items-center justify-center gap-1.5 rounded-lg bg-white/[0.04] px-2 py-1.5 text-xs font-medium text-white/75 ring-1 ring-white/10 transition-all duration-300 ease-fluid hover:bg-white/[0.08] hover:text-white disabled:opacity-40';

/** Small square icon button used for the per-row edit / delete / refresh affordances. */
export const iconBtn =
    'rounded-md p-1 text-white/40 transition-colors duration-200 hover:bg-white/5 hover:text-white/80 disabled:opacity-40';

/** Destructive variant of {@link iconBtn}. */
export const dangerIconBtn =
    'rounded-md p-1 text-white/40 transition-colors duration-200 hover:bg-rose-500/10 hover:text-rose-300 disabled:opacity-40';

/** Multi-line / single-line text entry inside a composer. */
export const fieldCls =
    'w-full rounded-lg bg-white/[0.04] px-2.5 py-2 text-xs text-white ring-1 ring-white/10 transition-colors duration-200 ease-fluid outline-none placeholder:text-white/30 focus:ring-emerald-400/40';

/** Primary submit pill inside a composer. */
export const submitBtn =
    'rounded-full bg-emerald-500 px-3.5 py-1 text-xs font-semibold text-emerald-950 transition-all duration-300 ease-fluid hover:bg-emerald-400 active:scale-[0.98] disabled:opacity-40';

/** Quiet cancel next to {@link submitBtn}. */
export const cancelBtn = 'px-2 py-1 text-xs font-medium text-white/45 transition-colors duration-200 hover:text-white/80';

/** The one-line muted state used for empty/loading/failed - never a big empty-state card. */
export const quietLine = 'text-xs text-white/35';
