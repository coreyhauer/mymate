<?php

namespace App\Console\Commands;

use App\Actions\Rf\CheckLinkLineOfSight;
use App\Actions\Rf\ComputeSignalDeficit;
use Illuminate\Console\Command;

/**
 * Recompute the RF health overlays so a REPAIRED LINK CLEARS ITSELF.
 *
 * Jake asked the question that exposed this: "what happens after we fix a link - do we need to
 * wait for the db to update or something for the lines to go away?" The answer was no, and that
 * was a defect: rf_link_state refreshes every five minutes, but the DEFICIT derived from it was
 * only ever computed by hand, so the map would have kept a fixed link coloured red indefinitely.
 *
 * Two stages, deliberately on different cadences:
 *
 *  - DEFICIT (hourly). Cheap, pure arithmetic over data already in the database. An hour is well
 *    inside the time it takes a tech to climb down, so a repair shows up on its own.
 *  - LINE OF SIGHT (daily, and only for links that currently look bad). Each call builds a terrain
 *    profile, and terrain does not change hour to hour. Re-checking is only needed when a link
 *    starts underperforming or is newly created.
 *
 * `--los` forces the line-of-sight pass; by default the command only recomputes deficits, which is
 * the part that has to be fresh.
 */
class RfHealthRefreshCommand extends Command
{
    protected $signature = 'mymate:rf:refresh {--los : also re-check line of sight for underperforming links}
                                              {--min-deficit=6.0 : dB threshold for the LOS pass}
                                              {--limit=300 : max links to LOS-check in one run}';

    protected $description = 'Recompute signal deficits (and optionally LOS) so repaired links clear on their own.';

    public function handle(ComputeSignalDeficit $deficit, CheckLinkLineOfSight $los): int
    {
        $d = $deficit->handle();
        $lap = $d['lapgps_backhauls'] ?? [];
        unset($d['lapgps_backhauls'], $d['calibration']);
        $this->line('deficit: '.collect($d)->map(fn ($v, $k) => "$k=$v")->implode(' '));

        // A backhaul terminating on a LiteAP sector is almost certainly a forgotten temporary
        // setup - Corey asked for those to be surfaced rather than silently modelled.
        if ($lap !== []) {
            $this->warn('backhauls landing on a LiteAP sector (possible temp installs): '.count($lap));
            foreach (array_slice($lap, 0, 10) as $n) {
                $this->line('   '.$n);
            }
        }

        if ($this->option('los')) {
            $r = $los->handle((float) $this->option('min-deficit'), (int) $this->option('limit'));
            $this->line('los: '.collect($r)->map(fn ($v, $k) => "$k=$v")->implode(' '));
        }

        return self::SUCCESS;
    }
}
