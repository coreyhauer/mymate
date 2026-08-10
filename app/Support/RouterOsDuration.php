<?php

namespace App\Support;

/**
 * Parse RouterOS duration strings into whole seconds.
 *
 * RouterOS reports every age/interval (`/ppp/active` uptime, `/system/resource` uptime,
 * lease times...) as a compact unit-suffixed string with the zero-valued leading units
 * omitted: "6s", "4m5s", "3h4m5s", "2w3d4h5m6s". Older/other paths also emit a clock form
 * ("1d 02:03:04" or "02:03:04"). Both shapes are handled here so callers never hand-roll it.
 *
 * Sub-second units (ms/us/ns) are recognised so they can't be mis-read as minutes ("500ms"
 * must not become 500 minutes) but contribute nothing to the whole-second result.
 */
final class RouterOsDuration
{
    private const UNIT_SECONDS = [
        'w' => 604800,
        'd' => 86400,
        'h' => 3600,
        'm' => 60,
        's' => 1,
    ];

    /**
     * @param  mixed  $value  the raw RouterOS field (string, or anything else -> null)
     * @return int|null whole seconds, or null when absent/unparseable
     */
    public static function toSeconds(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value >= 0 ? $value : null;
        }
        if (! is_string($value)) {
            return null;
        }

        $raw = trim(strtolower($value));
        if ($raw === '') {
            return null;
        }

        // Clock form: "[Nd ]HH:MM:SS" (also "HH:MM" / "N:MM:SS.mmm" defensively).
        if (preg_match('/^(?:(\d+)d\s*)?(\d+):(\d{1,2})(?::(\d{1,2}))?(?:\.\d+)?$/', $raw, $m) === 1) {
            return ((int) ($m[1] ?? 0)) * 86400
                + ((int) $m[2]) * 3600
                + ((int) $m[3]) * 60
                + ((int) ($m[4] ?? 0));
        }

        // Unit form. `ms|us|ns` MUST be matched before the bare `m`/`s` alternatives,
        // otherwise "500ms" reads as 500 minutes.
        if (preg_match_all('/(\d+)(ms|us|ns|w|d|h|m|s)/', $raw, $matches, PREG_SET_ORDER) === 0) {
            return null;
        }

        // Reject strings carrying anything the loop above didn't consume (e.g. "n/a", "5x") -
        // a partial parse is worse than admitting we don't know.
        $consumed = '';
        foreach ($matches as $match) {
            $consumed .= $match[0];
        }
        if ($consumed !== preg_replace('/\s+/', '', $raw)) {
            return null;
        }

        $seconds = 0;
        foreach ($matches as $match) {
            // Sub-second units are recognised (so they can't be misread) but truncated away.
            $seconds += ((int) $match[1]) * (self::UNIT_SECONDS[$match[2]] ?? 0);
        }

        return $seconds;
    }
}
