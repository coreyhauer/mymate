<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OspfNeighborResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'device_id' => $this->device_id,
            'device_name' => $this->device?->name,
            'site_id' => $this->device?->site_id,
            // Stored as '' (never NULL) so they can form the upsert's natural key - see the
            // ospf_neighbors migration. Presented as null, the shape a consumer expects for
            // "RouterOS did not report this field".
            'router_id' => $this->router_id === '' ? null : $this->router_id,
            'neighbor_address' => $this->neighbor_address === '' ? null : $this->neighbor_address,
            'interface' => $this->interface,
            'state' => $this->state,
            'is_full' => $this->is_full,
            'adjacency_seconds' => $this->adjacency_seconds,
            'state_changes' => $this->state_changes,
            'instance' => $this->instance,
            'area' => $this->area,
            'dr_id' => $this->dr_id,
            'backup_dr_id' => $this->backup_dr_id,
            'priority' => $this->priority,
            'last_seen_at' => $this->last_seen_at,
        ];
    }
}
