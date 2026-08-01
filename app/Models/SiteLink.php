<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A backhaul link between two sites (see the create_site_links_table migration). Imported from
 * the OSS that owns the backbone topology so the geo map draws real tower-to-tower links.
 *
 * device_a_id/device_b_id/interface_a_id/interface_b_id are a *derived* overlay on top of that
 * geometry - best-effort radio endpoints resolved from device naming convention (see
 * ResolveSiteLinkEndpoints), not part of the imported topology itself. Always check
 * endpoint_confidence before trusting them for anything alert-worthy.
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

    /** Resolved radio at siteA's end, or null when not (yet) determined. */
    public function deviceA(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'device_a_id');
    }

    /** Resolved radio at siteB's end, or null when not (yet) determined. */
    public function deviceB(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'device_b_id');
    }

    public function interfaceA(): BelongsTo
    {
        return $this->belongsTo(NetworkInterface::class, 'interface_a_id');
    }

    public function interfaceB(): BelongsTo
    {
        return $this->belongsTo(NetworkInterface::class, 'interface_b_id');
    }

    /** Latest materialised RF state for the siteA-end radio, or null when unresolved. */
    public function rfStateA(): HasOne
    {
        return $this->hasOne(RfLinkState::class, 'device_id', 'device_a_id');
    }

    /** Latest materialised RF state for the siteB-end radio, or null when unresolved. */
    public function rfStateB(): HasOne
    {
        return $this->hasOne(RfLinkState::class, 'device_id', 'device_b_id');
    }

    /**
     * Read model for the (future) geo-map RF badge - given this link, current health for
     * each resolved end (design §2e/§3b). A null side means that end's device isn't resolved
     * yet (see endpoint_confidence) - never rendered as "unknown-but-probably-fine" by a
     * consumer, per the NULL-vs-zero discipline used elsewhere in this codebase
     * (GeoController::devices() cust_count). `stale` is true when there's a state row but its
     * source_lastupdate is older than mymate.librenms_rf.stale_after_minutes, or when there's
     * no source_lastupdate at all - a consumer must treat stale exactly like missing, never
     * as still-good (design §2f).
     *
     * @return array{a: ?array<string, mixed>, b: ?array<string, mixed>}
     */
    public function rfHealth(): array
    {
        $staleBefore = now()->subMinutes((int) config('mymate.librenms_rf.stale_after_minutes', 30));

        $side = static function (?RfLinkState $state) use ($staleBefore): ?array {
            if ($state === null) {
                return null;
            }

            return [
                'device_id' => $state->device_id,
                'rssi_dbm' => $state->rssi_dbm,
                'noise_floor_dbm' => $state->noise_floor_dbm,
                'snr_db' => $state->snr_db,
                'snr_source' => $state->snr_source,
                'rate_mbps' => $state->rate_mbps,
                'channel_util_pct' => $state->channel_util_pct,
                'source_lastupdate' => $state->source_lastupdate?->toIso8601String(),
                'stale' => $state->source_lastupdate === null || $state->source_lastupdate->lt($staleBefore),
            ];
        };

        $a = $this->relationLoaded('rfStateA') ? $this->rfStateA : $this->rfStateA()->first();
        $b = $this->relationLoaded('rfStateB') ? $this->rfStateB : $this->rfStateB()->first();

        return ['a' => $side($a), 'b' => $side($b)];
    }
}
