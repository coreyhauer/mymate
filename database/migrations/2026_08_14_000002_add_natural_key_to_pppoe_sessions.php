<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Give `pppoe_sessions` the natural key its sweep now upserts on:
 * (device_id, username, caller_id).
 *
 * The table was written by DELETE-then-INSERT per device, so nothing ever needed a key - every
 * sweep threw the rows away and made new ones. That re-minted every id every five minutes,
 * which broke the read API's id-cursor pagination (a client walking pages lost every
 * concentrator swept mid-walk) and churned ~7.5M dead tuples a day. The sweep now upserts, and
 * an upsert needs a unique index to conflict on.
 *
 * caller_id becomes NOT NULL DEFAULT '' because Postgres treats NULLs as distinct in a unique
 * index: a session with no caller-id would never match its own previous row and would insert a
 * fresh duplicate on every single sweep - the exact bug the key exists to prevent.
 * PppoeSessionResource maps '' back to null on the way out, so the API shape is unchanged.
 *
 * Existing duplicates are collapsed (newest id wins) before the index is built, otherwise the
 * CREATE UNIQUE INDEX would fail on a table the delete-then-insert era may have left with
 * repeats.
 */
return new class extends Migration
{
    public function up(): void
    {
        // '' rather than NULL - see the class docblock.
        DB::statement("UPDATE pppoe_sessions SET caller_id = '' WHERE caller_id IS NULL");

        Schema::table('pppoe_sessions', function ($table): void {
            $table->text('caller_id')->nullable(false)->default('')->change();
        });

        // Collapse any pre-existing duplicates on the new key, keeping the highest id (the most
        // recently written row for that session).
        DB::statement(<<<'SQL'
            DELETE FROM pppoe_sessions a
             USING pppoe_sessions b
             WHERE a.device_id = b.device_id
               AND a.username  = b.username
               AND a.caller_id = b.caller_id
               AND a.id < b.id
            SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX pppoe_sessions_device_username_caller_unique
                ON pppoe_sessions (device_id, username, caller_id)
            SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS pppoe_sessions_device_username_caller_unique');

        Schema::table('pppoe_sessions', function ($table): void {
            $table->text('caller_id')->nullable()->default(null)->change();
        });
    }
};
