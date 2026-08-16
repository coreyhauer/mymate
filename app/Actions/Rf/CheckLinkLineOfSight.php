<?php

namespace App\Actions\Rf;

use App\Models\SiteLink;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Ask the LiDAR LOS tool whether a backhaul link's path is actually clear, and record the verdict
 * on site_links. This is the gate that turns the signal-deficit overlay from a list of physics
 * into a list of faults.
 *
 * Only links that ALREADY look underperforming are checked - the tool builds a terrain profile per
 * call, so sweeping all 1,700 links every night would be wasteful and slow. A link that meets spec
 * needs no explanation.
 *
 * THREE THINGS THAT MAKE THIS CORRECT, all learned the hard way:
 *
 *  1. ORIENT THE HEIGHTS. a_h_m/b_h_m must match which end is the tower (~45 m) and which is the
 *     CPE (~9 m). Backwards FLIPS the verdict - modelling Cody Przymus's house on a 45 m tower and
 *     the Marshall tower at 9 m turned `blocked/-5.2` into `los_only/-2.2`. Role comes from the
 *     ap/st suffix in the device name.
 *  2. VALIDATE THE GEOMETRY FIRST. LibreNMS `locations` lat/lng is wrong on a meaningful share of
 *     devices. If haversine disagrees with the radios' own `distance` sensor, the profile is
 *     garbage - record 'bad_geo' rather than a confident-looking lie.
 *  3. NAMES CAN SAY NLOS. Techs label some links "... NLOS" (marshall2codyP, henry2sunburg). That
 *     is free ground truth: trust it without spending a LiDAR call.
 */
class CheckLinkLineOfSight
{
    /** Fallback only. Real heights come from sites.height_m (UISP has 2,727 of 2,735). */
    private const TOWER_M = 45.0;
    private const CPE_M = 9.0;

    /** Geometry must agree with the radios to within this, else the terrain profile is meaningless. */
    private const GEO_TOLERANCE = 0.30;   // 30%

    /** @return array<string,int> */
    public function handle(float $minDeficitDb = 6.0, int $limit = 200, ?int $onlyLinkId = null): array
    {
        $base = rtrim((string) config('mymate.los_api_url', 'http://10.66.71.73:8088'), '/');
        $out = ['checked' => 0, 'clear' => 0, 'los_only' => 0, 'blocked' => 0,
            'named_nlos' => 0, 'bad_geo' => 0, 'no_lidar' => 0, 'skipped' => 0];

        $rows = DB::select('
            SELECT sl.id, sl.device_a_id, sl.device_b_id,
                   da.name AS a_name, db.name AS b_name,
                   sa2.latitude AS a_lat, sa2.longitude AS a_lon, sa2.height_m AS a_h,
                   sb2.latitude AS b_lat, sb2.longitude AS b_lon, sb2.height_m AS b_h,
                   GREATEST(COALESCE(ra.signal_deficit_db, -99), COALESCE(rb.signal_deficit_db, -99)) AS worst_deficit,
                   COALESCE(ra.distance_mi, rb.distance_mi) AS radio_km,
                   COALESCE(ra.freq_mhz, rb.freq_mhz) AS freq_mhz
              FROM site_links sl
              JOIN devices da ON da.id = sl.device_a_id
              JOIN devices db ON db.id = sl.device_b_id
              JOIN sites sa2 ON sa2.id = sl.site_a_id
              JOIN sites sb2 ON sb2.id = sl.site_b_id
              LEFT JOIN rf_link_state ra ON ra.device_id = sl.device_a_id
              LEFT JOIN rf_link_state rb ON rb.device_id = sl.device_b_id
             WHERE sl.device_a_id IS NOT NULL AND sl.device_b_id IS NOT NULL
               AND sa2.latitude IS NOT NULL AND sb2.latitude IS NOT NULL
               '.($onlyLinkId ? 'AND sl.id = '.(int) $onlyLinkId : '').'
            ORDER BY worst_deficit DESC');

        foreach ($rows as $r) {
            if ($out['checked'] >= $limit) {
                break;
            }
            if ($onlyLinkId === null && (float) $r->worst_deficit < $minDeficitDb) {
                $out['skipped']++;

                continue;   // meets spec - nothing to explain
            }

            // (3) the techs already told us
            if (preg_match('/\bnlos\b/i', $r->a_name.' '.$r->b_name)) {
                $this->store($r->id, 'blocked', null, null, null);
                $out['named_nlos']++;
                $out['checked']++;

                continue;
            }

            $geoKm = $this->haversine((float) $r->a_lat, (float) $r->a_lon, (float) $r->b_lat, (float) $r->b_lon);

            // (2) does the geometry agree with the radios?
            $radioKm = $r->radio_km !== null ? (float) $r->radio_km : null;
            if ($radioKm !== null && $radioKm > 0.05) {
                $err = abs($geoKm - $radioKm) / max($radioKm, 0.1);
                if ($err > self::GEO_TOLERANCE) {
                    $this->store($r->id, 'bad_geo', null, null, round($geoKm, 3));
                    $out['bad_geo']++;
                    $out['checked']++;

                    continue;
                }
            }

            // (1) MEASURED height wins. Inferring it from the ap/st suffix is wrong whenever a
            // TOWER-mounted radio is named "...ST" - which is normal for the station end of a
            // tower-to-tower backhaul - and it invents obstructions that are not there.
            // Charleston CCI <-> Easton GL was reported `los_only`/-2.8 m on that guess, and I
            // wrongly concluded vegetation; with the real 30 m heights the same tool returns
            // clear/+3.5 m/foliage 0.0.
            $aH = $r->a_h !== null ? (float) $r->a_h : $this->heightFor((string) $r->a_name);
            $bH = $r->b_h !== null ? (float) $r->b_h : $this->heightFor((string) $r->b_name);
            $freq = (float) ($r->freq_mhz ?: 5800);
            if ($freq < 1000) {
                $freq *= 1000;
            }

            try {
                $resp = Http::timeout(150)->acceptJson()->post($base.'/api/p2p', [
                    'a_lat' => (float) $r->a_lat, 'a_lon' => (float) $r->a_lon, 'a_h_m' => $aH,
                    'b_lat' => (float) $r->b_lat, 'b_lon' => (float) $r->b_lon, 'b_h_m' => $bH,
                    'freq_mhz' => $freq,
                ]);
                $d = $resp->json();
            } catch (\Throwable $e) {
                $d = null;
            }

            if (! is_array($d) || ! empty($d['error']) || empty($d['verdict'])) {
                $this->store($r->id, 'no_lidar', null, null, round($geoKm, 3));
                $out['no_lidar']++;
                $out['checked']++;

                continue;
            }

            $verdict = (string) $d['verdict'];
            $canopy = null;
            $p = $d['profile'] ?? null;
            if (is_array($p) && ! empty($p['d']) && ! empty($p['ground']) && ! empty($p['surface'])) {
                $n = count($p['d']);
                $D = (float) end($p['d']) ?: 1.0;
                // canopy near whichever end is the CPE
                [$lo, $hi] = $aH >= $bH ? [0.8 * $D, $D] : [0.0, 0.2 * $D];
                $seg = [];
                for ($i = 0; $i < $n; $i++) {
                    if ($p['surface'][$i] !== null && $p['ground'][$i] !== null
                        && $p['d'][$i] >= $lo && $p['d'][$i] <= $hi) {
                        $seg[] = $p['surface'][$i] - $p['ground'][$i];
                    }
                }
                $canopy = $seg ? round(max($seg), 1) : null;
            }

            $this->store($r->id, $verdict, isset($d['min_clearance_m']) ? (float) $d['min_clearance_m'] : null,
                $canopy, round($geoKm, 3));
            $out[$verdict] = ($out[$verdict] ?? 0) + 1;
            $out['checked']++;
        }

        return $out;
    }

    private function store(int $id, string $verdict, ?float $clr, ?float $canopy, ?float $km): void
    {
        SiteLink::where('id', $id)->update([
            'los_verdict' => $verdict,
            'los_clearance_m' => $clr,
            'los_canopy_m' => $canopy,
            'los_distance_km' => $km,
            'los_checked_at' => now(),
        ]);
    }

    /** AP end sits on the tower, ST end is the CPE; unknown defaults to tower (backhaul). */
    private function heightFor(string $name): float
    {
        return preg_match('/\bst\b|stv?\d|st$/i', strtolower($name)) ? self::CPE_M : self::TOWER_M;
    }

    private function haversine(float $a, float $b, float $c, float $d): float
    {
        $R = 6371.0;
        $p1 = deg2rad($a);
        $p2 = deg2rad($c);
        $dp = deg2rad($c - $a);
        $dl = deg2rad($d - $b);
        $h = sin($dp / 2) ** 2 + cos($p1) * cos($p2) * sin($dl / 2) ** 2;

        return 2 * $R * asin(min(1.0, sqrt($h)));
    }
}
