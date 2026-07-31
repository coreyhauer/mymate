<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A threaded note attached to a device, site, or link (see the create_notes_table
 * migration for the why). One flat table, not three - `notable` is polymorphic.
 */
class Note extends Model
{
    protected $fillable = ['body', 'author_id', 'author_name'];

    public function notable(): MorphTo
    {
        return $this->morphTo();
    }

    /** Who wrote it (soft ref - no FK, users may be pruned; see AlertEvent::acknowledgedBy). */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}
