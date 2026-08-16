<?php

namespace App\Console\Commands;

use App\Support\EngineLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Delete wireless registration rows that no poll has refreshed in a long time.
 *
 * Per-device pruning already happens inline on every NON-EMPTY successful read
 * (App\Actions\Polling\ReadWireless deletes whatever the reply did not contain), so this is
 * not the mechanism that removes a client that has genuinely left - that is immediate. This
 * catches what inline pruning structurally cannot: a radio that stops producing a NON-EMPTY
 * read at all any more - unmonitored, credential pulled, powered off, or (since an empty read
 * is untrusted here, unlike OSPF) simply left sitting client-less for good. Nothing else would
 * ever delete those rows. (Device deletion is covered by the FK cascade.)
 *
 * The read API hides rows past `mymate.wireless.stale_after_minutes` first, so anything this
 * deletes has already been invisible for `reap_multiplier` times that long - deletion is the
 * long-stop, not the mechanism. Scheduled hourly in routes/console.php, after ospf-neighbor-reap.
 *
 *   php artisan mymate:wireless:reap              # delete past the cutoff
 *   php artisan mymate:wireless:reap --dry-run    # report what would go, delete nothing
 */
class ReapWirelessRegistrationsCommand extends Command
{
    protected $signature = 'mymate:wireless:reap
        {--dry-run : report what would be deleted, delete nothing}';

    protected $description = 'Delete wireless registration rows for radios that stopped reporting entirely';

    public function handle(): int
    {
        $staleAfter = max(1, (int) config('mymate.wireless.stale_after_minutes', 30));
        $multiplier = max(1, (int) config('mymate.wireless.reap_multiplier', 4));
        $cutoff = now()->subMinutes($staleAfter * $multiplier);

        $query = DB::table('wireless_registrations')->where('last_seen_at', '<', $cutoff);

        if ($this->option('dry-run')) {
            $this->info("Would delete {$query->count()} wireless registration row(s) last seen before {$cutoff->toIso8601String()}. Nothing deleted (--dry-run).");

            return self::SUCCESS;
        }

        $deleted = $query->delete();

        if ($deleted > 0) {
            // Never silent: this is the only place rows leave the table without a device having
            // reported on them, so it should be visible when it happens.
            EngineLog::warning('wireless: reaped stale registrations', [
                'deleted' => $deleted,
                'older_than' => $cutoff->toIso8601String(),
            ]);
        }

        $this->info("Deleted {$deleted} stale wireless registration row(s) (last seen before {$cutoff->toIso8601String()}).");

        return self::SUCCESS;
    }
}
