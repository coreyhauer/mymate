<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only audit log for every change to `devices.site_id`.
 *
 * Device->site placement is wrong often enough (a sweep in Aug 2026 flagged ~890
 * candidates) that it needs both a self-service fix path and a way to undo one.
 * Every move - from the UI, from an agent proposal, or from an importer - is
 * written here by DeviceSiteAssigner, which is the only thing permitted to touch
 * `devices.site_id`. Nothing else should issue that UPDATE.
 *
 * `batch_id` is what makes a bulk correction revertible as a unit: a sweep that
 * moves 40 devices shares one batch_id, so undoing it is one call rather than 40
 * hand-written statements. `actor_name` snapshots the display name at write time
 * (same reasoning as notes.author_name) so history stays readable after a tech
 * leaves. `from_site_id`/`to_site_id` are deliberately plain integers with no FK:
 * sites get re-synced from UISP and we would rather keep an audit row pointing at
 * a since-deleted site than lose the record to a cascade.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_site_changes', function (Blueprint $table) {
            $table->id();
            $table->integer('device_id');
            $table->integer('from_site_id')->nullable();
            $table->integer('to_site_id')->nullable();

            // Who asked for it. actor_id is nullable because sync/automation has no user.
            $table->integer('actor_id')->nullable();
            $table->string('actor_name')->nullable();
            $table->string('actor_email')->nullable();

            // 'ui' | 'agent' | 'sync' | 'console' - where the write entered the system.
            $table->string('source', 16);
            $table->text('reason')->nullable();

            // Groups one logical correction so it can be reverted atomically.
            $table->uuid('batch_id')->nullable();

            // Set when this change has been undone; points at the change that undid it.
            $table->timestampTz('reverted_at')->nullable();
            $table->unsignedBigInteger('reverted_by_id')->nullable();

            $table->timestampsTz();

            $table->index(['device_id', 'created_at'], 'dsc_device_created_idx');
            $table->index('batch_id', 'dsc_batch_idx');
            $table->index(['source', 'created_at'], 'dsc_source_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_site_changes');
    }
};
