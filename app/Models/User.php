<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * An operator account. Auth is Sanctum SPA (stateful cookie session);
 * `HasApiTokens` is kept for optional personal access tokens.  adds a single
 * `is_admin` tier: admins manage operator accounts, normal operators are view-only.
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    // NOTE: `is_admin` is deliberately NOT fillable - the admin tier is a privilege, so
    // it's set explicitly in the controller *after* the admin gate, never from a mass-
    // assigned payload. An operator cannot self-escalate by smuggling it into a request.
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed', // auto-hash on set
        'is_admin' => 'boolean',
        'can_move_devices' => 'boolean',
    ];

    /** Admins can manage operator accounts; normal operators are view-only. */
    /**
     * May this operator move a device to a different site?
     *
     * Separate from isAdmin() on purpose: site placement drives outage attribution and
     * the map's backhaul lines, so it is granted to the few people who own placement
     * rather than to every admin, and withheld from field techs.
     */
    public function canMoveDevices(): bool
    {
        // NOT `|| is_admin`: this is a separate grant on purpose, so a future admin
        // does not silently inherit the ability to move devices between sites.
        return (bool) $this->can_move_devices;
    }

    public function isAdmin(): bool
    {
        return (bool) $this->is_admin;
    }
}
