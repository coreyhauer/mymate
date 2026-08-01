<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nightly rollup of rf_link_samples (App\Actions\Rf\RollupRfLinkDailyStats), one row per
 * device+sensor_index+day. This is the table the wind-misalignment baseline (design §4b)
 * will read from once that stage is built: raw rf_link_samples only lives 14 days (same
 * retention as interface_samples/device_metric_samples), but the baseline needs a rolling
 * 30-day window - solved here with a small, cheap, indefinitely-retained rollup rather than
 * extending raw retention (which would multiply disk use ~2x for no benefit, since the
 * baseline only ever needs daily median/p10/stddev, not 5-minute resolution).
 *
 * Not partitioned - unlike rf_link_samples this table is tiny (device_count x ~365 days is
 * still a few million rows at full fleet size) and isn't pruned by
 * ManageHistoryPartitions; it's cheap enough to keep indefinitely (design §2d). No FK
 * cascade needed beyond the standard one - a deleted device's rollup rows are harmless
 * orphans until GC, same tradeoff as the raw table, but low volume enough it doesn't matter.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rf_link_daily_stats', function (Blueprint $table): void {
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            $table->date('day');
            // 64 to match LibreNMS's own wireless_sensors.sensor_index width - real values can
            // run well past 16 chars (e.g. "integraXmodemBnormalizedMse", 27 chars).
            $table->string('sensor_index', 64)->default('');
            $table->double('rssi_median')->nullable();
            $table->double('rssi_p10')->nullable(); // worst 10th percentile of the day
            $table->double('rssi_stddev')->nullable();
            $table->double('snr_median')->nullable();
            $table->double('rate_median_mbps')->nullable();
            $table->unsignedInteger('sample_count')->default(0);
            $table->timestamps();

            $table->primary(['device_id', 'day', 'sensor_index']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rf_link_daily_stats');
    }
};
