<?php

namespace App\Models;

use App\Enums\BackupStatus;
use App\Enums\DeviceStatus;
use App\Enums\DeviceType;
use App\Enums\PollMethod;
use App\Enums\UpgradeStatus;
use Database\Factories\DeviceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Device extends Model
{
    /** @use HasFactory<DeviceFactory> */
    use HasFactory;

    protected $fillable = [
        'name', 'mgmt_ip', 'poll_method', 'credential_id', 'ssh_credential_id', 'routeros_credential_id', 'agent_id',
        'status', 'monitored', 'last_change', 'fail_streak', 'map_x', 'map_y', 'latitude', 'longitude', 'geo_source',
        'site_id', 'site_source',
        'device_type', 'icon', 'icon_color', 'parent_device_id', 'vendor', 'model', 'serial', 'cpu', 'ram_bytes', 'arch', 'uptime_seconds', 'uptime_at',
        'os_version', 'latest_version', 'upgrade_status', 'upgrade_message', 'upgrade_at',
        'discovery_error', 'discovered_at',
        'cpu_pct', 'mem_used_pct', 'temp_c', 'metrics_at',
        'signal_dbm', 'snr_db', 'ccq_pct', 'wireless_clients', 'ospf_neighbors',
        'os', 'freq_mhz', 'chan_width_mhz', 'freq_backup_mhz', 'chan_width_backup_mhz', 'freq_at',
        'rtt_ms', 'loss_pct', 'ping_at', 'latency_good_ms', 'latency_bad_ms',
        'backup_enabled', 'backup_driver', 'backup_status', 'backup_message', 'backup_at', 'backup_commit',
    ];

    protected $casts = [
        'poll_method' => PollMethod::class,
        'status' => DeviceStatus::class,
        'monitored' => 'boolean',
        'device_type' => DeviceType::class,
        'upgrade_status' => UpgradeStatus::class,
        'last_change' => 'datetime',
        'map_x' => 'float',
        'map_y' => 'float',
        'latitude' => 'float',
        'longitude' => 'float',
        'uptime_seconds' => 'integer',
        'ram_bytes' => 'integer',
        'uptime_at' => 'datetime',
        'cpu_pct' => 'float',
        'mem_used_pct' => 'float',
        'temp_c' => 'float',
        'metrics_at' => 'datetime',
        'freq_at' => 'datetime',
        'signal_dbm' => 'float',
        'snr_db' => 'float',
        'ccq_pct' => 'float',
        'wireless_clients' => 'integer',
        'ospf_neighbors' => 'integer',
        'rtt_ms' => 'float',
        'loss_pct' => 'float',
        'ping_at' => 'datetime',
        'latency_good_ms' => 'integer',
        'latency_bad_ms' => 'integer',
        'upgrade_at' => 'datetime',
        'discovered_at' => 'datetime',
        'backup_enabled' => 'boolean',
        'backup_status' => BackupStatus::class,
        'backup_at' => 'datetime',
    ];

    // In-memory defaults so a freshly created model mirrors the DB defaults
    // (otherwise status/device_type are null on the returned instance until refreshed).
    protected $attributes = [
        'status' => 'unknown',
        'monitored' => true,
        'device_type' => 'unknown',
        'map_x' => 0,
        'map_y' => 0,
        'backup_enabled' => false,
    ];

    public function credential(): BelongsTo
    {
        return $this->belongsTo(Credential::class);
    }

    /** Dedicated SSH credential for config backups (separate from the poll credential). */
    public function sshCredential(): BelongsTo
    {
        return $this->belongsTo(Credential::class, 'ssh_credential_id');
    }

    /** Optional RouterOS-API credential for reads SNMP can't do (OSPF neighbours), or null. */
    public function routerosCredential(): BelongsTo
    {
        return $this->belongsTo(Credential::class, 'routeros_credential_id');
    }

    /** The remote agent that polls this device, or null when polled centrally. */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    /** The physical location this device sits at, or null when it isn't assigned to one. */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /**
     * Where this device draws on the geo map: its own pin when it has one, otherwise its
     * site's coordinates.
     *
     * The inheritance is deliberately read-time rather than copied into the device columns on
     * assignment. Writing the site's coordinates onto every device would make a site that
     * moves (a corrected survey, a rebuild) leave thousands of devices behind at the old
     * position, and would make "has this device been placed itself?" unanswerable.
     *
     * @return array{0: float, 1: float}|null [lat, lng], or null when neither is placed
     */
    public function effectiveCoordinates(): ?array
    {
        if ($this->latitude !== null && $this->longitude !== null) {
            return [$this->latitude, $this->longitude];
        }

        $site = $this->relationLoaded('site') ? $this->site : $this->site()->first();

        return $site?->isPlaced() ? [$site->latitude, $site->longitude] : null;
    }

    /** The upstream device this one depends on (drives the hierarchy + inspector). */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_device_id');
    }

    /** @return HasMany<Device, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_device_id');
    }

    public function interfaces(): HasMany
    {
        return $this->hasMany(NetworkInterface::class);
    }
}
