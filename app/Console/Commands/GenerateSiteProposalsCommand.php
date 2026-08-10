<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Propose device->site corrections from network topology.
 *
 * The signal, in LTD's network: it is ROUTED, not bridged, so a device's gateway sits
 * physically at its site and devices sharing a small subnet are on the same tower. If a
 * device's /29 (then /28, then /24) neighbours overwhelmingly sit at a different site
 * than the device does, the device is probably in the wrong place.
 *
 * Four exclusions, each of which produced real false positives before it was added:
 *
 *  1. POINT-TO-POINT LINK RADIOS. Both ends of a backhaul share one /30-/29, so a link
 *     subnet spans two sites BY DESIGN and neighbour voting drags a link radio to the far
 *     end. `Nietzel2RickPetersen` correctly lives at Doug Neitzel; the vote wanted to move
 *     it to Rick Petersen where its own counterpart sits. 28% of infra devices are link
 *     radios, so they are excluded as candidates AND as voters.
 *  2. CUSTOMER CPE. Named with a Sonar `ID:<digits>` tag or ONU/ONT; they live at
 *     subscriber premises and follow no site convention.
 *  3. PUBLIC ADDRESSES. A public IP carries no site locality - transit and service gear
 *     (a Chicago P2P circuit landing at a fiber drain site, nameservers, mail servers)
 *     legitimately sits anywhere.
 *  4. BRIDGED REGIONS. Where the network is still bridged rather than routed, subnet
 *     adjacency means nothing. Configure those prefixes in config('mymate.bridged_prefixes').
 *
 * Regenerating supersedes previous OPEN proposals rather than duplicating them; decided
 * proposals are left alone as the record of what a human already judged.
 */
class GenerateSiteProposalsCommand extends Command
{
    protected $signature = 'mymate:site-proposals:generate
                            {--min-peers=2 : how many subnet neighbours before a vote counts}
                            {--agreement=0.75 : share of neighbours that must agree}
                            {--dry-run : report without writing proposals}';

    protected $description = 'Propose device->site corrections from subnet adjacency';

    /** "A2B" / "A 2 B" - the backhaul link naming delimiter. */
    private const LINK_RE = '/([A-Za-z]{3,})\s*2\s*([A-Za-z]{3,})/';

    /** Sonar-tagged subscriber gear. */
    private const CPE_RE = '/id:\s*\d|onu|\bont\b/i';

    public function handle(): int
    {
        $minPeers = max(1, (int) $this->option('min-peers'));
        $agreement = (float) $this->option('agreement');
        $bridged = (array) config('mymate.bridged_prefixes', []);

        $rows = DB::table('devices as d')
            ->join('sites as s', 'd.site_id', '=', 's.id')
            ->whereNotNull('d.mgmt_ip')
            ->selectRaw('d.id, d.name, d.mgmt_ip::text as ip, d.site_id, s.name as site_name')
            ->get();

        $devices = [];
        foreach ($rows as $r) {
            $v = $this->ipToInt($r->ip);
            if ($v === null || $this->isPublic($r->ip) || $this->inBridgedRegion($r->ip, $bridged)) {
                continue;
            }
            if (preg_match(self::CPE_RE, $r->name)) {
                continue;
            }
            $devices[] = [
                'id' => (int) $r->id, 'name' => $r->name, 'ip' => $r->ip, 'v' => $v,
                'site_id' => (int) $r->site_id, 'site_name' => $r->site_name,
                'is_link' => (bool) preg_match(self::LINK_RE, $r->name),
            ];
        }
        $this->line(sprintf('%d devices eligible (%d link radios excluded from candidacy)',
            count($devices), count(array_filter($devices, fn ($d) => $d['is_link']))));

        // Bucket by mask once; link radios never vote.
        $buckets = [];
        foreach ([29, 28, 24] as $bits) {
            foreach ($devices as $d) {
                if ($d['is_link']) continue;
                $buckets[$bits][$d['v'] >> (32 - $bits)][] = $d;
            }
        }

        $runId = (string) Str::uuid();
        $proposals = [];

        foreach ($devices as $d) {
            if ($d['is_link']) continue;   // orientation comes from naming, not neighbours

            foreach ([29, 28, 24] as $bits) {
                $peers = array_filter($buckets[$bits][$d['v'] >> (32 - $bits)] ?? [],
                    fn ($p) => $p['id'] !== $d['id']);
                if (count($peers) < $minPeers) continue;

                $tally = [];
                foreach ($peers as $p) {
                    $tally[$p['site_id']] = ($tally[$p['site_id']] ?? 0) + 1;
                }
                arsort($tally);
                $topSite = (int) array_key_first($tally);
                $topCount = $tally[$topSite];

                if ($topCount / count($peers) < $agreement) break;   // no consensus at the tightest mask
                if ($topSite === $d['site_id']) break;               // neighbours agree with where it is

                $peerSiteName = collect($peers)->firstWhere('site_id', $topSite)['site_name'] ?? '';
                $nameAgrees = $this->nameSuggests($d['name'], $peerSiteName);

                $proposals[] = [
                    'device_id' => $d['id'],
                    'current_site_id' => $d['site_id'],
                    'suggested_site_id' => $topSite,
                    'confidence' => $nameAgrees ? 'high' : 'medium',
                    'signals' => json_encode([
                        'subnet' => ['mask' => "/{$bits}", 'agree' => $topCount, 'peers' => count($peers)],
                        'name' => ['agrees_with_suggestion' => $nameAgrees],
                    ]),
                    'rationale' => sprintf(
                        '%s (%s) sits at "%s", but %d of %d neighbours in its /%d are at "%s"%s.',
                        $d['name'], $d['ip'], $d['site_name'], $topCount, count($peers), $bits, $peerSiteName,
                        $nameAgrees ? ' - and the device name matches that site' : ''
                    ),
                    'status' => 'open',
                    'run_id' => $runId,
                    'created_at' => now(), 'updated_at' => now(),
                ];
                break;
            }
        }

        $high = count(array_filter($proposals, fn ($p) => $p['confidence'] === 'high'));
        $this->line(sprintf('%d proposals (%d high, %d medium)', count($proposals), $high, count($proposals) - $high));

        if ($this->option('dry-run')) {
            foreach (array_slice($proposals, 0, 15) as $p) {
                $this->line('  ' . $p['rationale']);
            }
            $this->warn('dry run - nothing written');

            return 0;
        }

        DB::transaction(function () use ($proposals, $runId) {
            // Previous undecided suggestions are stale the moment a new run disagrees.
            DB::table('device_site_proposals')->where('status', 'open')
                ->update(['status' => 'superseded', 'updated_at' => now()]);

            foreach (array_chunk($proposals, 500) as $chunk) {
                DB::table('device_site_proposals')->insert($chunk);
            }

            $this->line("run {$runId} written");
        });

        return 0;
    }

    private function nameSuggests(string $deviceName, string $siteName): bool
    {
        $norm = fn ($s) => preg_replace('/[^a-z0-9]/', '', strtolower($s));
        $dev = $norm($deviceName);
        foreach (preg_split('/\s+/', trim($siteName)) as $tok) {
            $t = $norm($tok);
            if (strlen($t) >= 4 && str_contains($dev, $t)) {
                return true;
            }
        }

        return false;
    }

    private function ipToInt(string $ip): ?int
    {
        $l = ip2long($ip);

        return $l === false ? null : $l;
    }

    private function isPublic(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }

    /** @param array<int,string> $prefixes */
    private function inBridgedRegion(string $ip, array $prefixes): bool
    {
        foreach ($prefixes as $cidr) {
            [$net, $bits] = array_pad(explode('/', $cidr), 2, '32');
            $n = ip2long($net);
            $v = ip2long($ip);
            if ($n !== false && $v !== false && ($v >> (32 - (int) $bits)) === ($n >> (32 - (int) $bits))) {
                return true;
            }
        }

        return false;
    }
}
