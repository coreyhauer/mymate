<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Per-neighbour OSPF adjacency state.
 *
 * App\Actions\Polling\ReadOspf has always read `/routing/ospf/neighbor/print` on every device
 * with a RouterOS credential, counted the neighbours in the Full state, and thrown the rest of
 * each row away - so `devices.ospf_neighbors` could say "4" while nothing recorded WHICH four,
 * over which interface, in which state, or which one just disappeared. RouterOS publishes no
 * OSPF-MIB over SNMP, so that API read is the only source of this truth anywhere in the stack;
 * discarding it meant the data was unrecoverable after the fact.
 *
 * This table keeps the detail the poll already had in hand. It costs no extra device round
 * trips: the same reply that produces the count now also produces these rows.
 *
 * WRITE SEMANTICS mirror pppoe_sessions after its natural-key fix - upsert each adjacency the
 * read returned, delete only what it did not, both inside one transaction per device, with
 * `last_seen_at` stamped at commit. A neighbour's row therefore keeps a stable id for the life
 * of the adjacency, and an adjacency that drops disappears on the next poll of that device.
 *
 * router_id and neighbor_address are NOT NULL DEFAULT '' because they form the unique key the
 * upsert conflicts on, and Postgres treats NULLs as distinct in a unique index - a neighbour
 * missing either field would insert a fresh duplicate row on every single poll.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ospf_neighbors', function (Blueprint $table): void {
            $table->id();
            // The device that SEES this neighbour. Adjacencies are reported from both ends, so
            // a link between two monitored routers legitimately produces two rows - each is
            // that device's own view, and they can disagree (which is itself the signal).
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();

            // The neighbour's OSPF router-id, and the address the adjacency runs over. Together
            // with device_id these are the natural key - see the class docblock for why neither
            // may be NULL.
            $table->text('router_id')->default('');
            $table->text('neighbor_address')->default('');

            // Which local interface the adjacency is on. RouterOS 7 reports it directly; some
            // v6 builds do not, hence nullable.
            $table->text('interface')->nullable();

            // The adjacency state verbatim from RouterOS ("Full", "2-Way", "Init", "ExStart",
            // "Down", ...). Stored as reported rather than as an enum: a state this code has
            // never heard of must still be recorded, not dropped.
            $table->text('state')->nullable();
            // Full is the only state that means a usable adjacency, and it is what the existing
            // devices.ospf_neighbors count has always counted. Promoted to its own boolean so
            // "show me the adjacencies that are NOT up" is an indexed read rather than a scan
            // over a free-text column whose casing varies between RouterOS versions.
            $table->boolean('is_full')->default(false);

            // How long the adjacency has been up, parsed from RouterOS's compact duration
            // string by App\Support\RouterOsDuration. A number that keeps resetting is a
            // flapping neighbour - the thing this table exists to make visible.
            $table->bigInteger('adjacency_seconds')->nullable();
            // RouterOS's own count of how many times this adjacency has changed state. The
            // other half of flap detection, and it survives our own polling gaps.
            $table->integer('state_changes')->nullable();

            // OSPF topology context, all as reported.
            $table->text('instance')->nullable();
            $table->text('area')->nullable();
            $table->text('dr_id')->nullable();
            $table->text('backup_dr_id')->nullable();
            $table->integer('priority')->nullable();

            // Commit-time stamp: when the poll that wrote this row committed, not when its
            // batch started. Doubles as the staleness horizon - a device that stops being
            // polled stops refreshing these, and both the read API's default filter and
            // `mymate:ospf:reap` key off it.
            $table->timestampTz('last_seen_at');

            // (device_id): every per-device read, and the prune that follows each poll.
            // Postgres does not auto-index a foreign key column, so this is not redundant.
            $table->index('device_id');
            // (last_seen_at): the `?since=` delta pull and the reaper's cutoff.
            $table->index('last_seen_at');
            // (router_id): "who is adjacent to this router", from any device's point of view.
            $table->index('router_id');
        });

        // The natural key the sweep upserts on. Created as raw SQL for a stable, explicit name.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX ospf_neighbors_device_router_address_unique
                ON ospf_neighbors (device_id, router_id, neighbor_address)
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('ospf_neighbors');
    }
};
