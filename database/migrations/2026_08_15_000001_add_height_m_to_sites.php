<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Real tower height, so line-of-sight checks stop guessing it from the device name.
 *
 * CheckLinkLineOfSight inferred antenna height from the ap/st role suffix - 45 m for an AP, 9 m
 * for an ST. That is wrong whenever a TOWER-mounted radio is named `...ST`, which is common for
 * the station end of a tower-to-tower backhaul, and it silently manufactures obstructions.
 *
 * It produced a real false diagnosis: Charleston CCI <-> Easton GL was reported as `los_only`
 * with -2.8 m clearance, and I concluded the 21 dB degradation was vegetation growing into the
 * path. Re-run with the actual heights (30 m both ends) the same tool returns **clear, +3.5 m,
 * foliage_depth 0.0** - no obstruction at all. The station end had been modelled 21 m too low
 * because its name ends in "ST".
 *
 * UISP carries a measured height for 2,727 of 2,735 sites (mean 28.2 m), so there is no need to
 * guess. Sites without one fall back to the old default, and that fallback is worth treating as
 * lower-confidence rather than fact.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->double('height_m')->nullable()->after('longitude');
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn('height_m');
        });
    }
};
