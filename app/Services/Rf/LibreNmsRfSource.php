<?php

namespace App\Services\Rf;

use DateTimeInterface;

/**
 * A source of RF/link-health readings from LibreNMS for App\Actions\Rf\PullLibreNmsRfMetrics.
 * The production implementation (App\Services\Import\LibreNms\LibreNmsMysqlSource, which
 * also implements this interface) reads LibreNMS's own MySQL database directly; tests bind an
 * in-memory fake so the pull logic is exercised without ever touching a real LibreNMS host
 * (see the LibreNmsImportTest precedent for the same style of fake against LibreNmsSource).
 */
interface LibreNmsRfSource
{
    /**
     * Wireless RF sensor readings, one row per LibreNMS sensor. `capacity` and `ccq` are
     * never requested here (see PullLibreNmsRfMetrics docblock for why). $since, when given,
     * limits to sensors LibreNMS itself has updated after that time (an efficiency filter
     * only - not required for correctness).
     *
     * `sensor_type` is included (not just `sensor_class`) because it's the ONLY field that
     * disambiguates what a value actually means within one class - e.g. sensor_class='rssi' is
     * a unitless 0-90 index for sensor_type='airos' (plain airOS) but genuine dBm for
     * sensor_type='airos-af60-l' (Wave/AirFiber 60) on the exact same LibreNMS install. See
     * App\Actions\Rf\PullLibreNmsRfMetrics for the full per-platform mapping this drives.
     *
     * @return list<array{
     *   device_id:int, ip:string, sensor_class:string, sensor_type:?string, sensor_index:?string,
     *   sensor_descr:?string, sensor_current:?float, lastupdate:?string
     * }>
     */
    public function wirelessSensors(?DateTimeInterface $since = null): array;

    /**
     * One row per LibreNMS device: the highest-ifSpeed non-deleted port's error counters,
     * taken as the backhaul-interface proxy (My Mate doesn't yet know which LibreNMS ifIndex
     * corresponds to its own resolved site_link interface for most links - see design §2c).
     * Uses LibreNMS's own already-computed ifInErrors_delta/ifOutErrors_delta (the delta
     * since that port's previous poll), not a raw cumulative counter.
     *
     * @return list<array{device_id:int, ip:string, if_errors_in:?int, if_errors_out:?int}>
     */
    public function portErrorCounters(): array;
}
