<?php

namespace App\Actions\Polling;

use App\Support\EngineLog;
use App\Support\RouterOsDuration;
use Illuminate\Support\Facades\DB;

/**
 * Persist the per-client wireless registration-table rows that
 * App\Services\Polling\RouterOsDeviceMetricsDriver already reads on every metrics poll of every
 * RouterOS radio (see the wireless_registrations migration) - the OSPF pattern (ReadOspf),
 * not the PPPoE sweep pattern: a side effect of a poll that already runs, not a new
 * sweep/dispatcher/queue.
 *
 * Unlike ReadOspf, this class does not open its own RouterOS connection - the rows are read as
 * part of the existing metrics connection and handed in by App\Actions\Polling\PollDeviceMetrics.
 * `persist()` is therefore the entry point (mirrors ReadOspf's private `persist()`, just public
 * because there is no read step of its own here to wrap it with).
 */
class ReadWireless
{
    /**
     * Reconcile one device's wireless clients: upsert what the read returned, delete what a
     * NON-EMPTY read did not (an empty read is untrusted - see below), both in one transaction,
     * stamped at commit time. Never throws and never costs the caller its metrics reading - a
     * DB problem here is logged and swallowed, same contract as ReadOspf's persist step.
     *
     * @param  array<int, array<string, mixed>>  $rows  raw RouterOS registration-table rows
     */
    public function persist(int $deviceId, array $rows): void
    {
        if (! config('mymate.wireless.persist', true)) {
            return;
        }

        try {
            $this->persistRows($deviceId, $rows);
        } catch (\Throwable $e) {
            EngineLog::warning('wireless: registration persist failed', [
                'device_id' => $deviceId,
                'rows' => count($rows),
                'exception' => $e::class,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * ON THE EMPTY READ. This mirrors PPPoE, not OSPF: a registration table with zero rows is
     * exactly what a black-holing radio or a transient API hiccup produces, and - unlike OSPF,
     * which can tell "no OSPF configured" from "OSPF configured but no adjacencies" by whether
     * any OSPF interface exists - there is no equivalent signal here to tell "genuinely no
     * clients right now" from "could not read". So an empty read prunes nothing; existing rows
     * are left in place and age out through the staleness filter and `mymate:wireless:reap` if
     * the radio never reports again.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function persistRows(int $deviceId, array $rows): void
    {
        if ($rows === []) {
            return;
        }

        DB::transaction(function () use ($deviceId, $rows): void {
            // Whole seconds: last_seen_at is timestamptz(0) by convention here, and a value
            // carrying microseconds would not equal the value the prune compares against -
            // which would delete the rows this poll just wrote.
            $seenAt = now()->startOfSecond();

            $unique = [];
            foreach ($rows as $row) {
                $shaped = self::shape($deviceId, $row, $seenAt);
                // A row with no usable MAC is not a registration. Seen live 2026-08-16: a
                // registration-table path a board does not have (wifiwave2 / CAPsMAN on a
                // classic-wireless radio) answers with an API !trap that the client library
                // surfaces as a plain row ({message, category}) rather than throwing - which
                // shaped to mac_address '' and, keyed by '', left ONE junk row per device.
                // Skip it here (belt) and in the driver (braces); never persist a blank key.
                if ($shaped['mac_address'] === '') {
                    continue;
                }
                // Two rows on the same natural key in one batch would make Postgres reject the
                // whole statement ("ON CONFLICT DO UPDATE command cannot affect row a second
                // time") and sink an otherwise-good device (e.g. the same client showing up in
                // both the classic and wifiwave2 registration tables). Last one wins.
                $unique[$shaped['mac_address']] = $shaped;
            }
            $unique = array_values($unique);
            if ($unique === []) {
                // Every row was junk (see above) - indistinguishable from an empty read, and an
                // empty read is untrusted (PPPoE's rule): keep what we had, prune nothing.
                return;
            }

            foreach (array_chunk($unique, 500) as $chunk) {
                DB::table('wireless_registrations')->upsert(
                    $chunk,
                    ['device_id', 'mac_address'],
                    [
                        'interface', 'signal_strength_dbm', 'signal_to_noise_db', 'tx_ccq_pct',
                        'tx_rate', 'rx_rate', 'uptime_seconds', 'last_activity_seconds', 'last_seen_at',
                        // first_seen_at is deliberately OMITTED here - insert-only, so a
                        // continuing client's "how long have they actually been coming back"
                        // survives every subsequent poll rather than resetting to "just now".
                    ],
                );
            }

            // Everything this device carries that this (non-empty) read did not return is a
            // client that has left. Matched on mac_address rather than on "last_seen_at older
            // than this poll", for the same reason as ReadOspf: two polls landing inside the
            // same second would otherwise prune nothing.
            DB::table('wireless_registrations')
                ->where('device_id', $deviceId)
                ->whereNotIn('mac_address', array_column($unique, 'mac_address'))
                ->delete();
        });
    }

    /**
     * Shape one raw registration-table row into an insert row. `first_seen_at` is only ever
     * consulted for a brand new row (see the upsert's conflict-update list above) - stamping it
     * here unconditionally is harmless and keeps this function pure.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private static function shape(int $deviceId, array $row, \DateTimeInterface $seenAt): array
    {
        return [
            'device_id' => $deviceId,
            'interface' => self::text($row['interface'] ?? null) ?? '',
            // '' rather than null - this forms the unique key, and a NULL never conflicts.
            'mac_address' => self::normalizeMac($row['mac-address'] ?? null),
            'signal_strength_dbm' => self::firstNumber($row['signal-strength'] ?? null),
            'signal_to_noise_db' => self::firstNumber($row['signal-to-noise'] ?? null),
            'tx_ccq_pct' => self::firstNumber($row['tx-ccq'] ?? null),
            'tx_rate' => self::text($row['tx-rate'] ?? null) ?? '',
            'rx_rate' => self::text($row['rx-rate'] ?? null) ?? '',
            'uptime_seconds' => RouterOsDuration::toSeconds($row['uptime'] ?? null),
            'last_activity_seconds' => RouterOsDuration::toSeconds($row['last-activity'] ?? null),
            'first_seen_at' => $seenAt,
            'last_seen_at' => $seenAt,
        ];
    }

    /**
     * Normalize a RouterOS MAC (or anything a caller hands it, e.g. an `?mac=` query param)
     * into lowercase-colon form ("aa:bb:cc:dd:ee:ff"). Anything that isn't exactly 12 hex
     * digits once separators are stripped normalizes to '' - never NULL, so callers that use
     * this to shape a natural-key column never have to special-case a bad reply.
     */
    public static function normalizeMac(mixed $value): string
    {
        if (! is_string($value)) {
            return '';
        }

        $hex = strtolower(preg_replace('/[^0-9a-fA-F]/', '', $value) ?? '');
        if (strlen($hex) !== 12) {
            return '';
        }

        return implode(':', str_split($hex, 2));
    }

    /** First signed/decimal number in a value (e.g. "-65dBm@6Mbps" -> -65.0), or null. */
    private static function firstNumber(mixed $value): ?float
    {
        if ($value === null) {
            return null;
        }

        return preg_match('/-?\d+(\.\d+)?/', (string) $value, $m) === 1 ? (float) $m[0] : null;
    }

    /** Trim a RouterOS field, collapsing empty/absent to null. */
    private static function text(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
