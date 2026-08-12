<?php

namespace App\Actions\Sites;

use App\Services\Import\LibreNms\LibreNmsMysqlSource;
use Illuminate\Support\Facades\DB;

/**
 * Imports the FIBER half of the backbone into `site_links` from LibreNMS LLDP adjacency.
 *
 * WHY: `site_links` only ever held the wireless mesh - 3,084 links, every one of them
 * `wireless`, zero fiber (checked 2026-08-11). So the map drew tower-to-tower radio shots and
 * simply stopped wherever fiber took over, which is why a whole region could go dark with no
 * visible cause. The 13 Hub -> Spann Loop -> Bucket Branch -> Sanders chain that took out 28
 * devices in Tennessee existed nowhere in My Mate; it had to be reconstructed by reading
 * switch port descriptions by hand.
 *
 * WHY LLDP AND NOT PORT DESCRIPTIONS: the descriptions are the obvious source ("Feed to
 * Sanders", "Feed in from Spann Loop") and they are how a human reads the topology, but only
 * ~69 ports fleet-wide carry one and a chunk of those are prose ("feed company", a tech's
 * note about a dead radio). LLDP gives 111k resolved adjacencies with both device ids
 * already joined, so it is both broader and unambiguous. Descriptions stay useful as the
 * human-readable label and are recorded in `endpoint_evidence`.
 *
 * WIRELESS IN AN SFP CAGE: Wave/airFiber gear has SFP+ uplinks, so a radio shot lands on a
 * fiber-looking port and LLDP shows the two switches either side as neighbours. The port
 * description is what gives it away ("AHennen MLO5", "keck wavePRO", "AF60XR to Chad Johnson"),
 * so descriptions naming radio hardware are rejected - those spans are already in the wireless
 * mesh and calling them fiber would misdraw the map twice over.
 *
 * SCOPE: only adjacency between devices that BOTH resolve to a My Mate site, and only where
 * the two sites differ - intra-site cabling (a switch stack in one cabinet) is not a backhaul
 * and would clutter the map. Links are keyed by `external_ref` = "lldp:<lo>-<hi>" so re-runs
 * update in place instead of duplicating, and so a hand-made link is never overwritten.
 */
class ImportFiberLinks
{
    /**
     * Source is injectable for tests; in production it is built from the same
     * `mymate.librenms_rf.*` credentials the RF pull uses (one LibreNMS, one set of creds).
     *
     * @return array{scanned:int,created:int,updated:int,skipped_no_site:int,skipped_same_site:int,skipped_wireless:int}
     */
    public function handle(bool $dryRun = false, ?LibreNmsMysqlSource $source = null): array
    {
        $source ??= self::sourceFromConfig();

        // My Mate device mgmt_ip -> site_id. LibreNMS keys devices by `hostname`, which on this
        // fleet is the management IP; where the two systems poll different addresses for the
        // same box (Nashville 2216 is 64.7.234.67 in LibreNMS, 10.213.0.101 in UISP) the link
        // is simply skipped rather than guessed at.
        $ipToSite = DB::table('devices')
            ->whereNotNull('mgmt_ip')->whereNotNull('site_id')
            ->pluck('site_id', 'mgmt_ip');

        $rows = $source->fiberAdjacency();

        $stats = ['scanned' => count($rows), 'created' => 0, 'updated' => 0,
            'skipped_no_site' => 0, 'skipped_same_site' => 0, 'skipped_wireless' => 0];
        $seen = [];

        foreach ($rows as $r) {
            if ($this->looksWireless($r['local_descr'])) {
                $stats['skipped_wireless']++;
                continue;
            }
            $aSite = $ipToSite[$r['local_ip']] ?? ($r['local_ip2'] !== '' ? ($ipToSite[$r['local_ip2']] ?? null) : null);
            $bSite = $ipToSite[$r['remote_ip']] ?? ($r['remote_ip2'] !== '' ? ($ipToSite[$r['remote_ip2']] ?? null) : null);

            if ($aSite === null || $bSite === null) {
                $stats['skipped_no_site']++;
                continue;
            }
            if ((int) $aSite === (int) $bSite) {
                $stats['skipped_same_site']++;
                continue;
            }

            // Order-independent key: LLDP reports both directions and we want one link.
            [$lo, $hi] = $aSite <= $bSite ? [$aSite, $bSite] : [$bSite, $aSite];
            $ref = "lldp:{$lo}-{$hi}";
            if (isset($seen[$ref])) {
                continue;
            }
            $seen[$ref] = true;

            if ($dryRun) {
                $stats['created']++;
                continue;
            }

            $existing = DB::table('site_links')->where('external_ref', $ref)->first();
            $payload = [
                'site_a_id' => $lo,
                'site_b_id' => $hi,
                'media_type' => 'fiber',
                'endpoint_evidence' => json_encode([
                    'source' => 'librenms-lldp',
                    'local' => $r['local_ip'].' '.$r['local_port'],
                    'remote' => $r['remote_ip'].' '.$r['remote_port'],
                    'descr' => $r['local_descr'] ?: null,
                ]),
                'updated_at' => now(),
            ];

            if ($existing) {
                DB::table('site_links')->where('id', $existing->id)->update($payload);
                $stats['updated']++;
            } else {
                DB::table('site_links')->insert($payload + [
                    'external_ref' => $ref,
                    'created_at' => now(),
                ]);
                $stats['created']++;
            }
        }

        return $stats;
    }

    /** Throws when LibreNMS isn't configured - callers should check `librenms_rf.enabled` first. */
    public static function sourceFromConfig(): LibreNmsMysqlSource
    {
        return new LibreNmsMysqlSource(
            (string) config('mymate.librenms_rf.host'),
            (int) config('mymate.librenms_rf.port', 3306),
            (string) config('mymate.librenms_rf.database', 'librenms'),
            (string) config('mymate.librenms_rf.username'),
            (string) config('mymate.librenms_rf.password'),
        );
    }

    /**
     * Does this port description name radio hardware? Wave/airFiber/LTU/AF60 all terminate on
     * SFP+, so the cage alone cannot tell fiber from a radio shot - the label can.
     */
    private function looksWireless(string $descr): bool
    {
        return $descr !== '' && preg_match(
            '/\b(wave|wavepro|waveXR|mlo\d?|af\s?60|af60|airfiber|af5|ltu|nano|gigabeam|60ghz|wtm\\d|ptp\s?\d)/i',
            $descr
        ) === 1;
    }
}
