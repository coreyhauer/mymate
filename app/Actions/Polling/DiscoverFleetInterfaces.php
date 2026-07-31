<?php

namespace App\Actions\Polling;

use App\Actions\Devices\CaptureDeviceFacts;
use App\Models\Device;
use App\Support\EngineLog;

/**
 * Runs interface (re)discovery for a *set* of devices - one batch / shard - mirroring
 * PollInterfaces' scale-out shape.
 *
 * Discovery used to be dispatched one job per device, which meant ~17k queue jobs every
 * discover_interval landing on the same queue as throughput. Twelve workers can't drain that many
 * full ifTable walks inside the interval, so each cycle left more behind than the last until the
 * queue was millions deep and throughput polling - the thing that actually writes utilisation -
 * was buried behind days of stale discovery work. Batching per shard makes the job count a
 * function of shard count, not fleet size, so it can't outrun the workers again.
 *
 * Returns the number of devices that produced interfaces.
 */
class DiscoverFleetInterfaces
{
    public function __construct(
        private DiscoverInterfaces $discover,
        private CaptureDeviceFacts $facts,
    ) {}

    /** @param  list<int>  $deviceIds */
    public function __invoke(array $deviceIds): int
    {
        if ($deviceIds === []) {
            return 0;
        }

        $startedAt = microtime(true);
        $found = 0;
        $failed = 0;

        // Chunked so a large shard never holds the whole device set in memory at once.
        foreach (array_chunk($deviceIds, 200) as $chunk) {
            foreach (Device::with('credential')->whereIn('id', $chunk)->get() as $device) {
                try {
                    // DiscoverInterfaces already records its own failure on the device and
                    // returns 0; this catch is for anything that escapes it, so one
                    // black-holing device can't sink the rest of the shard.
                    if (($this->discover)($device) > 0) {
                        $found++;
                    }
                    ($this->facts)($device); // best-effort vendor/model/uptime/type - never throws
                } catch (\Throwable $e) {
                    $failed++;
                    EngineLog::warning('discover: device discovery failed', [
                        'device_id' => $device->id,
                        'device' => $device->name,
                        'ip' => $device->mgmt_ip,
                        'exception' => $e::class,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        EngineLog::debug('discover: batch complete', [
            'devices' => count($deviceIds),
            'with_interfaces' => $found,
            'failed' => $failed,
            'seconds' => round(microtime(true) - $startedAt, 2),
        ]);

        return $found;
    }
}
