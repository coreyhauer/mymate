<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RfLinkStateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'device_id' => $this->device_id,
            'sensor_index' => $this->sensor_index,
            'rssi_dbm' => $this->rssi_dbm,
            'rssi_sensor_type' => $this->rssi_sensor_type,
            'noise_floor_dbm' => $this->noise_floor_dbm,
            'snr_db' => $this->snr_db,
            'snr_source' => $this->snr_source,
            'rate_mbps' => $this->rate_mbps,
            'channel_util_pct' => $this->channel_util_pct,
            'tx_power_dbm' => $this->tx_power_dbm,
            'distance_mi' => $this->distance_mi,
            'freq_mhz' => $this->freq_mhz,
            'if_errors_in' => $this->if_errors_in,
            'if_errors_out' => $this->if_errors_out,
            // baseline_rssi_dbm/deviation_db intentionally omitted - reserved for the
            // (not-yet-built) baseline/alerting stage and never written by ingestion; see
            // the RfLinkState model docblock.
            'source_lastupdate' => $this->source_lastupdate,
            'synced_at' => $this->synced_at,
        ];
    }
}
