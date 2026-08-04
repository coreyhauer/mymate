<?php

namespace App\Services\Polling;

use App\Models\Device;
use App\Services\RouterOs\RouterOsClientException;
use App\Services\Snmp\SnmpClientException;
use Throwable;

/**
 * Per-device connect-failure circuit breaker for the throughput/metrics pollers
 * (PollInterfaces / PollDeviceMetrics). Tracks consecutive *transport* failures
 * (connect timeout, refused, no route) on `devices.poll_fail_streak` and, once a
 * device is clearly dead, sets `poll_backoff_until` so the batch query
 * (see both actions) skips it entirely - no connection attempted - until the
 * window expires. Any successful connect resets both columns immediately.
 *
 * Deliberately independent of `devices.fail_streak`, which belongs to the ICMP
 * ping sweep (PingFleet, mymate.ping.fail_threshold) and drives up/down status -
 * a different signal on a different cadence.
 *
 * Only a *transport* failure counts (see isTransportFailure()). An auth failure
 * or a missing/misconfigured credential means the device answered - it's alive,
 * just misconfigured - so it must NOT trip this breaker; it's retried every
 * cycle same as before (the operator needs to see it fail to notice and fix it).
 */
class ConnectBackoff
{
    /**
     * Record a per-device poll failure. No-op (nothing written) unless `$e` is a genuine
     * transport failure and the breaker is enabled - an auth/config failure leaves the
     * streak and backoff untouched.
     */
    public function recordFailure(Device $device, Throwable $e): void
    {
        if (! config('mymate.poll.connect_backoff.enabled', true) || ! $this->isTransportFailure($e)) {
            return;
        }

        $streak = (int) $device->poll_fail_streak + 1;
        $schedule = $this->scheduleSeconds();
        $seconds = $schedule[min($streak, count($schedule)) - 1];

        $device->forceFill([
            'poll_fail_streak' => $streak,
            'poll_backoff_until' => now()->addSeconds($seconds),
        ])->save();
    }

    /**
     * Clear any backoff after a successful connect. A no-op write is skipped (the common
     * case - most devices never fail) so a healthy fleet doesn't take an UPDATE every tick.
     */
    public function recordSuccess(Device $device): void
    {
        if ((int) $device->poll_fail_streak === 0 && $device->poll_backoff_until === null) {
            return;
        }

        $device->forceFill([
            'poll_fail_streak' => 0,
            'poll_backoff_until' => null,
        ])->save();
    }

    /**
     * True only for a *reachability* failure - the device never answered at all. Both
     * RouterOsClientException and SnmpClientException carry an explicit `$transport` flag set
     * at the one place each library actually attempts the wire-level connect/exchange (see
     * their docblocks); everything else (an untyped/unexpected Throwable from deeper in a
     * driver) is treated conservatively as NOT a transport failure, so an unrelated bug can't
     * silently start backing a device off.
     */
    public function isTransportFailure(Throwable $e): bool
    {
        return match (true) {
            $e instanceof RouterOsClientException => $e->transport,
            $e instanceof SnmpClientException => $e->transport,
            default => false,
        };
    }

    /** @return list<int> backoff windows in seconds, ascending, streak-1-indexed. */
    private function scheduleSeconds(): array
    {
        /** @var list<int> $minutes */
        $minutes = config('mymate.poll.connect_backoff.schedule_minutes', [1, 2, 5, 10, 30]);
        $seconds = array_values(array_map(static fn ($m): int => max(1, (int) $m) * 60, $minutes));

        return $seconds === [] ? [60] : $seconds;
    }
}
