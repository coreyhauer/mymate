<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Throwable;

/**
 * Parse a `?since=` delta cursor the read APIs accept, tolerantly but safely.
 *
 * Shared by every endpoint that offers `?since=` so they cannot drift apart on what they
 * accept, and so the three ways this has bitten are fixed in one place:
 *
 *  - EPOCH SCALE. Both seconds and milliseconds are accepted, told apart by magnitude: a value
 *    of 11 digits or fewer cannot plausibly be milliseconds (it would be 1970) and one longer
 *    cannot be seconds. Callers were previously required to know which the endpoint wanted.
 *  - OUT OF RANGE. Carbon does NOT throw on an absurd epoch - it happily builds year 292278994
 *    and hands it to the driver, where Postgres rejects it as "timestamp out of range" and the
 *    request 500s. Anything outside a sane window is rejected here, so it is a 422.
 *  - UNENCODED "+". An ISO8601 string carries its UTC offset as "+00:00", and a client that
 *    drops it into a query string without percent-encoding gets the "+" decoded as a SPACE by
 *    PHP - so a perfectly well-formed timestamp arrives as "2026-08-14T18:00:00 00:00" and is
 *    unparseable. Encoding it is the caller's job, but rejecting the most natural mistake a
 *    poller can make is a bad trade for an endpoint whose whole purpose is being polled.
 *
 * Returns null when the value cannot be parsed; the caller decides how to report that (every
 * current caller raises a 422).
 */
class SinceParam
{
    /** Nothing before this is a plausible cursor, and nothing after it fits a timestamp column. */
    private const MIN_YEAR = 1970;

    private const MAX_YEAR = 2200;

    public static function parse(string $value): ?Carbon
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        try {
            $parsed = ctype_digit($value)
                // >11 digits cannot be seconds; <=11 cannot be milliseconds.
                ? (strlen($value) > 11
                    ? Carbon::createFromTimestampMs((int) $value)
                    : Carbon::createFromTimestamp((int) $value))
                : Carbon::parse(self::restoreOffsetPlus($value));
        } catch (Throwable) {
            return null;
        }

        $year = (int) $parsed->year;
        if ($year < self::MIN_YEAR || $year > self::MAX_YEAR) {
            return null;
        }

        return $parsed;
    }

    /**
     * Put back the "+" that an unencoded ISO8601 offset loses to query-string decoding.
     *
     * Only touches the exact shape that decoding produces - a space directly before a bare
     * HH:MM (or HHMM) offset at the very end of the string. A space anywhere else, including
     * the legitimate "Y-m-d H:i:s" separator, is left alone.
     */
    private static function restoreOffsetPlus(string $value): string
    {
        return preg_replace('/ (\d{2}:?\d{2})$/', '+$1', $value) ?? $value;
    }
}
