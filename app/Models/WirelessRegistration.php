<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One wireless client as seen ON one radio, captured by App\Actions\Polling\ReadWireless from
 * the registration table(s) App\Services\Polling\RouterOsDeviceMetricsDriver already reads on
 * every metrics poll (see the wireless_registrations migration).
 *
 * "As seen on" is the important part: an AP's row is its associated station, a station-mode
 * CPE's row is the one AP it's registered to - the same client can therefore appear once per
 * radio it talks to.
 *
 * Rows are upserted on (device_id, mac_address) and anything a non-empty read does not return
 * is deleted, so `id` is stable for as long as the client keeps appearing. An EMPTY read,
 * unlike OSPF, is treated as untrusted (PPPoE's rule, not OSPF's) and prunes nothing - see
 * ReadWireless::persist. `first_seen_at` is set once and never overwritten by the upsert;
 * `last_seen_at` is stamped when the writing transaction commits, and is what the read API's
 * default freshness filter and `mymate:wireless:reap` key off - but on two different horizons:
 * freshness is a QUERY filter (`mymate.wireless.stale_after_minutes`, overridable per request),
 * lifetime is `mymate.wireless.retention_days` (default 90). The latest observation of a pair
 * is therefore kept, with its date, long after it stops being "current".
 *
 * `interface` and `mac_address` are stored as '' rather than NULL (mac_address takes part in a
 * unique index, and a NULL never conflicts); WirelessRegistrationResource presents them as null.
 */
class WirelessRegistration extends Model
{
    protected $table = 'wireless_registrations';

    public $timestamps = false;

    protected $fillable = [
        'device_id', 'interface', 'mac_address', 'signal_strength_dbm', 'signal_to_noise_db',
        'tx_ccq_pct', 'tx_rate', 'rx_rate', 'uptime_seconds', 'last_activity_seconds',
        'first_seen_at', 'last_seen_at',
    ];

    protected $casts = [
        'signal_strength_dbm' => 'float',
        'signal_to_noise_db' => 'float',
        'tx_ccq_pct' => 'float',
        'uptime_seconds' => 'integer',
        'last_activity_seconds' => 'integer',
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
