<?php

namespace App\Console\Commands;

use App\Services\Rf\TwdrRiskAssessor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Scan the fleet for radios at risk of interfering with a TDWR weather radar.
 *
 * Run on a schedule. Every radio with a known frequency and a location (its own GPS,
 * or its site's) is checked; open risk events are raised, refreshed, or resolved so
 * that a radio retuned or relocated INTO a conflict is reported as a new event rather
 * than being lost among pre-existing ones. That transition is the point of this - the
 * fleet is currently clean, so the value is catching the change that breaks it.
 */
class TwdrScanCommand extends Command
{
    protected $signature = 'mymate:twdr:scan {--quiet-when-clean : suppress output when nothing is at risk}';

    protected $description = 'Check radios against TDWR exclusion zones and raise/resolve risk events';

    public function handle(TwdrRiskAssessor $assessor): int
    {
        $rows = DB::table('devices as d')
            ->leftJoin('sites as s', 'd.site_id', '=', 's.id')
            ->whereNotNull('d.freq_mhz')
            ->whereRaw('coalesce(d.latitude, s.latitude) is not null')
            ->selectRaw('d.id, d.name, d.freq_mhz, d.chan_width_mhz,
                         coalesce(d.latitude, s.latitude) as lat,
                         coalesce(d.longitude, s.longitude) as lon,
                         coalesce(s.name, \'\') as site_name')
            ->get();

        $seen = [];
        $opened = $refreshed = 0;
        $now = now();

        foreach ($rows as $r) {
            foreach ($assessor->assess((int) $r->freq_mhz, $r->chan_width_mhz ? (int) $r->chan_width_mhz : null, (float) $r->lat, (float) $r->lon) as $risk) {
                $key = "twdr:{$r->id}:{$risk['twdr_site_id']}";
                $seen[] = $key;

                $detail = sprintf(
                    '%s (%s) on %d MHz%s is %.1f km from %s - %.1f MHz separation',
                    $r->name, $r->site_name ?: 'no site', $r->freq_mhz,
                    $r->chan_width_mhz ? "/{$r->chan_width_mhz}MHz" : '',
                    $risk['distance_km'], $risk['twdr_label'], $risk['separation_mhz']
                );

                $existing = DB::table('twdr_risk_events')->where('dedupe_key', $key)->first();

                if ($existing && $existing->resolved_at === null) {
                    DB::table('twdr_risk_events')->where('id', $existing->id)->update([
                        'level' => $risk['level'], 'distance_km' => $risk['distance_km'],
                        'separation_mhz' => $risk['separation_mhz'], 'device_freq_mhz' => $r->freq_mhz,
                        'device_width_mhz' => $r->chan_width_mhz, 'detail' => $detail,
                        'last_seen_at' => $now, 'updated_at' => $now,
                    ]);
                    $refreshed++;

                    continue;
                }

                // New, or a previously-resolved pair that has regressed.
                DB::table('twdr_risk_events')->updateOrInsert(['dedupe_key' => $key], [
                    'device_id' => $r->id, 'twdr_site_id' => $risk['twdr_site_id'],
                    'level' => $risk['level'], 'distance_km' => $risk['distance_km'],
                    'separation_mhz' => $risk['separation_mhz'], 'device_freq_mhz' => $r->freq_mhz,
                    'device_width_mhz' => $r->chan_width_mhz, 'detail' => $detail,
                    'opened_at' => $now, 'last_seen_at' => $now, 'resolved_at' => null,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
                $opened++;

                Log::warning("TDWR {$risk['level']}: {$detail}");
                $this->error("  {$risk['level']}: {$detail}");
            }
        }

        $resolved = DB::table('twdr_risk_events')
            ->whereNull('resolved_at')
            ->when($seen !== [], fn ($q) => $q->whereNotIn('dedupe_key', $seen))
            ->update(['resolved_at' => $now, 'updated_at' => $now]);

        if ($opened || $resolved || ! $this->option('quiet-when-clean')) {
            $this->line(sprintf(
                'TDWR scan: %d radios checked, %d new, %d ongoing, %d resolved',
                $rows->count(), $opened, $refreshed, $resolved
            ));
        }

        // Non-zero exit when something new appeared, so a scheduler or CI step can react.
        return $opened > 0 ? 1 : 0;
    }
}
