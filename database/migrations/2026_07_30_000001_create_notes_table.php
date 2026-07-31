<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Threaded notes attachable to a device, site, or link - a running log an operator can
 * leave for the next person on-call (e.g. "swapped the PoE injector, keep an eye on it").
 *
 * `author_id` is a soft reference - no FK, deliberately, matching AlertEvent.acknowledged_by
 * (operator accounts may be pruned and a note shouldn't cascade-delete or dangle a broken FK
 * when that happens). `author_name` snapshots the display name at write time so the note
 * stays readable ("- Jane Doe") even after the author is gone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notes', function (Blueprint $table) {
            $table->id();
            $table->morphs('notable'); // notable_type + notable_id, indexed
            $table->text('body');
            $table->integer('author_id')->nullable();
            $table->string('author_name')->nullable();
            $table->timestamps();

            // The feed for one subject, newest first - `morphs()` already indexes
            // (notable_type, notable_id) but not with created_at, so add it explicitly.
            $table->index(['notable_type', 'notable_id', 'created_at'], 'notes_notable_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notes');
    }
};
