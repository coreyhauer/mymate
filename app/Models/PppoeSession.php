<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Latest known active PPPoE session per concentrator, swept from RouterOS `/ppp/active` by the
 * PPPoE session sweeper (see the pppoe_sessions migration). A device's rows are wholesale-
 * replaced every sweep (DELETE the device's rows + INSERT the current set, in one transaction)
 * - a row's absence means the session wasn't active as of that device's last sweep, not that it
 * never existed. There is no history table in V1 - only the latest sweep per device is kept.
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
