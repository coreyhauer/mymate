<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A backhaul link between two sites (see the create_site_links_table migration). Imported from
 * the OSS that owns the backbone topology so the geo map draws real tower-to-tower links.
 *
 * The endpoint-resolution overlay (device_a_id/interface_a_id/device_b_id/interface_b_id +
 * endpoint_confidence/endpoint_method/endpoint_evidence, added 2026-07-31) records WHICH
 * radios form the link, resolved from the `alpha2bravo` naming convention by
 * App\Actions\Sites\ResolveSiteLinkEndpoints. Always check `endpoint_confidence` before
 * trusting the device ids for anything alert-worthy - `reciprocal_name` is solid, the
 * single-sided methods are best-effort.
 */
class SiteLink extends Model
{
    protected $fillable = [
        'site_a_id', 'site_b_id', 'media_type', 'external_ref',
        'device_a_id', 'interface_a_id', 'device_b_id', 'interface_b_id',
        'endpoint_confidence', 'endpoint_method', 'endpoint_evidence', 'endpoints_resolved_at',
    ];

    protected $casts = [
        'endpoint_evidence' => 'array',
        'endpoints_resolved_at' => 'datetime',
    ];

    public function siteA(): BelongsTo
    {
        return $this->belongsTo(Site::class, 'site_a_id');
    }

    public function siteB(): BelongsTo
    {
        return $this->belongsTo(Site::class, 'site_b_id');
    }

    public function deviceA(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'device_a_id');
    }

    public function deviceB(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'device_b_id');
    }

    /**
     * The link's current RF health, one entry per end: the latest RfLinkState reading for the
     * resolved device, or null when that end is unresolved / never synced - a missing reading
     * must read as "unknown", never as a fake good one. `stale` flags a reading LibreNMS
     * itself hasn't refreshed within `mymate.librenms_rf.stale_after_minutes` (judged by
     * `source_lastupdate`, not `synced_at` - the puller re-writing an old sensor value every
     * 5 minutes doesn't make the value current).
     *
     * @return array{a: ?array<string, mixed>, b: ?array<string, mixed>}
     */
    public function rfHealth(): array
    {
        $staleAfter = (int) config('mymate.librenms_rf.stale_after_minutes', 30);

        $end = static function (?int $deviceId) use ($staleAfter): ?array {
            if ($deviceId === null) {
                return null;
            }
            $state = RfLinkState::find($deviceId);
            if ($state === null) {
                return null;
            }

            return [
                'device_id' => $deviceId,
                'rssi_dbm' => $state->rssi_dbm,
                'rssi_sensor_type' => $state->rssi_sensor_type,
                'noise_floor_dbm' => $state->noise_floor_dbm,
                'snr_db' => $state->snr_db,
                'rate_mbps' => $state->rate_mbps,
                'freq_mhz' => $state->freq_mhz,
                'source_lastupdate' => $state->source_lastupdate,
                'stale' => $state->source_lastupdate === null
                    || $state->source_lastupdate->lt(now()->subMinutes($staleAfter)),
            ];
        };

        return [
            'a' => $end($this->device_a_id),
            'b' => $end($this->device_b_id),
        ];
    }
}
