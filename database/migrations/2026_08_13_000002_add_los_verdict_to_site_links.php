<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LiDAR line-of-sight verdict per backhaul link, so a signal deficit can be read as a FAULT
 * rather than as physics.
 *
 * Without this the underperformance overlay flags every chronically NLOS link. Corey called that
 * on marshall2codyP (Cody Przymus): worst deficit on the network at 52-55 dB, but the LiDAR tool
 * returns `blocked` with -5.2 m clearance and a 16.6 m treeline at his end of a 1.89 km path with
 * the CPE at ~9 m. It is working as well as physics permits and there is nothing to dispatch.
 *
 * Crucially a self-baseline CANNOT filter these out - a chronically obstructed link has been bad
 * forever, so it never looks like a change. Only an external terrain check separates "obstructed"
 * from "broken".
 *
 * verdict: 'clear' | 'los_only' | 'blocked' | 'no_lidar' | 'bad_geo'
 *   - los_only  = geometric line of sight but Fresnel intrusion; degraded, not necessarily faulty
 *   - bad_geo   = the two ends' GPS disagree with the radios' own reported distance, so the
 *                 profile would be meaningless. LibreNMS `locations` is wrong often enough to
 *                 matter (seen 213 km / 89 km / 0.00 km against links the radio reports as 12.0 /
 *                 10.8 / 15.2 km), and a -329 m "clearance" is the tell.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_links', function (Blueprint $table) {
            $table->string('los_verdict', 12)->nullable()->after('endpoints_resolved_at');
            $table->double('los_clearance_m')->nullable()->after('los_verdict');
            $table->double('los_canopy_m')->nullable()->after('los_clearance_m');
            $table->double('los_distance_km')->nullable()->after('los_canopy_m');
            $table->timestamp('los_checked_at')->nullable()->after('los_distance_km');
        });
    }

    public function down(): void
    {
        Schema::table('site_links', function (Blueprint $table) {
            $table->dropColumn(['los_verdict', 'los_clearance_m', 'los_canopy_m',
                'los_distance_km', 'los_checked_at']);
        });
    }
};
