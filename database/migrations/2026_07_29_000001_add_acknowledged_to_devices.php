<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A device-level acknowledgement: a known/intentional down (suspended customer,
 * polling-disabled gear, cancelled ONU) that shouldn't clutter the actionable
 * outage list. Acked devices are hidden from the default Outages view.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->boolean('acknowledged')->default(false)->index();
            $table->timestamp('ack_at')->nullable();
            $table->string('ack_note')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropColumn(['acknowledged', 'ack_at', 'ack_note']);
        });
    }
};
