<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Latest known ACTIVE PPPoE sessions per concentrator - the customer-facing data plane the
 * RF/interface tables never see. One row per live `/ppp/active` entry, refreshed wholesale
 * per device by App\Actions\Pppoe\SweepPppoeSessions (~5-minute cadence, see
 * mymate:pppoe:sweep + routes/console.php).
 *
 * Sweep semantics are DELETE-then-INSERT inside one transaction *per device*: the table
 * always answers "who is online right now, according to the last successful sweep of that
 * concentrator". A device whose sweep fails keeps its previous rows (stale, not missing) -
 * `swept_at` is the honest staleness stamp, so a reader can always tell how old an answer is.
 * That is deliberate: a failed RouterOS connect must never be indistinguishable from "this
 * concentrator has no customers online".
 *
 * NO history table in V1 (documented seam). Session churn on ~2,300 concentrators is a
 * partitioned-samples-table shape of problem (see rf_link_samples / interface_samples for the
 * pattern to copy) and nothing consumes it yet; the wholesale-replace table above is what the
 * read API and the Magi mirror need. Adding `pppoe_session_events` later is purely additive.
 *
 * Deliberately no `timestamps()`: `swept_at` is the only meaningful time on a row that is
 * recreated from scratch on every sweep, and created_at/updated_at would just be a second
 * copy of it written on every one of ~13k rows every 5 minutes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pppoe_sessions', function (Blueprint $table): void {
            $table->id();
            // The concentrator the session is terminated on. Cascade delete: a removed device's
            // sessions are meaningless (and would be orphaned forever - nothing else prunes them).
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            // The PPPoE username the customer authenticates with (RouterOS `/ppp/active` `name`).
            // Not unique: the same username CAN legitimately appear twice mid-reconnect (old
            // session not yet reaped on one concentrator, new one already up on another), and a
            // unique constraint would make a sweep fail rather than record reality.
            $table->text('username');
            // The address handed to the session (RouterOS `address`) - normally the customer's
            // IPv4. Text, not `ipAddress`: RouterOS can report shapes (v6, ranges) that a
            // strict inet column would reject, and a rejected row would sink a whole sweep.
            $table->text('remote_address')->nullable();
            // RouterOS `caller-id` - the CPE MAC for PPPoE (it is the caller id for other ppp
            // transports, hence the verbatim name). This is the join key to CPE inventory.
            $table->text('caller_id')->nullable();
            // Session age in seconds, parsed from RouterOS's compact duration string
            // ("2w3d4h5m6s") by App\Support\RouterOsDuration. Null when unparseable/absent.
            $table->bigInteger('uptime_seconds')->nullable();
            // When this device's sweep ran. Same value for every row written by one sweep, so
            // "latest sweep of device X" is a single equality, and staleness is readable per row.
            $table->timestampTz('swept_at');

            // (device_id): the wholesale-replace DELETE + every per-device read.
            // Postgres does NOT auto-index a foreign key column, so this is not redundant.
            $table->index('device_id');
            // (username): the customer lookup - "which concentrator is this subscriber on".
            $table->index('username');
            // (swept_at): the `?since=` incremental pull the Magi mirror uses.
            $table->index('swept_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pppoe_sessions');
    }
};
