<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sonar (gigfire.sonar.software) tickets linked to a device, site, or link - the NOC's
 * live-ticket panel. My Mate only ever *reads* Sonar's GraphQL API (see App\Services\Sonar\
 * SonarClient) - these rows are a local pointer + a cache of the fields the panel shows,
 * refreshed on read when stale and periodically for open tickets (see
 * RefreshSonarTicketLinksCommand). `linked_by` is a soft reference (no FK - users may be
 * pruned), mirroring notes.author_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sonar_ticket_links', function (Blueprint $table) {
            $table->id();
            $table->morphs('notable'); // notable_type + notable_id, indexed
            $table->bigInteger('ticket_id'); // Sonar's ticket id (Int64Bit)

            // Cached snapshot from Sonar - refreshed on read (stale) / on schedule (open).
            $table->string('subject')->nullable();
            $table->string('status')->nullable();
            $table->string('priority')->nullable();
            $table->string('assignee_name')->nullable();
            $table->string('group_name')->nullable();
            $table->string('account_name')->nullable();
            $table->string('ticketable_type')->nullable(); // Sonar's TicketableType: Account | NetworkSite
            $table->bigInteger('ticketable_id')->nullable();
            $table->timestamp('sonar_created_at')->nullable();
            $table->timestamp('sonar_closed_at')->nullable();
            $table->timestamp('cached_at')->nullable();

            $table->integer('linked_by')->nullable();
            $table->string('linked_by_name')->nullable();

            $table->timestamps();

            // Can't link the same Sonar ticket to the same subject twice.
            $table->unique(['notable_type', 'notable_id', 'ticket_id'], 'sonar_ticket_links_notable_ticket_unique');
            // The scheduled refresh selects "not CLOSED" links across the whole table.
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sonar_ticket_links');
    }
};
