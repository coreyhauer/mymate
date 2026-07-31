<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * GitHub #15: anonymous wallboard links. A share is an unguessable token bound to one map
 * that grants a read-only, no-login view of that map's wallboard. Revocable (delete the row,
 * or flip `enabled`). Nothing durable lives behind it - the public endpoints only ever read.
 *
 * Shares its `2026_07_24_000000` stamp with add_frequency_to_devices - harmless (different
 * filenames sort deterministically, and the two touch different tables); renaming either would
 * re-run it on installs that already applied it under the original name.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('map_shares', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('map_id')->constrained('maps')->cascadeOnDelete();
            // The bearer capability in the URL - long, random, unguessable. Indexed for lookup.
            $table->string('token', 64)->unique();
            $table->string('label')->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamp('last_viewed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('map_shares');
    }
};
