// Live-frequency display helpers. The device *name* used to carry a hand-typed (often stale)
// frequency; we now read the real operating channel over SNMP and show it as a pill, pruning the
// stale token from the displayed name (the stored name is left untouched - it stays authoritative).

/** "5790 MHz", "5790 · 40 MHz", or "60.5 GHz" (>= 10 GHz shown in GHz). Null when unknown. */
export function formatFreq(mhz: number | null | undefined, width?: number | null): string | null {
    if (mhz == null || mhz <= 0) return null;
    const base = mhz >= 10000 ? `${(mhz / 1000).toFixed(mhz % 1000 === 0 ? 0 : 1)} GHz` : `${mhz} MHz`;
    return width && width > 0 ? `${base} · ${width}` : base;
}

/**
 * Remove a stale frequency token from a device name for display only. Strips:
 *  - the strong "5825/40" / "5825-40" freq/width form (only when the leading number is a real
 *    2/3/5/6 GHz channel),
 *  - bare "5.8 GHz" style mentions,
 *  - and the exact live frequency number when we have one.
 * Deliberately conservative so it never eats a legitimate tower/host number.
 */
export function stripFreqToken(name: string, liveMhz?: number | null): string {
    let s = name
        .replace(/\b\d{3,5}\s*[/-]\s*\d{1,3}\b/g, (m) =>
            /^(24\d{2}|3\d{3}|4[89]\d{2}|5\d{3}|6[0-1]\d{2})/.test(m) ? '' : m,
        )
        .replace(/\b\d{1,2}(\.\d+)?\s?ghz\b/gi, '');
    if (liveMhz && liveMhz > 0) {
        for (const t of [String(liveMhz), String(Math.round(liveMhz / 10) * 10)]) {
            s = s.replace(new RegExp(`\\b${t}\\b`, 'g'), '');
        }
    }
    return s
        .replace(/\s{2,}/g, ' ')
        .replace(/[\s/,-]+$/, '')
        .trim();
}
