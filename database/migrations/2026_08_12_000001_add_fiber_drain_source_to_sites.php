<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records WHY a site is flagged `is_fiber_drain` - derived from NNI adjacency, or set by hand.
 *
 * `sites:derive-fiber-drains` clears and rebuilds the flag on every run, which is what keeps it
 * true as fiber moves. But some drains are invisible to the derivation: Mankato Fiber Rooftop is
 * a real drain whose carrier handoff shows NO LLDP adjacency to an NNI at all, so no amount of
 * query-tuning will find it. Without this column an operator marking it by hand would silently
 * lose that flag at 00:25 the next morning - the worst kind of bug, because the map would go back
 * to quietly telling them "7 hops to Erdahl" for a site that drains locally.
 *
 * So: 'derived' rows are rebuilt each run, 'manual' rows are never touched by the deriver.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->string('fiber_drain_source', 16)->nullable()->after('is_fiber_drain');
        });

        // Everything currently flagged came from the deriver.
        \Illuminate\Support\Facades\DB::table('sites')
            ->where('is_fiber_drain', true)
            ->update(['fiber_drain_source' => 'derived']);
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn('fiber_drain_source');
        });
    }
};
