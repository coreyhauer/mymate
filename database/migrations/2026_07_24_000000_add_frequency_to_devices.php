<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Live RF channel + detected OS on devices. Frequency is discovered over SNMP on a slow
 * cadence (see SnmpDeviceMetricsDriver::frequencyIfDue) and surfaced as a pill in the UI so
 * the name no longer has to carry a stale hand-typed frequency. `os` is the fine-grained
 * platform (airos / airos-af60 / routeros / ...) correlated from LibreNMS.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->string('os')->nullable()->after('vendor');
            $table->integer('freq_mhz')->nullable()->after('wireless_clients');
            $table->integer('chan_width_mhz')->nullable()->after('freq_mhz');
            $table->integer('freq_backup_mhz')->nullable()->after('chan_width_mhz');
            $table->integer('chan_width_backup_mhz')->nullable()->after('freq_backup_mhz');
            $table->timestamp('freq_at')->nullable()->after('chan_width_backup_mhz');
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropColumn(['os', 'freq_mhz', 'chan_width_mhz', 'freq_backup_mhz', 'chan_width_backup_mhz', 'freq_at']);
        });
    }
};
