<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-device connect-failure backoff (circuit breaker) for the throughput/metrics
 * pollers - deliberately separate from `fail_streak`, which belongs to the ICMP
 * ping sweep (MYMATE_PING_FAIL_THRESHOLD) and drives up/down status, not this.
 *
 * `poll_fail_streak` counts consecutive *transport* failures (connect timeout,
 * refused, no route) on the RouterOS/SNMP poll path - never an auth failure or a
 * missing/misconfigured credential, which mean the device is alive and answering
 * (see ConnectBackoff::isTransportFailure). `poll_backoff_until` is the skip
 * window computed from that streak (mymate.poll.connect_backoff.schedule_minutes);
 * a device with a future `poll_backoff_until` is filtered out of the batch query
 * entirely, so it costs nothing to skip - no connection is ever attempted.
 *
 * Any successful connect resets both columns. This is the fix for whole poll
 * shards being killed by a pile of dead devices each burning the full RouterOS
 * connect timeout inside one Horizon job's 60s budget, taking healthy SNMP
 * devices sharing that shard down as collateral damage.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->unsignedSmallInteger('poll_fail_streak')->default(0)->after('fail_streak');
            $table->timestamp('poll_backoff_until')->nullable()->index()->after('poll_fail_streak');
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropColumn(['poll_fail_streak', 'poll_backoff_until']);
        });
    }
};
