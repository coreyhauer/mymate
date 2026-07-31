<?php

namespace App\Http\Controllers\Api;

use App\Enums\DeviceType;
use App\Http\Controllers\Controller;
use App\Http\Resources\OutageResource;
use App\Models\Outage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Outage timeline. Newest-first, capped. Optional filters:
 * `?device_id=` (one device) and `?state=open|closed`.
 *
 * Acknowledged devices (known/intentional down - suspended customers, polling-disabled
 * gear, cancelled ONUs) are hidden by default so the list stays actionable; pass
 * `?include_acked=1` to show them (the "Show acked" toggle).
 *
 * Customer CPE (fiber ONUs / monitored customer routers, device_type=ont) is likewise
 * hidden by default so the list stays infrastructure-only; pass `?include_cpe=1` to
 * show it (the "Show ONUs" toggle).
 */
class OutageController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Outage::query()->with('device')->latest('started_at');

        $deviceId = $request->integer('device_id');
        if ($deviceId > 0) {
            $query->where('device_id', $deviceId);
        }

        $state = $request->query('state');
        if ($state === 'open') {
            $query->whereNull('ended_at');
        } elseif ($state === 'closed') {
            $query->whereNotNull('ended_at');
        }

        // Default: drop outages whose device has been acknowledged. A single-device
        // lookup (device_id) always shows its history regardless.
        if ($deviceId <= 0 && ! $request->boolean('include_acked')) {
            $query->whereHas('device', fn ($q) => $q->where('acknowledged', false));
        }

        // Default: drop customer-CPE outages (device_type=ont) so the main list only
        // shows infrastructure. Same single-device bypass as the acked filter.
        if ($deviceId <= 0 && ! $request->boolean('include_cpe')) {
            $query->whereHas('device', fn ($q) => $q->where('device_type', '!=', DeviceType::Ont->value));
        }

        return OutageResource::collection($query->limit(500)->get());
    }
}
