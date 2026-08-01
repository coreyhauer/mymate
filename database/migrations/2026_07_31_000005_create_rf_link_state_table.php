<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Latest known RF/link-health reading per device - the materialised "current value" table a
 * later map/API stage reads (design §2e), so the map's request path never queries LibreNMS
 * live. One row per device, upserted every ~5-minute pull
 * (App\Actions\Rf\PullLibreNmsRfMetrics). Mirrors the ap_customer_counts precedent already in
 * this codebase (small denormalised table synced periodically from an external source,
 * left-joined into the fast read path) rather than inventing a new one.
 *
 * For a multi-carrier device (rare - Aviat WTM 1+1 diversity) this reflects the worse-
 * performing carrier (App\Actions\Rf\PullLibreNmsRfMetrics::pickWorstForState); `sensor_index`
 * records which one, for anyone who needs to cross-reference rf_link_samples.
 *
 * `baseline_rssi_dbm`/`deviation_db` are reserved for the baseline/alerting stage (design §4)
 * - this task builds ingestion + storage only, so they ship NULL and unused; the column shape
 * exists now so that later work is purely additive (no migration needed to add them).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rf_link_state', function (Blueprint $table): void {
            $table->foreignId('device_id')->primary()->constrained()->cascadeOnDelete();
            // 64 to match LibreNMS's own wireless_sensors.sensor_index width (see
            // create_rf_link_samples_table for the real-world example that forced this).
            $table->string('sensor_index', 64)->nullable();
            $table->double('rssi_dbm')->nullable();
            $table->double('noise_floor_dbm')->nullable();
            $table->double('snr_db')->nullable();
            $table->string('snr_source', 8)->nullable(); // 'native' | 'derived'
            $table->double('rate_mbps')->nullable();
            $table->double('channel_util_pct')->nullable();
            $table->double('tx_power_dbm')->nullable();
            $table->double('distance_mi')->nullable();
            $table->double('freq_mhz')->nullable();
            $table->bigInteger('if_errors_in')->nullable();
            $table->bigInteger('if_errors_out')->nullable();
            // Reserved for the baseline/alerting stage (design §4) - always NULL for now.
            $table->double('baseline_rssi_dbm')->nullable();
            $table->double('deviation_db')->nullable();
            // Two independent staleness signals (design §2f), both required so a link with
            // no fresh data never silently reads as "good": LibreNMS's own poll timestamp,
            // and when My Mate itself last synced this row.
            $table->timestamp('source_lastupdate')->nullable();
            $table->timestamp('synced_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rf_link_state');
    }
};
