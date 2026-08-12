<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-link baseline for antenna-chain imbalance, so degradation is measured against what THIS
 * link has always looked like rather than a fleet-wide number.
 *
 * WHY: the first cut of the chain-imbalance overlay coloured any link over 6 dB. Checking that
 * against LibreNMS RRD history showed it was ~96% noise - 83 of 87 flagged links had looked
 * that way for months (Reinhart<->Cambridge has sat at 4-7 dB for THIRTEEN months and runs at
 * its expected 8x modulation with 99.5% link potential). Chain imbalance is largely a fixed
 * property of an installation: antenna port, cable run, mount geometry. The absolute number
 * says almost nothing.
 *
 * What DOES say something is a step change. The three genuinely broken links all moved on a
 * single day from a flat baseline:
 *   Leon Dorn <-> Paul Wallman   2.3 -> 20.1 dB on 2026-08-09
 *   Sage <-> Brettman            0.7 -> 10.1 dB on 2026-07-27
 *   B and B Ag <-> Bill Softley  4.4 -> 10.5 dB on 2026-07-18
 * That is the wind-turned-dish / water-in-pigtail signature, and it is invisible to a threshold.
 *
 * `baseline_at` records when the baseline was computed so a stale one can be recognised rather
 * than silently trusted - a baseline computed before a repair would keep flagging a fixed link.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rf_link_state', function (Blueprint $table) {
            $table->double('chain_imbalance_db')->nullable()->after('deviation_db');
            $table->double('chain_imbalance_baseline_db')->nullable()->after('chain_imbalance_db');
            $table->timestamp('chain_baseline_at')->nullable()->after('chain_imbalance_baseline_db');
        });
    }

    public function down(): void
    {
        Schema::table('rf_link_state', function (Blueprint $table) {
            $table->dropColumn(['chain_imbalance_db', 'chain_imbalance_baseline_db', 'chain_baseline_at']);
        });
    }
};
