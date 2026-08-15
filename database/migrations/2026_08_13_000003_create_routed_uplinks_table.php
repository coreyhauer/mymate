<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where each site's traffic ACTUALLY goes, learned from the routers' own forwarding tables.
 *
 * The Path feature walks physical `site_links` and stops at the nearest `is_fiber_drain`. That is
 * adjacency, not routing, and the two diverge - it reported Dennis Loucks draining at Glenville
 * when the router's table sends it to Austin. Jake Haley: "the 'designed drain' is incorrect, its
 * saying dloucks is part of the glenville network, it is part of the austin network, it might miss
 * twin towers because its bridged."
 *
 * Two reasons a physical walk cannot get this right:
 *   - A BRIDGED hop makes no routing decision at all (Twin Towers 5009 is fully bridged), so
 *     adjacency through it says nothing about where traffic goes.
 *   - SPLIT points feed two networks from one site - dgrotz ether2 is literally labelled
 *     "lickteg - Austin / Glenville Split". Only routing decides which way a given site goes.
 *
 * Collected per router via SNMP ipCidrRouteTable for 0.0.0.0/0 (two cheap walks, no SSH):
 *   next-hop  .1.3.6.1.2.1.4.24.4.1.4.0.0.0.0.0.0.0.0
 *   metric    .1.3.6.1.2.1.4.24.4.1.11.0.0.0.0.0.0.0.0
 *   proto     .1.3.6.1.2.1.4.24.4.1.7.0.0.0.0.0.0.0.0     (13 = ospf, 14 = bgp)
 * Lowest metric wins - on dl pppoe that is 10.0.7.90 (OSPF, 110) over two BGP routes at 200,
 * matching the CLI exactly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('routed_uplinks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->foreignId('device_id')->nullable()->constrained()->nullOnDelete();
            $table->string('next_hop_ip', 45);
            $table->unsignedInteger('metric')->nullable();
            // 13 = ospf, 14 = bgp, 2 = local, 3 = static/netmgmt
            $table->unsignedSmallInteger('proto')->nullable();
            // Resolved owner of next_hop_ip - the site traffic actually leaves toward.
            $table->foreignId('upstream_site_id')->nullable()->constrained('sites')->nullOnDelete();
            $table->foreignId('upstream_device_id')->nullable()->constrained('devices')->nullOnDelete();
            $table->timestamp('collected_at');
            $table->timestamps();

            $table->unique(['site_id', 'next_hop_ip']);
            $table->index('upstream_site_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('routed_uplinks');
    }
};
