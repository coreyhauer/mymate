<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PppoeSessionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'device_id' => $this->device_id,
            'device_name' => $this->device?->name,
            'site_id' => $this->device?->site_id,
            'username' => $this->username,
            'remote_address' => $this->remote_address,
            'caller_id' => $this->caller_id,
            'uptime_seconds' => $this->uptime_seconds,
            'swept_at' => $this->swept_at,
        ];
    }
}
