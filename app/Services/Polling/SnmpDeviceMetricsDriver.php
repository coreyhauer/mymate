<?php

namespace App\Services\Polling;

use App\Models\Device;
use App\Services\Snmp\SnmpClient;
use App\Services\Snmp\SnmpClientException;
use App\Services\Snmp\SnmpCredential;

/**
 * CPU / memory / temperature over SNMP, driven by a per-vendor OID profile
 * (see DeviceMetricProfiles + config('mymate.device_metrics.profiles')). Each metric is
 * best-effort and independent: an OID the agent doesn't implement just leaves that metric
 * null rather than failing the whole read. A transport failure (timeout/filtered) throws
 * so the orchestrator can isolate the device.
 */
class SnmpDeviceMetricsDriver implements DeviceMetricsDriver
{
    public function __construct(
        private SnmpClient $snmp,
        private DeviceMetricProfiles $profiles,
    ) {}

    public function sample(Device $device): DeviceMetrics
    {
        [$host, $community] = $this->target($device);
        $profile = $this->profiles->for($device);

        $wl = $this->wireless($host, $community, $profile);
        $rf = $this->frequencyIfDue($device, $host, $community);

        return new DeviceMetrics(
            cpuPct: DeviceMetrics::clampPct($this->cpu($host, $community, $profile)),
            memUsedPct: DeviceMetrics::clampPct($this->memory($host, $community, $profile)),
            tempC: $this->temperature($host, $community, $profile),
            signalDbm: $wl['signal'],
            snrDb: $wl['snr'],
            ccqPct: DeviceMetrics::clampPct($wl['ccq']),
            wirelessClients: $wl['clients'],
            freqMhz: $rf['freq'],
            chanWidthMhz: $rf['width'],
            freqBackupMhz: $rf['backup'],
            chanWidthBackupMhz: $rf['backup_width'],
        );
    }

    /**
     * Live operating frequency for Ubiquiti radios, read at most once per
     * config('mymate.device_metrics.frequency_interval') per device (RF channel barely moves,
     * and this is extra GETs on top of the metric read). Returns all-null when not a Ubiquiti
     * radio or not yet due, so the persister leaves any stored value untouched.
     *
     * Candidate OIDs are tried cheapest-common-first; a radio only answers its own family:
     *   airMAX    UBNT-AirMAX-MIB::ubntRadioFreq         41112.1.4.1.1.4.1   (MHz)
     *   AF60/Wave UI-AF60-MIB::af60Frequency / Chanbw    41112.1.11.1.1.2.1 / .3.1 (MHz)
     *   airFiber  UBNT-AirFIBER-MIB::txFrequency         41112.1.3.1.1.5.1
     *   AF-LTU    UBNT-AFLTU-MIB::afLTUFrequency         41112.1.10.1.2.2.0  (Hz)
     * A 60 GHz Wave link that also answers the airMAX OID yields the 5 GHz failover as backup.
     *
     * @return array{freq:?int, width:?int, backup:?int, backup_width:?int}
     */
    private function frequencyIfDue(Device $device, string $host, SnmpCredential $community): array
    {
        $none = ['freq' => null, 'width' => null, 'backup' => null, 'backup_width' => null];

        if (! str_contains(strtolower((string) $device->vendor), 'ubiquiti')) {
            return $none;
        }
        $interval = (int) config('mymate.device_metrics.frequency_interval', 600);
        if ($device->freq_at !== null && $device->freq_at->gt(now()->subSeconds($interval))) {
            return $none;
        }

        try {
            $mhz = fn (string $oid): ?int => (($v = $this->firstNumeric($this->snmp->get($host, $community, [$oid]))) !== null && $v > 0)
                ? (int) ($v > 1_000_000 ? round($v / 1_000_000) : round($v)) // some OIDs report Hz
                : null;

            $af60 = $mhz('.1.3.6.1.4.1.41112.1.11.1.1.2.1');
            $airmax = $mhz('.1.3.6.1.4.1.41112.1.4.1.1.4.1');

            if ($af60 !== null) { // 60 GHz Wave: primary 60 GHz + width, airMAX OID (if any) is the 5 GHz backup
                return [
                    'freq' => $af60,
                    'width' => $mhz('.1.3.6.1.4.1.41112.1.11.1.1.3.1'),
                    'backup' => $airmax,
                    'backup_width' => null,
                ];
            }
            if ($airmax !== null) {
                return ['freq' => $airmax, 'width' => null, 'backup' => null, 'backup_width' => null];
            }
            $other = $mhz('.1.3.6.1.4.1.41112.1.10.1.2.2.0') ?? $mhz('.1.3.6.1.4.1.41112.1.3.1.1.5.1');

            return ['freq' => $other, 'width' => null, 'backup' => null, 'backup_width' => null];
        } catch (\Throwable) {
            return $none; // frequency is best-effort - never let it break the metric read
        }
    }

    /**
     * Wireless RF over SNMP, driven by optional profile OIDs (a profile without them just
     * leaves every field null). Each metric can be read as a scalar GET or a table walk, and
     * both are supported so the one profile covers a device in AP mode (RF is a per-station
     * table -> averaged) and station/CPE mode (RF is a scalar):
     *   signal|snr|ccq _oids   GET scalars, all numeric averaged
     *   signal|snr|ccq _walk   walk column(s), all numeric averaged
     *   clients_walk           walk column(s), COUNT the rows (one per associated station)
     *   clients_value_walk     walk/GET, take the reported count VALUE (summed across rows)
     *
     * @param  array<string, mixed>  $profile
     * @return array{signal:?float, snr:?float, ccq:?float, clients:?int}
     */
    private function wireless(string $host, SnmpCredential $community, array $profile): array
    {
        return [
            'signal' => $this->rfMeasure($host, $community, $profile['signal_oids'] ?? [], $profile['signal_walk'] ?? []),
            'snr' => $this->rfMeasure($host, $community, $profile['snr_oids'] ?? [], $profile['snr_walk'] ?? []),
            'ccq' => $this->rfMeasure($host, $community, $profile['ccq_oids'] ?? [], $profile['ccq_walk'] ?? []),
            'clients' => $this->rfClients($host, $community, $profile),
        ];
    }

    /**
     * Average of every numeric value from the scalar GETs plus the table walks (an empty set
     * -> null). Averaging lets an AP report the mean across its associated stations.
     *
     * @param  string|list<string>  $oids   scalar OIDs to GET
     * @param  string|list<string>  $walks  table column OIDs to walk
     */
    private function rfMeasure(string $host, SnmpCredential $community, string|array $oids, string|array $walks): ?float
    {
        $vals = [];
        foreach ((array) $oids as $oid) {
            $v = $this->firstNumeric($this->snmp->get($host, $community, [$oid]));
            if ($v !== null) {
                $vals[] = $v;
            }
        }
        foreach ((array) $walks as $oid) {
            foreach ($this->numericValues($this->snmp->walk($host, $community, $oid)) as $v) {
                $vals[] = $v;
            }
        }

        return $vals === [] ? null : round(array_sum($vals) / count($vals), 1);
    }

    /** @param array<string, mixed> $profile */
    private function rfClients(string $host, SnmpCredential $community, array $profile): ?int
    {
        // A registration/station table: one row per associated station -> count the rows.
        if (! empty($profile['clients_walk'])) {
            $count = 0;
            $any = false;
            foreach ((array) $profile['clients_walk'] as $oid) {
                $rows = $this->numericValues($this->snmp->walk($host, $community, $oid));
                if ($rows !== []) {
                    $any = true;
                    $count += count($rows);
                }
            }

            return $any ? $count : null;
        }

        // A reported count value (e.g. cambiumAPNumberOfConnectedSTA / ubntWlStatStaCount).
        if (! empty($profile['clients_value_walk'])) {
            $sum = 0.0;
            $any = false;
            foreach ((array) $profile['clients_value_walk'] as $oid) {
                foreach ($this->numericValues($this->snmp->walk($host, $community, $oid)) as $v) {
                    $sum += $v;
                    $any = true;
                }
            }

            return $any ? (int) round($sum) : null;
        }

        return null;
    }

    /** @param array<string, mixed> $profile */
    private function cpu(string $host, SnmpCredential $community, array $profile): ?float
    {
        if (! empty($profile['cpu_walk'])) {
            $loads = $this->numericValues($this->snmp->walk($host, $community, (string) $profile['cpu_walk']));

            return $loads === [] ? null : array_sum($loads) / count($loads); // average across cores
        }

        foreach ((array) ($profile['cpu_oids'] ?? []) as $oid) {
            $res = $this->snmp->get($host, $community, [$oid]);
            $val = $this->firstNumeric($res);
            if ($val !== null) {
                return $val;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $profile */
    private function memory(string $host, SnmpCredential $community, array $profile): ?float
    {
        return match ($profile['mem'] ?? null) {
            'hrstorage' => $this->hrStorageMemory($host, $community),
            'cisco' => $this->ciscoMemory($host, $community, $profile),
            default => null,
        };
    }

    /**
     * Host-resources-MIB memory: walk the storage table, pick the physical-RAM row
     * (largest size among memory rows, skipping virtual/swap/cache), used/size %.
     */
    private function hrStorageMemory(string $host, SnmpCredential $community): ?float
    {
        $oids = config('mymate.device_metrics.hrstorage', []);
        $descr = $this->snmp->walk($host, $community, (string) $oids['descr']);
        $size = $this->numericValues($this->snmp->walk($host, $community, (string) $oids['size']));
        $used = $this->numericValues($this->snmp->walk($host, $community, (string) $oids['used']));

        $bestIndex = null;
        $bestSize = 0.0;
        foreach ($descr as $index => $label) {
            $l = strtolower((string) $label);
            $isRam = str_contains($l, 'physical memory') || str_contains($l, 'real memory')
                || str_contains($l, 'main memory') || $l === 'memory'
                || (str_contains($l, 'ram') && ! str_contains($l, 'virtual'));
            $isRam = $isRam && ! str_contains($l, 'virtual') && ! str_contains($l, 'swap')
                && ! str_contains($l, 'cache') && ! str_contains($l, 'buffer');

            if ($isRam && isset($size[$index]) && $size[$index] > $bestSize) {
                $bestSize = $size[$index];
                $bestIndex = $index;
            }
        }

        if ($bestIndex === null || $bestSize <= 0 || ! isset($used[$bestIndex])) {
            return null;
        }

        return ($used[$bestIndex] / $bestSize) * 100;
    }

    /** Cisco memory pools: sum used / (used + free) across pools. */
    private function ciscoMemory(string $host, SnmpCredential $community, array $profile): ?float
    {
        $used = $this->numericValues($this->snmp->walk($host, $community, (string) $profile['mem_used_walk']));
        $free = $this->numericValues($this->snmp->walk($host, $community, (string) $profile['mem_free_walk']));

        $totalUsed = array_sum($used);
        $totalFree = array_sum($free);
        $total = $totalUsed + $totalFree;

        return $total > 0 ? ($totalUsed / $total) * 100 : null;
    }

    /** @param array<string, mixed> $profile */
    private function temperature(string $host, SnmpCredential $community, array $profile): ?float
    {
        $divisor = max(1, (int) ($profile['temp_divisor'] ?? 1));
        $values = [];

        if (! empty($profile['temp_walk'])) {
            $values = $this->numericValues($this->snmp->walk($host, $community, (string) $profile['temp_walk']));
        }
        foreach ((array) ($profile['temp_oids'] ?? []) as $oid) {
            $values = [...$values, ...$this->numericValues($this->snmp->get($host, $community, [$oid]))];
        }

        // Ignore obvious non-readings (0 / sentinel) - take the hottest real sensor.
        $values = array_filter($values, static fn (float $v): bool => $v > 0);

        return $values === [] ? null : max($values) / $divisor;
    }

    /**
     * Resolve host + decrypted community. Mirrors SnmpThroughputDriver so a metrics
     * poll and a throughput poll fail the same way on a missing community.
     *
     * @return array{0: string, 1: SnmpCredential}
     */
    private function target(Device $device): array
    {
        $device->loadMissing('credential');
        $cred = SnmpCredential::fromCredential($device->credential);

        if (! $cred->isUsable()) {
            throw new SnmpClientException("Device {$device->id} ({$device->name}) has no usable SNMP credential.");
        }

        return [$device->mgmt_ip, $cred];
    }

    /**
     * Keep only numeric SNMP values, cast to float, preserving keys.
     *
     * @param  array<string, string>  $values
     * @return array<string, float>
     */
    private function numericValues(array $values): array
    {
        $out = [];
        foreach ($values as $index => $value) {
            if (is_numeric($value)) {
                $out[$index] = (float) $value;
            }
        }

        return $out;
    }

    /** @param array<string, string> $values */
    private function firstNumeric(array $values): ?float
    {
        foreach ($values as $value) {
            if (is_numeric($value)) {
                return (float) $value;
            }
        }

        return null;
    }
}
