<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Latest known RF/link-health reading per device, materialised from LibreNMS by
 * App\Actions\Rf\PullLibreNmsRfMetrics on a ~5-minute cadence (design §2e) - the fast read
 * path a later map/API stage joins through site_links.device_a_id/device_b_id, so that
 * request path never queries LibreNMS live.
 *
 * `device_id` is the primary key (one row per device); for a multi-carrier radio this
 * reflects the worse-performing carrier (see PullLibreNmsRfMetrics::pickWorstForState) -
 * `sensor_index` records which one.
 *
 * `baseline_rssi_dbm`/`deviation_db` are reserved for the baseline/alerting stage (design §4)
 * - always null until that stage lands; the ingestion stage never writes them.
 */
class RfLinkState extends Model
{
    protected $table = 'rf_link_state';

    protected $primaryKey = 'device_id';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = [
        'device_id', 'sensor_index',
        'rssi_dbm', 'rssi_sensor_type', 'noise_floor_dbm', 'snr_db', 'snr_source',
        'rate_mbps', 'channel_util_pct', 'tx_power_dbm', 'distance_mi', 'freq_mhz',
        'if_errors_in', 'if_errors_out',
        'baseline_rssi_dbm', 'deviation_db',
        'source_lastupdate', 'synced_at',
    ];

    protected $casts = [
        'rssi_dbm' => 'float',
        'noise_floor_dbm' => 'float',
        'snr_db' => 'float',
        'rate_mbps' => 'float',
        'channel_util_pct' => 'float',
        'tx_power_dbm' => 'float',
        'distance_mi' => 'float',
        'freq_mhz' => 'float',
        'if_errors_in' => 'integer',
        'if_errors_out' => 'integer',
        'baseline_rssi_dbm' => 'float',
        'deviation_db' => 'float',
        'source_lastupdate' => 'datetime',
        'synced_at' => 'datetime',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
