<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How far BELOW its expected receive signal a radio is running.
 *
 * Complements chain imbalance, which finds ASYMMETRY between the two antenna chains. A link can be
 * perfectly balanced and still terrible, so this finds ABSOLUTE underperformance - "a three-mile
 * link at 2x modulation and -78 dBm is not what we expect if the link is LOS" (Corey 2026-08-12).
 *
 * Two ways to get an expectation, both validated before use:
 *
 *  - `vendor_ideal` (AF-LTU only, ~870 radios): the firmware itself reports
 *    `airos-af-ltu-ideal-rx-chain-0`, i.e. Ubiquiti's own computed expected signal. Free and exact.
 *  - `link_budget` (airOS, ~11,600 radios): expected = tx + gain_near + gain_far - FSPL(d,f).
 *    Most backhauls are airOS, so without this we would miss the bulk of the network.
 *
 * The budget is not guesswork: run against the 848 AF-LTU links where the firmware's own `ideal`
 * is known, it back-computes an implied antenna gain of **26.6 dBi median** against the 27 dBi
 * RF Elements StarterDish those actually carry - agreement to 0.4 dB. Same method also correctly
 * identified LTU-Rocket as ~17.5 dBi (a sector, not a dish) purely from data.
 *
 * DETECTION FLOOR ~6 dB: the implied-gain IQR across a single model is 5.8 dB, which is this
 * method's own scatter. Nothing under ~6 dB is meaningful here; that is what the chain-imbalance
 * step detector is for. This one finds GROSS impairment (the real cases run 25-55 dB).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rf_link_state', function (Blueprint $table) {
            $table->double('expected_rssi_dbm')->nullable()->after('chain_baseline_at');
            $table->double('signal_deficit_db')->nullable()->after('expected_rssi_dbm');
            // 'vendor_ideal' | 'link_budget' - so the map can weight confidence, and so a
            // deficit computed off an ASSUMED antenna gain is never mistaken for the
            // firmware's own number.
            $table->string('deficit_method', 16)->nullable()->after('signal_deficit_db');
            $table->double('deficit_gain_dbi')->nullable()->after('deficit_method');
            $table->timestamp('deficit_at')->nullable()->after('deficit_gain_dbi');
        });
    }

    public function down(): void
    {
        Schema::table('rf_link_state', function (Blueprint $table) {
            $table->dropColumn(['expected_rssi_dbm', 'signal_deficit_db', 'deficit_method',
                'deficit_gain_dbi', 'deficit_at']);
        });
    }
};
