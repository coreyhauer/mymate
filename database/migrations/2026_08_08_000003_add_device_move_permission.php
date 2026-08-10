<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Who may move a device between sites.
 *
 * Deliberately NOT `is_admin`: moving a device is a narrow, high-consequence action
 * (site placement drives outage attribution and the map's backhaul lines) that a
 * small group needs and field techs should not have. Overloading is_admin would
 * either hand out far more than intended or block the people who actually do this.
 *
 * Off by default, so the Voltron middleware's auto-provisioning of a first-seen user
 * can never grant it. Seeded here for the four operators who own site placement today;
 * anyone else is granted deliberately, the same way is_admin is.
 */
return new class extends Migration
{
    /** Operators who own device->site placement (agreed 2026-08-08). */
    private const GRANTEES = [
        'coreyhauer@ltdbroadband.com',      // Corey Hauer
        'brendannmcgregor@ltdbroadband.com',// Brendann Mcgregor
        'jakehaley@ltdbroadband.com',       // Jake Haley
        'ethanludwig@ltdbroadband.com',     // Ethan Ludwig
    ];

    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('can_move_devices')->default(false)->after('is_admin');
        });

        DB::table('users')->whereIn(DB::raw('lower(email)'), self::GRANTEES)
            ->update(['can_move_devices' => true]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('can_move_devices');
        });
    }
};
