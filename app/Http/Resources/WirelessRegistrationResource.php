<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WirelessRegistrationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'device_id' => $this->device_id,
            'device_name' => $this->device?->name,
            'site_id' => $this->device?->site_id,
            // Stored as '' (never NULL) so it can take part in the upsert's natural key - see
            // the wireless_registrations migration. Presented as null, the shape a consumer
            // expects for "RouterOS did not report an interface for this row".
            'interface' => $this->interface === '' ? null : $this->interface,
            'mac_address' => $this->mac_address === '' ? null : $this->mac_address,
            'signal_strength_dbm' => $this->signal_strength_dbm,
            'signal_to_noise_db' => $this->signal_to_noise_db,
            'tx_ccq_pct' => $this->tx_ccq_pct,
            'tx_rate' => $this->tx_rate === '' ? null : $this->tx_rate,
            'rx_rate' => $this->rx_rate === '' ? null : $this->rx_rate,
            'uptime_seconds' => $this->uptime_seconds,
            'last_activity_seconds' => $this->last_activity_seconds,
            'first_seen_at' => $this->first_seen_at,
            'last_seen_at' => $this->last_seen_at,
        ];
    }
}
