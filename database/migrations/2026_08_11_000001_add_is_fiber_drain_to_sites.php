<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marks the sites where a wireless backhaul chain stops being wireless and hands off to
 * fiber that carries it toward an NNI - the "fiber drain" for everything behind it.
 *
 * This is the terminator for the backhaul Path walk (App\Actions\Sites\ResolveBackhaulPath):
 * without it the walk has no idea when it has arrived and would run to the far edge of the
 * mesh. It is deliberately a separate boolean rather than a SiteKind, because a drain is
 * almost always ALSO a tower or a cabinet - Marshall and Nerstrand are towers that happen to
 * have the region's fiber handoff on them, and collapsing that into `kind` would throw away
 * what the site physically is.
 *
 * WHAT IT IS NOT: "there is fiber here". Freeborn and Hayward both have fiber and are NOT
 * drains - their backhaul carries on over FWA, so traffic never gets on that fiber. Braham
 * likewise has fiber but drains onward to Cambridge, which is the real drain. The test is
 * whether the BACKHAUL transits onto fiber at this site, not whether fiber is present
 * (Corey, 2026-08-11).
 *
 * Populate it with `sites:derive-fiber-drains`, which infers the flag from fiber adjacency to
 * a known NNI aggregator rather than a hand-maintained list, so it survives topology changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->boolean('is_fiber_drain')->default(false)->index()->after('kind');
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn('is_fiber_drain');
        });
    }
};
