<?php

namespace App\Actions\Sites;

use App\Models\SiteLink;
use Illuminate\Support\Facades\DB;

/**
 * CREATE missing site_links from the `alpha2bravo` device naming convention.
 *
 * Sibling to ResolveSiteLinkEndpoints, and deliberately the other half of the job: that action
 * fills in WHICH DEVICES carry a link we already know about; this one discovers that a link
 * EXISTS AT ALL. It reuses that class's matcher rather than growing a second one.
 *
 * WHY THIS EXISTS (2026-08-13): site_links are imported from UISP's `data_link` table, and that
 * table is incomplete. Dennis Loucks reported "No fiber drain reachable" on the map for a region
 * whose fiber is fully imported - the real cause was that its AF5xHD backhaul to Grotz had NO
 * site_link, because UISP had no data_link row for it. A sweep found 26 more of the same, and
 * creating them dropped the fleet's unreachable-site count from 132 to 120. Depending on UISP's
 * link table being complete is therefore not safe; the naming convention is the better source.
 *
 * PRECISION - why this does not repeat the fuzzy `<a>2<b>` disaster
 * ------------------------------------------------------------------
 * A previous detector matched one side only and scored 96% false positives; a first cut of THIS
 * sweep matched `Kolb2Burg` against `prinsburg`, `sunburg` and `fredericksburg`, because
 * ResolveSiteLinkEndpoints::tokMatch() is a two-way substring test. That looseness is fine there
 * (the two candidate sites are already fixed by the link being resolved) and dangerous here (we
 * search all ~2,650 sites). Three guards make the difference:
 *
 *   1. RECIPROCITY. A device at site A must name site B, AND a device at site B must name site A.
 *      Both radios of a real link are named for it; a coincidental substring is not mirrored.
 *   2. PLACEMENT AGREEMENT. Each device's SELF token must match the site it is actually assigned
 *      to. A device at A named `B2A` claims to live elsewhere - per ResolveSiteLinkEndpoints that
 *      is a contradiction and evidence of a misfile, never evidence of a link.
 *   3. UNAMBIGUOUS FAR END. If the other-token matches more than one site by the same strength,
 *      the pair is skipped rather than guessed - that ambiguity IS the `burg` failure mode.
 *
 * Guard 2 is also why this is safe to run standing: the Dennis Loucks device was MISFILED, and
 * under guard 2 a misfiled device produces no link at all rather than a wrong one. Fixing the
 * misfile is what let the link be derived - the two stay honest about each other.
 *
 * Never touches `devices` - placement is a separate concern with its own guardrails.
 */
class DeriveSiteLinksFromNaming
{
    /** Sites further apart than this are not a plausible single RF hop; longest real link is ~40 km. */
    private const MAX_PLAUSIBLE_KM = 60.0;

    /**
     * @return array{created: int, skipped_ambiguous: int, skipped_far: int, candidates: int, links: array<int, array<string, mixed>>}
     */
    public function handle(bool $dryRun = false): array
    {
        $sites = collect(DB::select('SELECT id, name, latitude, longitude FROM sites'));

        // buildFuzzyIndex wants each site pre-shaped as ['id' => int, 'words' => string[]],
        // not a raw row.
        $fuzzyIndex = ResolveSiteLinkEndpoints::buildFuzzyIndex(
            $sites->map(fn (object $s): array => [
                'id' => (int) $s->id,
                'words' => ResolveSiteLinkEndpoints::siteWords((string) $s->name),
            ])
        );

        $nnameById = [];
        $siteById = [];
        foreach ($sites as $s) {
            $nnameById[$s->id] = ResolveSiteLinkEndpoints::normalize((string) $s->name);
            $siteById[$s->id] = $s;
        }

        $devices = DB::select('SELECT id, name, site_id FROM devices WHERE site_id IS NOT NULL AND name IS NOT NULL');

        // claim[selfSiteId][otherToken] = device — a device that lives where its name says it does
        $claims = [];
        foreach ($devices as $d) {
            $ownNname = $nnameById[$d->site_id] ?? null;
            if ($ownNname === null) {
                continue;
            }

            foreach (ResolveSiteLinkEndpoints::parsePairs((string) $d->name) as $pair) {
                // Guard 2: the SELF token must agree with where the device actually is.
                // Suffix-stripping is never applied to the self token (see sibling class docblock).
                $self = ResolveSiteLinkEndpoints::matchToken($pair['t1'], $ownNname, (int) $d->site_id, false, $fuzzyIndex);
                if ($self === null) {
                    continue;
                }

                $other = ResolveSiteLinkEndpoints::normalize($pair['t2']);
                $stripped = ResolveSiteLinkEndpoints::stripRoleSuffix($other);
                foreach (array_unique(array_filter([$other, $stripped])) as $tok) {
                    if (strlen($tok) >= 4) {
                        $claims[(int) $d->site_id][$tok] = $d;
                    }
                }
            }
        }

        $linked = [];
        foreach (DB::select('SELECT site_a_id, site_b_id FROM site_links') as $l) {
            $linked[$l->site_a_id.'-'.$l->site_b_id] = true;
            $linked[$l->site_b_id.'-'.$l->site_a_id] = true;
        }

        $out = ['created' => 0, 'skipped_ambiguous' => 0, 'skipped_far' => 0, 'candidates' => 0, 'links' => []];
        $seen = [];

        foreach ($claims as $siteA => $tokens) {
            foreach ($tokens as $tok => $devA) {
                // Which sites could `$tok` mean? Guard 3: exactly one, or we skip.
                $targets = [];
                foreach ($nnameById as $sid => $nname) {
                    if ($sid !== $siteA && ResolveSiteLinkEndpoints::tokMatch($tok, $nname)) {
                        $targets[] = $sid;
                    }
                }
                if ($targets === []) {
                    continue;
                }

                // Guard 1: keep only targets that name US back.
                $reciprocal = [];
                foreach ($targets as $sid) {
                    foreach (($claims[$sid] ?? []) as $backTok => $devB) {
                        if (ResolveSiteLinkEndpoints::tokMatch($backTok, $nnameById[$siteA])) {
                            $reciprocal[$sid] = $devB;
                            break;
                        }
                    }
                }
                if ($reciprocal === []) {
                    continue;
                }

                $out['candidates']++;
                if (count($reciprocal) > 1) {
                    $out['skipped_ambiguous']++;   // the `burg` failure mode - never guess
                    continue;
                }

                $siteB = array_key_first($reciprocal);
                $devB = $reciprocal[$siteB];
                $lo = min($siteA, $siteB);
                $hi = max($siteA, $siteB);
                $key = $lo.'-'.$hi;
                if (isset($linked[$key]) || isset($seen[$key])) {
                    continue;
                }

                $km = self::km($siteById[$siteA] ?? null, $siteById[$siteB] ?? null);
                if ($km !== null && $km > self::MAX_PLAUSIBLE_KM) {
                    $out['skipped_far']++;
                    continue;
                }

                $seen[$key] = true;
                $out['links'][] = [
                    'site_a' => $siteById[$lo]->name ?? $lo,
                    'site_b' => $siteById[$hi]->name ?? $hi,
                    'device_a' => $devA->name,
                    'device_b' => $devB->name,
                    'km' => $km === null ? null : round($km, 2),
                ];

                if (! $dryRun) {
                    $link = SiteLink::firstOrNew(['external_ref' => 'pair:'.$lo.'-'.$hi]);
                    $link->site_a_id = $lo;
                    $link->site_b_id = $hi;
                    $link->media_type ??= 'wireless';
                    $link->save();
                }
                $out['created']++;
            }
        }

        return $out;
    }

    private static function km(?object $a, ?object $b): ?float
    {
        if (! $a || ! $b || $a->latitude === null || $b->latitude === null) {
            return null;
        }
        $r = 6371.0;
        $p1 = deg2rad((float) $a->latitude);
        $p2 = deg2rad((float) $b->latitude);
        $dp = deg2rad((float) $b->latitude - (float) $a->latitude);
        $dl = deg2rad((float) $b->longitude - (float) $a->longitude);
        $h = sin($dp / 2) ** 2 + cos($p1) * cos($p2) * sin($dl / 2) ** 2;

        return 2 * $r * asin(min(1.0, sqrt($h)));
    }
}
