<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Provenance for rf_link_samples.rssi_dbm / rf_link_state.rssi_dbm - the LibreNMS
 * `sensor_type` the surviving reading actually came from (e.g. 'airos-rx', 'airos-af60-l',
 * 'mimosa-ptp-rx'), added as part of the 2026-07-31 signal-mapping correctness fix (see
 * App\Actions\Rf\PullLibreNmsRfMetrics class docblock - the incident this fixes was exactly a
 * value being stored with no record of which sensor it came from, making the bad mapping
 * invisible until someone happened to compare against ground truth). Null whenever rssi_dbm
 * itself is null (no eligible candidate, or the value was rejected by the sanity guard).
 *
 * rf_link_samples is RANGE-partitioned by ts - Postgres propagates a plain ADD COLUMN to every
 * existing + future partition automatically, so no per-partition DDL is needed here.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE rf_link_samples ADD COLUMN rssi_sensor_type varchar(64)');

        Schema::table('rf_link_state', function (Blueprint $table): void {
            $table->string('rssi_sensor_type', 64)->nullable()->after('rssi_dbm');
        });
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE rf_link_samples DROP COLUMN IF EXISTS rssi_sensor_type');

        Schema::table('rf_link_state', function (Blueprint $table): void {
            $table->dropColumn('rssi_sensor_type');
        });
    }
};
