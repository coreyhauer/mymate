<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Latest known active PPPoE session per concentrator, swept from RouterOS `/ppp/active` by the
 * PPPoE session sweeper (see the pppoe_sessions migration). A device's rows are reconciled
 * every sweep - each live session is upserted on its natural key (device_id, username,
 * caller_id) and anything the read did not return is deleted, both in one transaction. A row's
 * absence means the session wasn't active as of that device's last sweep, not that it never
 * existed. There is no history table in V1 - only the latest sweep per device is kept.
 *
 * Because the write is an upsert, `id` is STABLE for as long as a session lives: it is minted
 * when the session first appears and survives every subsequent sweep. That is what makes the
 * read API's id cursor safe to page through, and it lets a delta consumer dedupe by id.
 *
 * `caller_id` is stored as '' rather than NULL (a NULL cannot participate in a Postgres unique
 * index); PppoeSessionResource presents it as null.
 */
class PppoeSession extends Model
{
    protected $table = 'pppoe_sessions';

    public $timestamps = false;

    protected $fillable = [
        'device_id', 'username', 'remote_address', 'caller_id', 'uptime_seconds', 'swept_at',
    ];

    protected $casts = [
        'uptime_seconds' => 'integer',
        'swept_at' => 'datetime',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
