<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One OSPF adjacency as seen BY one device, captured by App\Actions\Polling\ReadOspf from the
 * same `/routing/ospf/neighbor/print` reply that produces `devices.ospf_neighbors` (see the
 * ospf_neighbors migration).
 *
 * "As seen by" is the important part: adjacencies are reported from both ends, so a link
 * between two monitored routers produces two rows. They can disagree - one side Full while the
 * other is stuck in ExStart is a real and diagnostically useful state, not a bug to normalise
 * away.
 *
 * Rows are upserted on (device_id, router_id, neighbor_address) and anything a poll does not
 * return is deleted, so `id` is stable for the life of an adjacency and a dropped adjacency
 * disappears on the next poll of that device. `last_seen_at` is stamped when the writing
 * transaction commits; a device that stops being polled stops refreshing it, which is what the
 * read API's default freshness filter and `mymate:ospf:reap` key off.
 *
 * `router_id` and `neighbor_address` are stored as '' rather than NULL because they take part
 * in a unique index (a NULL never conflicts, so the row would duplicate every poll).
 */
class OspfNeighbor extends Model
{
    protected $table = 'ospf_neighbors';

    public $timestamps = false;

    protected $fillable = [
        'device_id', 'router_id', 'neighbor_address', 'interface', 'state', 'is_full',
        'adjacency_seconds', 'state_changes', 'instance', 'area', 'dr_id', 'backup_dr_id',
        'priority', 'last_seen_at',
    ];

    protected $casts = [
        'is_full' => 'boolean',
        'adjacency_seconds' => 'integer',
        'state_changes' => 'integer',
        'priority' => 'integer',
        'last_seen_at' => 'datetime',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
