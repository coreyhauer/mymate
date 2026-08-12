<?php

namespace App\Console\Commands;

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
    protected $signature = 'sites:derive-fiber-drains
        {--dry-run : Report what would change without writing}
        {--mark= : Mark this site id as a MANUAL drain (never overwritten by derivation)}
        {--unmark= : Remove a manual drain flag from this site id}';

    protected $description = 'Mark sites as fiber drains based on fiber adjacency to an NNI aggregator';

    public function handle(): int
    {
        if ($this->option('mark') !== null || $this->option('unmark') !== null) {
            return $this->setManual();
        }

        if (! config('mymate.librenms_rf.enabled', false)) {
            $this->error('LibreNMS is not configured (mymate.librenms_rf) - drains are derived from its LLDP data.');

            return self::FAILURE;
        }
        $source = \App\Actions\Sites\ImportFiberLinks::sourceFromConfig();

        $nnis = collect(config('mymate.fiber_nnis', []))->filter()->values();
        if ($nnis->isEmpty()) {
            $this->error('No NNIs configured (mymate.fiber_nnis) - refusing to derive drains from nothing.');

            return self::FAILURE;
        }

        $ipToSite = DB::table('devices')
            ->whereNotNull('mgmt_ip')->whereNotNull('site_id')
            ->pluck('site_id', 'mgmt_ip');

        // Name fallback for routers the two systems address differently (see fiberAdjacency).
        $nameToSite = [];
        foreach (DB::table('devices')->whereNotNull('site_id')->select('name', 'site_id')->get() as $d) {
            $nameToSite[mb_strtolower(trim($d->name))] = $d->site_id;
        }

        // false = include shared-segment adjacency; a drain is a drain however it reaches the NNI.
        $adjacency = $source->fiberAdjacency(false);

        $seenNni = [];
        $drainSites = [];
        foreach ($adjacency as $r) {
            foreach ([
                [$r['local_ip'], $r['remote_ip'], $r['local_ip2'], $r['local_name']],
                [$r['remote_ip'], $r['local_ip'], $r['remote_ip2'], $r['remote_name']],
            ] as [$near, $far, $nearAlt, $nearName]) {
                if (! $nnis->contains($far)) {
                    continue;
                }
                $seenNni[$far] = true;
                // The NNI's own site is a drain too - Southfront both aggregates and drains.
                foreach ([$near, $far, $nearAlt] as $ip) {
                    if ($ip !== '' && isset($ipToSite[$ip])) {
                        $drainSites[(int) $ipToSite[$ip]] = true;
                    }
                }
                $key = mb_strtolower(trim($nearName));
                if ($key !== '' && isset($nameToSite[$key])) {
                    $drainSites[(int) $nameToSite[$key]] = true;
                }
            }
        }

        foreach ($nnis as $ip) {
            if (! isset($seenNni[$ip])) {
                $this->warn("NNI {$ip} has no LLDP adjacency in LibreNMS - everything behind it will have NO drain.");
            }
        }

        $ids = array_keys($drainSites);

        // Hand-set drains are never touched: some are invisible to the derivation (Mankato's
        // carrier handoff exposes no LLDP adjacency to an NNI), and silently clearing an
        // operator's flag overnight would be worse than not deriving at all.
        $manual = DB::table('sites')->where('fiber_drain_source', 'manual')->pluck('name', 'id');
        if ($manual->isNotEmpty()) {
            $this->line(sprintf('  (%d manual drain%s preserved: %s)',
                $manual->count(), $manual->count() === 1 ? '' : 's', $manual->values()->implode(', ')));
        }

        $current = DB::table('sites')->where('is_fiber_drain', true)
            ->where(fn ($q) => $q->where('fiber_drain_source', '!=', 'manual')->orWhereNull('fiber_drain_source'))
            ->pluck('name', 'id');
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
            DB::table('sites')
                ->where('is_fiber_drain', true)
                ->where(fn ($q) => $q->where('fiber_drain_source', '!=', 'manual')->orWhereNull('fiber_drain_source'))
                ->update(['is_fiber_drain' => false, 'fiber_drain_source' => null]);

            if ($ids !== []) {
                DB::table('sites')->whereIn('id', $ids)
                    ->where(fn ($q) => $q->where('fiber_drain_source', '!=', 'manual')->orWhereNull('fiber_drain_source'))
                    ->update(['is_fiber_drain' => true, 'fiber_drain_source' => 'derived']);
            }
        });

        $this->info('Written.');

        return self::SUCCESS;
    }

    /** Hand-set (or clear) a drain the derivation cannot see. */
    private function setManual(): int
    {
        $mark = $this->option('mark');
        $id = (int) ($mark ?? $this->option('unmark'));
        $site = DB::table('sites')->where('id', $id)->first();

        if (! $site) {
            $this->error("No site #{$id}.");

            return self::FAILURE;
        }

        if ($mark !== null) {
            DB::table('sites')->where('id', $id)->update(['is_fiber_drain' => true, 'fiber_drain_source' => 'manual']);
            $this->info("{$site->name} (#{$id}) marked as a MANUAL fiber drain - derivation will not clear it.");
        } else {
            DB::table('sites')->where('id', $id)->update(['is_fiber_drain' => false, 'fiber_drain_source' => null]);
            $this->info("{$site->name} (#{$id}) manual drain flag removed.");
        }

        return self::SUCCESS;
    }
}
