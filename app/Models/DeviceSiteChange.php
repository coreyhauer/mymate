<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One recorded move of a device between sites. Written only by
 * App\Services\Assignment\DeviceSiteAssigner - never constructed directly.
 */
class DeviceSiteChange extends Model
{
    protected $table = 'device_site_changes';

    protected $fillable = [
        'device_id', 'from_site_id', 'to_site_id',
        'actor_id', 'actor_name', 'actor_email',
        'source', 'reason', 'batch_id',
        'reverted_at', 'reverted_by_id',
    ];

    protected $casts = [
        'reverted_at' => 'datetime',
    ];

    public const SOURCE_UI      = 'ui';
    public const SOURCE_AGENT   = 'agent';
    public const SOURCE_SYNC    = 'sync';
    public const SOURCE_CONSOLE = 'console';

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function fromSite(): BelongsTo
    {
        return $this->belongsTo(Site::class, 'from_site_id');
    }

    public function toSite(): BelongsTo
    {
        return $this->belongsTo(Site::class, 'to_site_id');
    }

    public function isReverted(): bool
    {
        return $this->reverted_at !== null;
    }
}
