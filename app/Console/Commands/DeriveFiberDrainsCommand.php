<?php

namespace App\Console\Commands;

use App\Services\Import\LibreNms\LibreNmsMysqlSource;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Derives `sites.is_fiber_drain` from fiber adjacency to an NNI aggregator.
 *
 * A drain is where a region's backhaul gets on fiber toward a handoff. Empirically that is
 * exactly "one fiber hop from an NNI": deriving it this way on 2026-08-11 reproduced the
 * operator's own list unprompted - marshall, nerstrand, windom, erdahl, durst, glenville,
 * cambridge, plus burchinal/pitch/meier landing on Waterloo - while correctly EXCLUDING
 * Braham (drains onward to Cambridge), Pine City, and Dallas (a tail fiber feeding back
 * toward Waverly). It is derived rather than hand-listed so it stays true as fiber moves.
 *
 * The NNIs themselves live in config('mymate.fiber_nnis'). There are four: Southfront 2216,
 * Waterloo 2216, Nashville 2216 (365 Datacenter, downtown Nashville - the TN handoff to
 * Hurricane Electric) and Chicago CH3 (Equinix CH3, QuickPacket colo - where the Farina and
 * Bluff circuits drain). Miss one and every site behind it silently loses its drain, so the
 * command reports any NNI it could not find rather than quietly carrying on.
 *
 * Run with --dry-run first: this flag is what the Path walk terminates on, so a bad derivation
 * shows up as paths that run to the wrong side of the network.
 */
class DeriveFiberDrainsCommand extends Command
{
    protected $signature = 'sites:derive-fiber-drains {--dry-run : Report what would change without writing}';

    protected $description = 'Mark sites as fiber drains based on fiber adjacency to an NNI aggregator';

    public function handle(LibreNmsMysqlSource $source): int
    {
        $nnis = collect(config('mymate.fiber_nnis', []))->filter()->values();
        if ($nnis->isEmpty()) {
            $this->error('No NNIs configured (mymate.fiber_nnis) - refusing to derive drains from nothing.');

            return self::FAILURE;
        }

        $ipToSite = DB::table('devices')
            ->whereNotNull('mgmt_ip')->whereNotNull('site_id')
            ->pluck('site_id', 'mgmt_ip');

        $adjacency = $source->fiberAdjacency();

        $seenNni = [];
        $drainSites = [];
        foreach ($adjacency as $r) {
            foreach ([[$r['local_ip'], $r['remote_ip']], [$r['remote_ip'], $r['local_ip']]] as [$near, $far]) {
                if (! $nnis->contains($far)) {
                    continue;
                }
                $seenNni[$far] = true;
                // The NNI's own site is a drain too - Southfront both aggregates and drains.
                foreach ([$near, $far] as $ip) {
                    if (isset($ipToSite[$ip])) {
                        $drainSites[(int) $ipToSite[$ip]] = true;
                    }
                }
            }
        }

        foreach ($nnis as $ip) {
            if (! isset($seenNni[$ip])) {
                $this->warn("NNI {$ip} has no LLDP adjacency in LibreNMS - everything behind it will have NO drain.");
            }
        }

        $ids = array_keys($drainSites);
        $current = DB::table('sites')->where('is_fiber_drain', true)->pluck('name', 'id');
        $adding = array_diff($ids, $current->keys()->all());
        $removing = array_diff($current->keys()->all(), $ids);

        $this->info(sprintf('%d drain sites derived from %d NNIs (%d new, %d no longer adjacent).',
            count($ids), $nnis->count(), count($adding), count($removing)));

        foreach (DB::table('sites')->whereIn('id', $adding)->orderBy('name')->pluck('name', 'id') as $id => $name) {
            $this->line("  + {$name} (#{$id})");
        }
        foreach ($removing as $id) {
            $this->line("  - {$current[$id]} (#{$id})");
        }

        if ($this->option('dry-run')) {
            $this->comment('Dry run - nothing written.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($ids) {
            DB::table('sites')->where('is_fiber_drain', true)->update(['is_fiber_drain' => false]);
            if ($ids !== []) {
                DB::table('sites')->whereIn('id', $ids)->update(['is_fiber_drain' => true]);
            }
        });

        $this->info('Written.');

        return self::SUCCESS;
    }
}
