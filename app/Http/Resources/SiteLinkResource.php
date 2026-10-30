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
            // Names so an editor can label rows without a second lookup.
            'site_a_name' => $this->whenLoaded('siteA', fn () => $this->siteA?->name),
            'site_b_name' => $this->whenLoaded('siteB', fn () => $this->siteB?->name),
        ];
    }
}
