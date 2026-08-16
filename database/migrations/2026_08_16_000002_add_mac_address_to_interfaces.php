<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Interface hardware address - the identity a 4,509-device fleet has never exported
 * anywhere. Captured at discovery from RouterOS `/interface/print` (`mac-address`) and SNMP
 * `ifPhysAddress`, normalised to lowercase colon form (`aa:bb:cc:dd:ee:ff`) so the two sources
 * agree on one representation and a lookup never has to guess a caller's casing/separator.
 *
 * NOT NULL DEFAULT '' (not nullable) for the same reason `ospf_neighbors.router_id` is: an
 * absent/all-zero MAC (a tunnel, a bridge, a port that hasn't answered yet) still needs a
 * value that participates cleanly in `WHERE mac_address != ''` and the sorted/distinct list
 * DeviceResource builds, without a NULL special-case at every call site.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('interfaces', function (Blueprint $table) {
            $table->text('mac_address')->default('')->after('description');
            $table->index('mac_address');
        });
    }

    public function down(): void
    {
        Schema::table('interfaces', function (Blueprint $table) {
            $table->dropColumn('mac_address');
        });
    }
};
