<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Partial index on the *open* outages only.
 *
 * The geo map feed (/geo/devices) left-joins every placed device to its still-open outage to show
 * how long it has been down. The existing (device_id, started_at) index doesn't help that join -
 * it can't skip the ~936k closed rows - whereas open outages are one row per currently-down device
 * (~1.2k), so a partial index is both tiny and exactly the working set. Built CONCURRENTLY so the
 * live poller keeps writing outages during the migration.
 */
return new class extends Migration
{
    public $withinTransaction = false; // CREATE INDEX CONCURRENTLY cannot run in a transaction

    public function up(): void
    {
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS outages_open_device_id_index
            ON outages (device_id) WHERE ended_at IS NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS outages_open_device_id_index');
    }
};
