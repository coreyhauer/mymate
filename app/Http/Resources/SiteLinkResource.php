<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SiteLinkResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'site_a_id' => $this->site_a_id,
            'site_b_id' => $this->site_b_id,
            'media_type' => $this->media_type,
            'external_ref' => $this->external_ref,
            'device_a_id' => $this->device_a_id,
            'interface_a_id' => $this->interface_a_id,
            'device_b_id' => $this->device_b_id,
            'interface_b_id' => $this->interface_b_id,
            'endpoint_confidence' => $this->endpoint_confidence,
            'endpoint_method' => $this->endpoint_method,
            // Cast to array on the model - the audit trail behind endpoint_confidence.
            'endpoint_evidence' => $this->endpoint_evidence,
            'endpoints_resolved_at' => $this->endpoints_resolved_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
