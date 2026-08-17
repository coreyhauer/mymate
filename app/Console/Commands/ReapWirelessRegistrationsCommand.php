<?php

namespace App\Console\Commands;

use App\Support\EngineLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Delete wireless registration rows that no poll has refreshed in a very long time - RETENTION,
 * not staleness.
 *
 * Per-device pruning already happens inline on every NON-EMPTY successful read
 * (App\Actions\Polling\ReadWireless deletes whatever the reply did not contain), so this is
 * not the mechanism that removes a client that has genuinely left - that is immediate. This
 * catches what inline pruning structurally cannot: a radio that stops producing a NON-EMPTY
 * read at all any more - unmonitored, credential pulled, powered off, or (since an empty read
 * is untrusted here, unlike OSPF) simply left sitting client-less for good. Nothing else would
 * ever delete those rows. (Device deletion is covered by the FK cascade.)
 *
 * WHY THE CUTOFF IS DAYS, NOT A MULTIPLE OF THE FRESHNESS WINDOW. Until 2026-08-17 this
 * deleted anything older than `stale_after_minutes` x `reap_multiplier` (30 min x 4 = 2 h),
 * on the assumption that every radio is re-polled well inside that. The metrics lane is
 * sharded and rate-bounded, so on a real fleet it is not: a given AP can go unvisited for
 * more than two hours, and the hourly run then deleted a HEALTHY radio's whole client list
 * for the crime of being polled late (prod, 2026-08-17: ~50 radios / ~85 rows in one run).
 * Freshness is now purely a READ filter (the API's default `max_age_minutes`), and lifetime is
 * its own knob: `mymate.wireless.retention_days` (env MYMATE_WIRELESS_RETENTION_DAYS,
 * default 90). The latest observation of a (device, client) pair therefore survives with its
 * `last_seen_at` date - which is what downstream history consumers rank on - until it is
 * genuinely old.
 *
 * Scheduled hourly in routes/console.php, after ospf-neighbor-reap. Hourly is ample for a
 * cutoff measured in days; it just means the horizon is never more than an hour behind.
 *
 *   php artisan mymate:wireless:reap              # delete past the retention horizon
 *   php artisan mymate:wireless:reap --dry-run    # report what would go, delete nothing
 */
class ReapWirelessRegistrationsCommand extends Command
{
    protected $signature = 'mymate:wireless:reap
        {--dry-run : report what would be deleted, delete nothing}';

    protected $description = 'Delete wireless registration rows older than the retention horizon';

    public function handle(): int
    {
        $retentionDays = max(1, (int) config('mymate.wireless.retention_days', 90));
        $cutoff = now()->subDays($retentionDays);

        $query = DB::table('wireless_registrations')->where('last_seen_at', '<', $cutoff);

        if ($this->option('dry-run')) {
            $this->info("Would delete {$query->count()} wireless registration row(s) last seen before {$cutoff->toIso8601String()} (retention {$retentionDays}d). Nothing deleted (--dry-run).");

            return self::SUCCESS;
        }

        $deleted = $query->delete();

        if ($deleted > 0) {
            // Never silent: this is the only place rows leave the table without a device having
            // reported on them, so it should be visible when it happens.
            EngineLog::warning('wireless: reaped registrations past retention', [
                'deleted' => $deleted,
                'older_than' => $cutoff->toIso8601String(),
                'retention_days' => $retentionDays,
            ]);
        }

        $this->info("Deleted {$deleted} wireless registration row(s) (last seen before {$cutoff->toIso8601String()}, retention {$retentionDays}d).");

        return self::SUCCESS;
    }
}
