<?php

namespace App\Actions\Devices;

use App\Actions\Outages\RecordOutage;
use App\Models\Device;

class UpdateDevice
{
    public function __construct(private RecordOutage $outages) {}

    /** @param array<string, mixed> $data */
    public function __invoke(Device $device, array $data): Device
    {
        // Turning monitoring OFF must close any open outage. PingFleet only sweeps monitored
        // devices, so nothing would ever record the recovery - the outage would stay "ongoing"
        // with a duration climbing for as long as the device stays paused, and would reappear
        // as a bogus multi-week event the moment monitoring came back on.
        $pausing = array_key_exists('monitored', $data)
            && ! $data['monitored']
            && $device->monitored;
        // A coordinate set through the editor is a manual pin - stamp the source so the SNMP
        // auto-derive never overwrites it. Clearing both drops the source too.
        if (array_key_exists('latitude', $data) || array_key_exists('longitude', $data)) {
            $data['geo_source'] = ($data['latitude'] ?? null) !== null && ($data['longitude'] ?? null) !== null ? 'manual' : null;
        }

        // Assigning (or clearing) a site through the editor is a manual decision - stamp the
        // source so an import or nearest-site pass never overrides the operator.
        if (array_key_exists('site_id', $data)) {
            $data['site_source'] = $data['site_id'] !== null ? 'manual' : null;
        }

        $device->update($data);

        if ($pausing) {
            $this->outages->close($device);
        }

        return $device;
    }
}
