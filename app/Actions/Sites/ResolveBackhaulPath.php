<?php

namespace App\Actions\Sites;

use Illuminate\Support\Facades\DB;

/**
 * Walks the backhaul graph from a site back to the fiber drain that feeds it, so the map can
 * draw "how does this tower actually get to the internet".
 *
 * WHY THIS EXISTS: during an outage the question is never "is this tower down", it is "what
 * else is behind the same break". A typical tower here is NINE wireless hops from fiber
 * (measured across the fleet 2026-08-11, max 26), which is far more than anyone holds in
 * their head, so the chain gets rediscovered by hand every time. Tracing Sanders by hand that
 * day - Sanders <- Bucket Branch <- Spann Loop <- 13 Hub - is what showed a single broken
 * fiber span had taken out 28 devices across four sites that looked like four faults.
 *
 * The walk is a breadth-first search over `site_links`, so it returns the FEWEST-HOP route to
 * a drain, and it terminates on `sites.is_fiber_drain`. Both media types are traversed:
 * wireless links come from the OSS import, fiber links from ImportFiberLinks. Hop count is
 * the only cost - we deliberately do not try to model which way traffic actually routes,
 * because a plausible-but-wrong path during an incident is worse than no path at all. What
 * this shows is the physical chain; if it disagrees with a live traceroute, the traceroute
 * wins and the topology data is what needs fixing.
 *
 * Returns null when the site is not in the graph at all (157 sites have no links) or when no
 * drain is reachable (a region whose fiber has not been imported yet) - the caller should say
 * so rather than draw a partial path that implies a route that does not exist.
 */
class ResolveBackhaulPath
{
    /** Hard stop so a pathological graph can never spin; real paths top out around 26. */
    private const MAX_HOPS = 60;

    /**
     * @return array{
     *     sites: list<array{id:int,name:string,lat:float|null,lng:float|null,is_drain:bool}>,
     *     links: list<array{id:int,media_type:string|null,device_a:string|null,device_b:string|null}>,
     *     hops: int,
     *     drain: array{id:int,name:string}|null,
     *     truncated: bool
     * }|null
     */
    public function handle(int $siteId): ?array
    {
        $sites = DB::table('sites')
            ->select('id', 'name', 'latitude', 'longitude', 'is_fiber_drain')
            ->get()->keyBy('id');

        if (! $sites->has($siteId)) {
            return null;
        }

        // Already standing on the drain: a zero-hop path is a real answer, not an error.
        if ($sites[$siteId]->is_fiber_drain) {
            return [
                'sites' => [$this->site($sites[$siteId])],
                'links' => [],
                'hops' => 0,
                'drain' => ['id' => $siteId, 'name' => $sites[$siteId]->name],
                'truncated' => false,
            ];
        }

        $links = DB::table('site_links as l')
            ->leftJoin('devices as da', 'da.id', '=', 'l.device_a_id')
            ->leftJoin('devices as db', 'db.id', '=', 'l.device_b_id')
            ->select('l.id', 'l.site_a_id', 'l.site_b_id', 'l.media_type', 'da.name as dev_a', 'db.name as dev_b')
            ->get();

        /** @var array<int, list<object>> $adj */
        $adj = [];
        foreach ($links as $l) {
            $adj[$l->site_a_id][] = $l;
            $adj[$l->site_b_id][] = $l;
        }

        // BFS. `cameFrom` stores the link we arrived on so the path can be rebuilt with the
        // radios/interfaces attached, which is what makes the drawn line actionable.
        $queue = [$siteId];
        $seen = [$siteId => true];
        $cameFrom = [];
        $found = null;
        $depth = [$siteId => 0];

        while ($queue !== [] && $found === null) {
            $current = array_shift($queue);
            if ($depth[$current] >= self::MAX_HOPS) {
                continue;
            }

            foreach ($adj[$current] ?? [] as $link) {
                $next = (int) ($link->site_a_id === $current ? $link->site_b_id : $link->site_a_id);
                if (isset($seen[$next]) || ! $sites->has($next)) {
                    continue;
                }
                $seen[$next] = true;
                $cameFrom[$next] = ['link' => $link, 'from' => $current];
                $depth[$next] = $depth[$current] + 1;

                if ($sites[$next]->is_fiber_drain) {
                    $found = $next;
                    break;
                }
                $queue[] = $next;
            }
        }

        if ($found === null) {
            return null;
        }

        $chainSites = [];
        $chainLinks = [];
        for ($node = $found; isset($cameFrom[$node]); $node = $cameFrom[$node]['from']) {
            $chainSites[] = $this->site($sites[$node]);
            $l = $cameFrom[$node]['link'];
            $chainLinks[] = [
                'id' => (int) $l->id,
                'media_type' => $l->media_type,
                'device_a' => $l->dev_a,
                'device_b' => $l->dev_b,
            ];
        }
        $chainSites[] = $this->site($sites[$siteId]);

        // Built drain-first while unwinding; the caller wants tower -> drain.
        $chainSites = array_reverse($chainSites);
        $chainLinks = array_reverse($chainLinks);

        return [
            'sites' => $chainSites,
            'links' => $chainLinks,
            'hops' => count($chainLinks),
            'drain' => ['id' => (int) $found, 'name' => $sites[$found]->name],
            'truncated' => false,
        ];
    }

    /** @return array{id:int,name:string,lat:float|null,lng:float|null,is_drain:bool} */
    private function site(object $s): array
    {
        return [
            'id' => (int) $s->id,
            'name' => (string) $s->name,
            'lat' => $s->latitude === null ? null : (float) $s->latitude,
            'lng' => $s->longitude === null ? null : (float) $s->longitude,
            'is_drain' => (bool) $s->is_fiber_drain,
        ];
    }
}
