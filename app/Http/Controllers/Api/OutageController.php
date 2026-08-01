<?php

namespace App\Http\Controllers\Api;

use App\Enums\DeviceType;
use App\Http\Controllers\Controller;
use App\Http\Resources\OutageResource;
use App\Models\Outage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;

/**
 * Outage timeline. Newest-first, capped. Optional filters:
 * `?device_id=` (one device), `?state=open|closed`, and `?from=`/`?to=` (started_at range).
 *
 * Acknowledged devices (known/intentional down - suspended customers, polling-disabled
 * gear, cancelled ONUs) are hidden by default so the list stays actionable; pass
 * `?include_acked=1` to show them (the "Show acked" toggle).
 *
 * Customer CPE (fiber ONUs / monitored customer routers, device_type=ont) is likewise
 * hidden by default so the list stays infrastructure-only; pass `?include_cpe=1` to
 * show it (the "Show ONUs" toggle).
 *
 * Without `?per_page=` this keeps the original contract byte-for-byte: a plain capped
 * list (limit 500). With `?per_page=` (1-1000) it switches to cursor pagination instead -
 * the table is 2.4M+ rows, so offset pagination gets slower with every page; the response
 * becomes the standard Resource-collection paginated envelope (`data` + `links` +
 * `meta.next_cursor`).
 */
class OutageController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ]);

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

        if (isset($validated['from'])) {
            $query->where('started_at', '>=', Carbon::parse($validated['from']));
        }
        if (isset($validated['to'])) {
            $query->where('started_at', '<=', Carbon::parse($validated['to']));
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

        if (isset($validated['per_page'])) {
            // Cursor pagination needs a fully deterministic order - `latest('started_at')`
            // above ties whenever two outages share a started_at, so add the id as a
            // tiebreak before paginating.
            return OutageResource::collection(
                $query->orderByDesc('id')->cursorPaginate((int) $validated['per_page'])
            );
        }

        return OutageResource::collection($query->limit(500)->get());
    }
}
