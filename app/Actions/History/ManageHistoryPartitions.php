<?php

namespace App\Actions\History;

use App\Support\Settings;
use Illuminate\Support\Carbon;
use App\Support\EngineLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Roll the daily history partitions forward and drop expired ones for every
 * RANGE-partitioned samples table (interface_samples + device_metric_samples).
 * Idempotent - safe to run repeatedly (loop cadence, scheduler, or
 * `mymate:loop --partitions`). Retention = drop partitions whose day is older than
 * `history.retention_days`.
 */
class ManageHistoryPartitions
{
    /**
     * Parent tables that are daily-partitioned; each partition is "{table}_YYYYMMDD".
     *
     * `rf_link_samples` MUST be here. It was created partitioned by its own migration but never
     * added to this list, so once the migration's hand-made partitions ran out (2026-08-07) every
     * RF sample was silently discarded - no error, no alert, and `rf_link_state` kept updating so
     * the current-values view looked perfectly healthy while five days of history evaporated.
     * The nightly rollup then had nothing to roll up, which stalled the 30-day RSSI baseline that
     * the wind/degradation detection is built on. Adding a partitioned table without adding it
     * here is a silent data-loss bug; there is no runtime check that would catch it.
     */
    private const TABLES = ['interface_samples', 'device_metric_samples', 'ping_samples', 'sensor_samples', 'rf_link_samples'];

    /** @return array{created:int, dropped:int} */
    public function __invoke(): array
    {
        $ahead = max(0, (int) config('mymate.history.partitions_ahead', 3));
        // Retention is operator-editable - read the live Settings value.
        $retentionDays = max(1, app(Settings::class)->getInt('history.retention_days', 14));
        $cutoff = now()->startOfDay()->subDays($retentionDays);

        $created = 0;
        $dropped = 0;
        foreach (self::TABLES as $table) {
            // Ensure [yesterday .. today+ahead] exist (yesterday covers writes that land
            // just after a UTC-midnight rollover).
            for ($i = -1; $i <= $ahead; $i++) {
                if ($this->ensureDailyPartition($table, now()->startOfDay()->addDays($i))) {
                    $created++;
                }
            }
            $dropped += $this->dropPartitionsBefore($table, $cutoff);
        }

        $this->warnAboutUnmanagedPartitionedTables();

        return ['created' => $created, 'dropped' => $dropped];
    }

    /** Create the daily partition for $day if absent. Returns true if it created one. */
    private function ensureDailyPartition(string $table, Carbon $day): bool
    {
        $name = $table.'_'.$day->format('Ymd');
        if (Schema::hasTable($name)) {
            return false;
        }

        $from = $day->format('Y-m-d 00:00:00');
        $to = $day->copy()->addDay()->format('Y-m-d 00:00:00');

        DB::statement(
            "CREATE TABLE IF NOT EXISTS \"{$name}\" PARTITION OF {$table} FOR VALUES FROM ('{$from}') TO ('{$to}')"
        );

        return true;
    }

    /** Drop every daily partition of $table whose day is strictly before $cutoffDay. */
    private function dropPartitionsBefore(string $table, Carbon $cutoffDay): int
    {
        $cutoff = (int) $cutoffDay->format('Ymd');
        $prefixLen = strlen($table) + 1; // "{table}_"
        $dropped = 0;

        foreach ($this->partitionNames($table) as $name) {
            $datePart = substr($name, $prefixLen);
            if (strlen($datePart) !== 8 || ! ctype_digit($datePart)) {
                continue; // not a YYYYMMDD daily partition - leave it alone
            }
            if ((int) $datePart < $cutoff) {
                DB::statement("DROP TABLE IF EXISTS \"{$name}\"");
                $dropped++;
            }
        }

        return $dropped;
    }

    /** @return list<string> child partition table names of $table */
    private function partitionNames(string $table): array
    {
        $rows = DB::select(<<<'SQL'
            SELECT c.relname AS name
            FROM pg_inherits i
            JOIN pg_class c ON c.oid = i.inhrelid
            JOIN pg_class p ON p.oid = i.inhparent
            WHERE p.relname = ?
        SQL, [$table]);

        return array_map(static fn ($r): string => $r->name, $rows);
    }

    /**
     * Shout if the database has a RANGE-partitioned table this class does not manage.
     *
     * `rf_link_samples` was added partitioned by its own migration but never listed in TABLES.
     * Its migration-made partitions covered 2026-07-30..08-07; the moment they ran out every
     * insert began failing and FIVE DAYS of RF history were lost silently - no exception
     * surfaced anywhere an operator would look, and the derived `rf_link_state` kept updating,
     * so every dashboard still looked healthy. Nothing in the system would ever have told us.
     *
     * This is deliberately noisy rather than clever: adding a partitioned table without adding
     * it here is data loss with no other symptom, so it warrants a log line every single run
     * until somebody fixes it.
     */
    private function warnAboutUnmanagedPartitionedTables(): void
    {
        try {
            $partitioned = DB::select(<<<'SQL'
                SELECT c.relname AS table_name
                  FROM pg_class c
                  JOIN pg_namespace n ON n.oid = c.relnamespace
                 WHERE c.relkind = 'p' AND n.nspname = current_schema()
                SQL);
        } catch (\Throwable) {
            return; // never let a diagnostic break partition maintenance
        }

        foreach ($partitioned as $row) {
            $name = (string) $row->table_name;
            if (! in_array($name, self::TABLES, true)) {
                EngineLog::warning('history: partitioned table is NOT managed - it will stop accepting writes when its existing partitions run out', [
                    'table' => $name,
                    'fix' => 'add it to ManageHistoryPartitions::TABLES',
                ]);
            }
        }
    }
}
