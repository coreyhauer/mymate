<?php

namespace App\Console\Commands;

use App\Services\Pppoe\PppoeSweepDispatcher;
use Illuminate\Console\Command;

/**
 * Queue a PPPoE active-session sweep across the concentrator fleet. Driven every 5 minutes by
 * the scheduler (routes/console.php), or by hand:
 *
 *   php artisan mymate:pppoe:sweep                  # whole fleet, staggered over the window
 *   php artisan mymate:pppoe:sweep --limit=5        # canary: first 5 concentrators only
 *   php artisan mymate:pppoe:sweep --device=1234    # one device (ignores the name filter)
 *   php artisan mymate:pppoe:sweep --now            # no stagger - fire every shard at once
 *   php artisan mymate:pppoe:sweep --dry-run        # count only, dispatch nothing
 *
 * This only *queues* work; the sweeping happens on the `pppoe` Horizon queue. `--limit`/`--now`
 * exist for the first prod run: a small immediate batch proves credentials, reachability and
 * parsing on real gear before the full fleet cadence is turned on.
 */
class SweepPppoeSessionsCommand extends Command
{
    protected $signature = 'mymate:pppoe:sweep
        {--limit= : dispatch only the first N concentrators (canary run)}
        {--device= : sweep a single device by id (ignores the name filter)}
        {--now : dispatch every shard immediately instead of staggering across the window}
        {--dry-run : report what would be dispatched, dispatch nothing}';

    protected $description = 'Queue a sweep of active PPPoE sessions across the concentrator fleet';

    public function handle(PppoeSweepDispatcher $dispatcher): int
    {
        if (! config('mymate.pppoe.enabled', true)) {
            $this->line('PPPoE session sweeping is disabled (MYMATE_PPPOE_ENABLED=false) - skipping.');

            return self::SUCCESS;
        }

        $limit = $this->option('limit') !== null ? max(1, (int) $this->option('limit')) : null;
        $deviceId = $this->option('device') !== null ? (int) $this->option('device') : null;
        // A canary/single-device run staggering over 4 minutes would just look broken.
        $stagger = ! $this->option('now') && $deviceId === null && $limit === null;

        if ($this->option('dry-run')) {
            $fleet = $dispatcher->fleetSize();
            $this->info("Fleet matching the PPPoE filter: {$fleet} concentrator(s). Nothing dispatched (--dry-run).");

            return self::SUCCESS;
        }

        $result = $dispatcher->dispatch($limit, $deviceId, $stagger);

        if ($result['devices'] === 0) {
            $this->warn('No PPPoE concentrators matched - nothing dispatched.');

            return self::SUCCESS;
        }

        $spread = $stagger && $result['step'] > 0
            ? sprintf(' staggered over %ds (~%.1fs between shards)', $result['window'], $result['step'])
            : ' with no stagger';

        $this->info("Queued {$result['shards']} shard job(s) covering {$result['devices']} concentrator(s){$spread}.");

        return self::SUCCESS;
    }
}
