<?php

namespace App\Console\Commands;

use App\Support\EngineLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Delete OSPF adjacencies that no poll has refreshed in a long time.
 *
 * Per-device pruning already happens inline on every successful read (ReadOspf deletes whatever
 * the reply did not contain), so this is not the mechanism that removes a dropped adjacency -
 * that is immediate. This catches the case inline pruning structurally cannot: a device that
 * produces no successful read AT ALL any more, because it was unmonitored, had its credential
 * pulled, stopped answering, or had OSPF removed entirely. Nothing else would ever delete its
 * rows. (Device deletion is covered by the FK cascade.)
 *
 * The read API hides rows past `mymate.ospf.stale_after_minutes` first, so anything this
 * deletes has already been invisible for `reap_multiplier` times that long - deletion is the
 * long-stop, not the mechanism. Scheduled hourly in routes/console.php.
 *
 *   php artisan mymate:ospf:reap              # delete past the cutoff
 *   php artisan mymate:ospf:reap --dry-run    # report what would go, delete nothing
 */
class ReapOspfNeighborsCommand extends Command
{
    protected $signature = 'mymate:ospf:reap
        {--dry-run : report what would be deleted, delete nothing}';

    protected $description = 'Delete OSPF neighbour rows for devices that stopped reporting entirely';

    public function handle(): int
    {
        $staleAfter = max(1, (int) config('mymate.ospf.stale_after_minutes', 30));
        $multiplier = max(1, (int) config('mymate.ospf.reap_multiplier', 8));
        $cutoff = now()->subMinutes($staleAfter * $multiplier);

        $query = DB::table('ospf_neighbors')->where('last_seen_at', '<', $cutoff);

        if ($this->option('dry-run')) {
            $this->info("Would delete {$query->count()} OSPF neighbour row(s) last seen before {$cutoff->toIso8601String()}. Nothing deleted (--dry-run).");

            return self::SUCCESS;
        }

        $deleted = $query->delete();

        if ($deleted > 0) {
            // Never silent: this is the only place rows leave the table without a device having
            // reported on them, so it should be visible when it happens.
            EngineLog::warning('ospf: reaped stale neighbours', [
                'deleted' => $deleted,
                'older_than' => $cutoff->toIso8601String(),
            ]);
        }

        $this->info("Deleted {$deleted} stale OSPF neighbour row(s) (last seen before {$cutoff->toIso8601String()}).");

        return self::SUCCESS;
    }
}
