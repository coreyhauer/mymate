<?php

namespace App\Http\Controllers\Api;

use App\Actions\Devices\CreateDevice;
use App\Actions\Devices\DeleteDevice;
use App\Actions\Devices\UpdateDevice;
use App\Actions\Devices\UpdateDevicePosition;
use App\Actions\Devices\UpgradePreflight;
use App\Enums\UpgradeStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Device\StoreDeviceRequest;
use App\Http\Requests\Device\UpdateDevicePositionRequest;
use App\Http\Requests\Device\UpdateDeviceRequest;
use App\Http\Requests\Device\UpgradeDevicesRequest;
use App\Http\Resources\DeviceResource;
use App\Jobs\BulkUpgradeJob;
use App\Jobs\UpgradeDeviceJob;
use App\Models\Device;
use App\Models\NetworkInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class DeviceController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        // `site` is eager-loaded so DeviceResource can resolve inherited geo coordinates
        // without an N+1 across the whole fleet.
        $devices = Device::with(['parent', 'site'])->orderBy('name')->get();

        // `mac_addresses`: ONE grouped query over the MAC-bearing interfaces (~10% of the
        // table - PPPoE concentrators carry thousands of dynamic MAC-less pppoe-in rows),
        // aggregated in Postgres, never hydrated as models. Eager-loading `interfaces` here
        // hydrated the whole ~200k-row table behind this unpaginated fleet list and
        // exhausted 1.5 GB on the first prod deploy (2026-08-16). Attached as a plain
        // attribute the resource emits only when present (see DeviceResource).
        $macsByDevice = NetworkInterface::query()
            ->whereNotNull('mac_address')
            ->where('mac_address', '<>', '')
            ->groupBy('device_id')
            ->selectRaw("device_id, string_agg(DISTINCT mac_address, ',' ORDER BY mac_address) AS macs")
            ->pluck('macs', 'device_id');
        foreach ($devices as $device) {
            $macs = $macsByDevice->get($device->id);
            $device->setAttribute('mac_addresses', $macs === null ? [] : explode(',', $macs));
        }

        return DeviceResource::collection($devices);
    }

    /**
     * Lightweight up/down/unknown tallies for the top bar - a single GROUP BY instead of
     * shipping the whole (25k+, ~35 MB) device collection just to count statuses. Only
     * monitored devices count, matching StatusCounts. Kept tiny so the header is instant and
     * stays correct even when the full /devices list is slow or fails to load.
     */
    public function stats(): JsonResponse
    {
        $rows = Device::query()
            ->where('monitored', true)
            ->selectRaw('status, count(*) as c')
            ->groupBy('status')
            ->pluck('c', 'status');

        return response()->json(['data' => [
            'up' => (int) $rows->get('up', 0),
            'down' => (int) $rows->get('down', 0),
            'unknown' => (int) $rows->get('unknown', 0),
            'total' => (int) $rows->sum(),
        ]]);
    }

    public function store(StoreDeviceRequest $request, CreateDevice $createDevice): JsonResponse
    {
        $device = $createDevice($request->validated());

        return (new DeviceResource($device->loadMissing('parent')))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(Device $device): DeviceResource
    {
        return new DeviceResource($device->loadMissing('parent'));
    }

    public function update(UpdateDeviceRequest $request, Device $device, UpdateDevice $updateDevice): DeviceResource
    {
        return new DeviceResource($updateDevice($device, $request->validated())->loadMissing('parent', 'site'));
    }

    public function updatePosition(UpdateDevicePositionRequest $request, Device $device, UpdateDevicePosition $updatePosition): DeviceResource
    {
        $data = $request->validated();

        return new DeviceResource($updatePosition($device, (float) $data['map_x'], (float) $data['map_y']));
    }

    public function destroy(Device $device, DeleteDevice $deleteDevice): Response
    {
        $deleteDevice($device);

        return response()->noContent();
    }

    /**
     * Acknowledge a device - a known/intentional down (suspended customer, polling-disabled
     * gear, cancelled ONU) that shouldn't clutter the actionable outage list. Acked devices
     * are hidden from the default Outages view. Optional `note` records why.
     */
    public function acknowledge(Request $request, Device $device): DeviceResource
    {
        $device->update([
            'acknowledged' => true,
            'ack_at' => now(),
            'ack_note' => $request->string('note')->trim()->value() ?: null,
        ]);

        return new DeviceResource($device->loadMissing('parent'));
    }

    /** Clear a device's acknowledgement - it re-appears in the default Outages view. */
    public function unacknowledge(Device $device): DeviceResource
    {
        $device->update(['acknowledged' => false, 'ack_at' => null, 'ack_note' => null]);

        return new DeviceResource($device->loadMissing('parent'));
    }

    /**
     * Dry-run the dependency checks: return the downstream-first
     * order and, per device, whether it would upgrade or be skipped (and why) -
     * without touching anything. The UI shows this before the operator confirms.
     */
    public function upgradePreflight(UpgradeDevicesRequest $request, UpgradePreflight $preflight): JsonResponse
    {
        $data = $request->validated();
        $ids = array_map('intval', $data['device_ids']);

        return response()->json($preflight($ids, (bool) ($data['preserve_order'] ?? false), $data['version'] ?? null));
    }

    /**
     * Queue a firmware upgrade for the given devices. `ordered` runs one
     * BulkUpgradeJob that walks them downstream-first (waiting for each to recover
     * before its parent); otherwise one isolated job per device, in parallel.
     */
    public function upgrade(UpgradeDevicesRequest $request): JsonResponse
    {
        $data = $request->validated();
        $ids = array_map('intval', $data['device_ids']);

        // Mark queued up front so the UI shows a spinner immediately (before a worker picks it up).
        Device::whereIn('id', $ids)->update([
            'upgrade_status' => UpgradeStatus::Queued,
            'upgrade_message' => 'Queued for upgrade...',
            'upgrade_at' => now(),
        ]);

        $version = $data['version'] ?? null;
        $source = $data['source'] ?? 'mikrotik';

        if ($data['ordered'] ?? false) {
            BulkUpgradeJob::dispatch($ids, (bool) ($data['explicit_order'] ?? false), $version, $source);
        } else {
            foreach ($ids as $id) {
                UpgradeDeviceJob::dispatch($id, $version, $source);
            }
        }

        return response()->json([
            'queued' => count($ids),
            'ordered' => (bool) ($data['ordered'] ?? false),
        ], Response::HTTP_ACCEPTED);
    }
}
