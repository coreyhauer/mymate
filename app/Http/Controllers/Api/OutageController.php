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

        // Default: drop outages the operator has already muted. There are TWO ways to mute a
        // device and both must count here, or the views disagree: `acknowledged` (the Ack
        // button) and `monitored=false` (monitoring toggled off / the `(acked)` name
        // convention). The geo map has always filtered on `monitored`, so before this a
        // polling-disabled device vanished from the map but sat in this list forever - and
        // "forever" was literal, because PingFleet skips unmonitored devices, so its open
        // outage could never close (reported by the NOC 2026-08-08). A single-device lookup
        // (device_id) always shows full history regardless.
        if ($deviceId <= 0 && ! $request->boolean('include_acked')) {
            $query->whereHas('device', fn ($q) => $q->where('acknowledged', false)->where('monitored', true));
        }

        // Default: drop customer-CPE outages (device_type=ont) so the main list only
        // shows infrastructure. Same single-device bypass as the acked filter.
        if ($deviceId <= 0 && ! $request->boolean('include_cpe')) {
            $query->whereHas('device', fn ($q) => $q->where('device_type', '!=', DeviceType::Ont->value));
        }

        $outages = $query->with('device.site')->limit(500)->get();

        // Latest operator note per device, one query for the whole page (an eager
        // `notes => limit(1)` would apply the limit across ALL parents, not per-device).
        // Ridden into the payload so the outage table can show a note column - triage
        // context ("gen on site", "tree on line") right where the sorting happens.
        $latestNotes = \App\Models\Note::query()
            ->where('notable_type', \App\Models\Device::class)
            ->whereIn('notable_id', $outages->pluck('device_id')->unique())
            ->orderByDesc('created_at')
            ->get()
            ->unique('notable_id')
            ->keyBy('notable_id');

        foreach ($outages as $outage) {
            $outage->setAttribute('latest_device_note', $latestNotes[$outage->device_id]->body ?? null);
        }

        return OutageResource::collection($outages);
    }
}
