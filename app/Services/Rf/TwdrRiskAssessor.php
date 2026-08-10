<?php

namespace App\Services\Rf;

use Illuminate\Support\Facades\DB;

/**
 * Decides whether a radio is at risk of interfering with a TDWR weather radar.
 *
 * The rule: within RADIUS_KM of a TDWR installation, a U-NII radio must not operate
 * on a channel that comes within the guard margin of that radar's frequency. The FCC
 * margin is 30 MHz; LTD's own policy is the more conservative 40 MHz, so anything
 * between the two is reported as a warning rather than a violation.
 *
 * Deliberately narrow in two ways. Only 5 GHz gear is evaluated - the fleet's 60 GHz
 * Wave links report freq_mhz around 69120 and are physically incapable of touching
 * 5.6 GHz, so including them would bury real findings in noise. And where a radio's
 * channel width is unknown the channel is treated as its centre frequency: the guard
 * margin already supplies headroom, and inventing a width would manufacture alerts
 * from missing data rather than from a real condition.
 */
class TwdrRiskAssessor
{
    /** FCC exclusion radius around a TDWR installation. */
    public const RADIUS_KM = 35.0;

    /** FCC-mandated separation from the radar's operating frequency. */
    public const FCC_GUARD_MHZ = 30.0;

    /** LTD policy margin - wider than the FCC minimum, on purpose. */
    public const LTD_GUARD_MHZ = 40.0;

    /** Only U-NII 5 GHz gear can conflict; anything outside this is ignored. */
    public const BAND_MIN_MHZ = 5150;
    public const BAND_MAX_MHZ = 5925;

    /** @var array<int,object>|null */
    private ?array $sites = null;

    /**
     * @return array<int,array<string,mixed>> one entry per radar the radio conflicts with
     */
    public function assess(int $freqMhz, ?int $widthMhz, float $lat, float $lon): array
    {
        if ($freqMhz < self::BAND_MIN_MHZ || $freqMhz > self::BAND_MAX_MHZ) {
            return [];
        }

        $half = $widthMhz > 0 ? $widthMhz / 2.0 : 0.0;
        $lowEdge = $freqMhz - $half;
        $highEdge = $freqMhz + $half;

        $risks = [];
        foreach ($this->sites() as $site) {
            $km = $this->haversineKm($lat, $lon, (float) $site->latitude, (float) $site->longitude);
            if ($km > self::RADIUS_KM) {
                continue;
            }

            $radar = (int) $site->freq_mhz;
            $separation = ($lowEdge <= $radar && $radar <= $highEdge)
                ? 0.0
                : min(abs($lowEdge - $radar), abs($highEdge - $radar));

            if ($separation >= self::LTD_GUARD_MHZ) {
                continue;
            }

            $risks[] = [
                'twdr_site_id'   => $site->id,
                'twdr_label'     => "{$site->state} {$site->city} @{$radar}MHz",
                'level'          => $separation < self::FCC_GUARD_MHZ ? 'violation' : 'warning',
                'distance_km'    => round($km, 2),
                'separation_mhz' => round($separation, 2),
            ];
        }

        return $risks;
    }

    /** @return array<int,object> */
    private function sites(): array
    {
        return $this->sites ??= DB::table('twdr_sites')->get()->all();
    }

    private function haversineKm(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $r = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2
           + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;

        return 2 * $r * asin(min(1.0, sqrt($a)));
    }
}
