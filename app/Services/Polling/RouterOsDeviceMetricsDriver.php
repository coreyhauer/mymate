<?php

namespace App\Services\Polling;

use App\Models\Device;
use App\Services\RouterOs\RouterOsClient;
use App\Services\RouterOs\RouterOsTarget;

/**
 * CPU / memory / temperature + wireless RF over the RouterOS binary API (MikroTik). Reads
 * `/system/resource` (cpu-load + free/total memory), best-effort `/system/health`
 * (board/CPU temperature - shape differs across RouterOS 6 and 7, both handled), and the
 * wireless registration table (signal / SNR / CCQ / client count) when the board has radios.
 */
class RouterOsDeviceMetricsDriver implements DeviceMetricsDriver
{
    public function __construct(private RouterOsClient $client) {}

    public function sample(Device $device): DeviceMetrics
    {
        $conn = $this->client->open(RouterOsTarget::fromDevice($device));

        try {
            // The API needs the /print action - a bare "/system/resource" traps with
            // "no such command" and the whole read silently comes back null.
            $res = $conn->query('/system/resource/print')[0] ?? [];

            // Keep the running version fresh: if this poll finds a different RouterOS version
            // (upgraded/downgraded, here or out-of-band) update our record right away rather
            // than waiting for the slow discovery/facts cadence.
            $version = \App\Actions\Devices\UpgradeDevice::normalizeVersion((string) ($res['version'] ?? ''));
            if ($version !== null && $version !== $device->os_version) {
                $device->forceFill(['os_version' => $version])->save();
            }

            $cpu = isset($res['cpu-load']) && is_numeric($res['cpu-load']) ? (float) $res['cpu-load'] : null;

            $mem = null;
            $total = (float) ($res['total-memory'] ?? 0);
            $free = (float) ($res['free-memory'] ?? 0);
            if ($total > 0) {
                $mem = (($total - $free) / $total) * 100;
            }

            $wl = $this->wireless($conn);

            return new DeviceMetrics(
                cpuPct: DeviceMetrics::clampPct($cpu),
                memUsedPct: DeviceMetrics::clampPct($mem),
                tempC: $this->temperature($conn),
                signalDbm: $wl['signal'],
                snrDb: $wl['snr'],
                ccqPct: DeviceMetrics::clampPct($wl['ccq']),
                wirelessClients: $wl['clients'],
                // OSPF (neighbours + interface costs) is read separately in PollDeviceMetrics via
                // ReadOspf, so it works the same for a routeros-polled device and an snmp-polled
                // one with a routeros credential attached.
                wirelessRegistrations: $wl['rows'],
            );
        } finally {
            $conn->close();
        }
    }

    /**
     * Wireless RF from the registration table: one row per associated station (an AP sees
     * its clients; a CPE in station mode sees the one AP). We report the client count and the
     * average signal / SNR / CCQ across the rows - this aggregate contract is UNCHANGED
     * (DeviceResource depends on it) - and now ALSO return the raw rows so
     * App\Actions\Polling\PollDeviceMetrics can persist per-client detail via
     * App\Actions\Polling\ReadWireless without a second device round trip for the primary
     * table. Best-effort - a board with no wireless just leaves the aggregate null and 'rows'
     * empty.
     *
     * @return array{signal:?float, snr:?float, ccq:?float, clients:?int, rows: array<int, array<string, mixed>>}
     */
    private function wireless(\App\Services\RouterOs\RouterOsConnection $conn): array
    {
        $rows = $this->registrationRows($conn);

        if ($rows === []) {
            return ['signal' => null, 'snr' => null, 'ccq' => null, 'clients' => null, 'rows' => []];
        }

        $signals = $snrs = $ccqs = [];
        foreach ($rows as $row) {
            // signal-strength is like "-65dBm@6Mbps" or "-65"; pull the leading number.
            $s = self::firstNumber($row['signal-strength'] ?? null);
            if ($s !== null) {
                $signals[] = $s;
            }
            $n = self::firstNumber($row['signal-to-noise'] ?? null);
            if ($n !== null) {
                $snrs[] = $n;
            }
            $c = self::firstNumber($row['tx-ccq'] ?? null);
            if ($c !== null) {
                $ccqs[] = $c;
            }
        }

        $avg = static fn (array $v): ?float => $v === [] ? null : round(array_sum($v) / count($v), 1);

        return [
            'signal' => $avg($signals),
            'snr' => $avg($snrs),
            'ccq' => $avg($ccqs),
            'clients' => count($rows),
            'rows' => $rows,
        ];
    }

    /**
     * Best-effort union of every registration-table RouterOS can expose: the classic wireless
     * package, wifiwave2's `/interface/wifi/...`, and CAPsMAN's `/caps-man/...`. A board only
     * ever populates one of these (a CAPsMAN controller managing local APs can populate two),
     * so this just collects whatever exists rather than needing to know which the board runs.
     * Each command is independently best-effort - one RouterOS doesn't recognise just
     * contributes nothing, same as the single-command read this replaces.
     *
     * @return array<int, array<string, mixed>>
     */
    private function registrationRows(\App\Services\RouterOs\RouterOsConnection $conn): array
    {
        $rows = [];
        foreach ([
            '/interface/wireless/registration-table/print',
            '/interface/wifi/registration-table/print',
            '/caps-man/registration-table/print',
        ] as $command) {
            try {
                foreach ($conn->query($command) as $row) {
                    // Drop API !trap replies: an unsupported path (wifiwave2 / CAPsMAN on a
                    // classic radio) comes back as a {message, category} row the client library
                    // surfaces instead of throwing (seen live 2026-08-16). A trap is recognised
                    // by its shape - a `message` and no `mac-address` - so a genuine (if sparse)
                    // registration row still counts toward the client aggregate.
                    if (! is_array($row) || (isset($row['message']) && ! isset($row['mac-address']))) {
                        continue;
                    }
                    $rows[] = $row;
                }
            } catch (\Throwable) {
                // Not this board's path (or this RouterOS version doesn't have it) - contribute
                // nothing and try the next one.
            }
        }

        return $rows;
    }

    /** First signed/decimal number in a value (e.g. "-65dBm@6Mbps" -> -65.0), or null. */
    private static function firstNumber(mixed $value): ?float
    {
        if ($value === null) {
            return null;
        }

        return preg_match('/-?\d+(\.\d+)?/', (string) $value, $m) === 1 ? (float) $m[0] : null;
    }

    /** Best-effort - /system/health is unavailable on some boards; never let it fail the read. */
    private function temperature(\App\Services\RouterOs\RouterOsConnection $conn): ?float
    {
        try {
            $rows = $conn->query('/system/health/print');
        } catch (\Throwable) {
            return null;
        }

        $temps = [];
        foreach ($rows as $row) {
            // RouterOS 6: a single row with a `temperature` (and maybe `cpu-temperature`) key.
            foreach (['cpu-temperature', 'temperature', 'board-temperature'] as $key) {
                if (isset($row[$key]) && is_numeric($row[$key])) {
                    $temps[] = (float) $row[$key];
                }
            }
            // RouterOS 7: one row per sensor, {name: "...temperature", value: "42"}.
            $name = strtolower((string) ($row['name'] ?? ''));
            if (str_contains($name, 'temperature') && isset($row['value']) && is_numeric($row['value'])) {
                $temps[] = (float) $row['value'];
            }
        }

        $temps = array_filter($temps, static fn (float $v): bool => $v > 0);

        return $temps === [] ? null : max($temps);
    }
}
