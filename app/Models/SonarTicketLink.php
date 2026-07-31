<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A Sonar ticket linked to a device, site, or link (see the create_sonar_ticket_links_table
 * migration for the why). Cached fields are a point-in-time snapshot from Sonar's GraphQL
 * API - App\Services\Sonar\SonarClient::toCacheAttributes() is the single place that maps a
 * raw Sonar ticket payload onto these columns, so writers stay in sync.
 */
class SonarTicketLink extends Model
{
    protected $fillable = [
        'ticket_id', 'subject', 'status', 'priority', 'assignee_name', 'group_name', 'account_name',
        'ticketable_type', 'ticketable_id', 'sonar_created_at', 'sonar_closed_at', 'cached_at',
        'linked_by', 'linked_by_name',
    ];

    protected $casts = [
        'ticket_id' => 'integer',
        'ticketable_id' => 'integer',
        'sonar_created_at' => 'datetime',
        'sonar_closed_at' => 'datetime',
        'cached_at' => 'datetime',
        'linked_by' => 'integer',
    ];

    public function notable(): MorphTo
    {
        return $this->morphTo();
    }

    /** Who linked it (soft ref - no FK, users may be pruned; see AlertEvent::acknowledgedBy). */
    public function linkedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'linked_by');
    }
}
