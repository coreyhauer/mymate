<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Per-client wireless registration-table state.
 *
 * App\Services\Polling\RouterOsDeviceMetricsDriver has always read
 * `/interface/wireless/registration-table/print` on every metrics poll of every RouterOS
 * radio - one row per associated station - then averaged signal/SNR/CCQ across the rows,
 * counted them, and thrown the rows themselves away. The per-row `mac-address` was discarded
 * with them, which is why MyMate exports no MAC anywhere and can't identify a device against
 * an external inventory by anything but IP. This table keeps the detail the poll already had
 * in hand, at no extra device round trip for the primary table (see ReadWireless / the driver
 * for the best-effort wifiwave2/CAPsMAN union, which does cost extra round trips).
 *
 * WRITE SEMANTICS mirror ospf_neighbors with one deliberate difference: PPPoE's "empty read is
 * untrusted" rule, not OSPF's "empty is a real, urgent state" rule. A registration table with
 * zero rows is exactly what a black-holing radio or a transient API hiccup produces too, and
 * unlike OSPF there is no companion "is wireless even configured" signal to tell the two apart
 * - so a device's existing rows are left alone whenever a read comes back empty, and the reaper
 * is the long-stop for a radio that has genuinely gone client-less for good. See
 * App\Actions\Polling\ReadWireless.
 *
 * device_id + mac_address are the natural key the upsert conflicts on. mac_address is NOT NULL
 * DEFAULT '' because Postgres treats NULL as distinct in a unique index - a client whose MAC
 * RouterOS somehow didn't report would otherwise insert a fresh duplicate row on every poll.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wireless_registrations', function (Blueprint $table): void {
            $table->id();
            // The radio the registration table was read ON, not the client. An AP sees its
            // associated stations; a CPE in station mode sees the one AP it's registered to.
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();

            // Which local interface this registration came off. Default '' (not nullable) for
            // the same NULL-in-a-unique-index reason as mac_address, even though it isn't part
            // of the key - keeps every non-nullable text column here consistently queryable.
            $table->text('interface')->default('');

            // The client's MAC, normalized lowercase colon form (aa:bb:cc:dd:ee:ff). Together
            // with device_id this is the natural key - see the class docblock for why it may
            // not be NULL.
            $table->text('mac_address')->default('');

            // RF at last poll. Nullable: not every registration-table variant reports every
            // field (wifiwave2 and CAPsMAN don't always match the classic wireless package).
            $table->double('signal_strength_dbm')->nullable();
            $table->double('signal_to_noise_db')->nullable();
            $table->double('tx_ccq_pct')->nullable();

            // Raw RouterOS rate strings ("300Mbps-40MHz-1S", ...) - no bps parser in V1, stored
            // verbatim so nothing is lost while that parser doesn't exist yet.
            $table->text('tx_rate')->default('');
            $table->text('rx_rate')->default('');

            // Parsed via App\Support\RouterOsDuration from RouterOS's compact duration strings.
            $table->bigInteger('uptime_seconds')->nullable();
            $table->bigInteger('last_activity_seconds')->nullable();

            // Set once, at first sight of this (device_id, mac_address) pair, and never
            // touched again by the upsert - see ReadWireless::persist. Answers "how long has
            // this client actually been coming back", which last_seen_at alone can't.
            $table->timestampTz('first_seen_at');
            // Commit-time stamp of the poll that last saw this client. Doubles as the
            // staleness horizon the read API's default filter and `mymate:wireless:reap` key
            // off, same contract as ospf_neighbors.last_seen_at.
            $table->timestampTz('last_seen_at');

            // (device_id): every per-device persist, and the prune that follows a non-empty read.
            $table->index('device_id');
            // (mac_address): "which radio(s) has this client been seen on" and the API's ?mac=.
            $table->index('mac_address');
            // (last_seen_at): the `?since=` delta pull and the reaper's cutoff.
            $table->index('last_seen_at');
        });

        // The natural key the persist upserts on. Raw SQL for a stable, explicit name.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX wireless_registrations_device_mac_unique
                ON wireless_registrations (device_id, mac_address)
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('wireless_registrations');
    }
};
