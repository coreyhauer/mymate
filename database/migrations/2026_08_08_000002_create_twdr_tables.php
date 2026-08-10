<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * TDWR (Terminal Doppler Weather Radar) interference guard.
 *
 * TDWR occupies 5600-5650 MHz. FCC rules bar U-NII gear from operating on an
 * overlapping channel within 35 km (~22 mi) of a TDWR installation, and interference
 * with airport weather radar is an enforcement matter, not a customer complaint - so
 * this is checked continuously rather than audited after the fact.
 *
 * `twdr_sites` is static reference data seeded here (47 sites, WISPA/FAA published
 * table, Jan 2024) - coordinates converted from DMS to decimal degrees, and each row
 * carries the radar's ACTUAL operating frequency so the guard can be evaluated against
 * a real centre rather than blanket-blocking the whole band.
 *
 * `twdr_risk_events` mirrors the AlertEvent lifecycle (one open row per dedupe_key,
 * resolved when the condition clears) so a radio retuned or moved INTO a violation
 * raises a new event, and one moved out of it resolves rather than lingering.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('twdr_sites', function (Blueprint $table) {
            $table->id();
            $table->string('state', 2);
            $table->string('city');
            $table->decimal('latitude', 10, 6);
            $table->decimal('longitude', 10, 6);
            $table->integer('freq_mhz');
            $table->timestampsTz();
        });

        Schema::create('twdr_risk_events', function (Blueprint $table) {
            $table->id();
            $table->string('dedupe_key')->unique();   // device + radar pair
            $table->integer('device_id');
            $table->unsignedBigInteger('twdr_site_id');
            $table->string('level', 16);              // 'violation' (FCC) | 'warning' (LTD policy)
            $table->decimal('distance_km', 8, 2);
            $table->decimal('separation_mhz', 8, 2);
            $table->integer('device_freq_mhz');
            $table->integer('device_width_mhz')->nullable();
            $table->text('detail')->nullable();
            $table->timestampTz('opened_at');
            $table->timestampTz('last_seen_at');
            $table->timestampTz('resolved_at')->nullable();
            $table->timestampsTz();

            $table->index(['device_id', 'resolved_at'], 'twdr_dev_open_idx');
            $table->index('resolved_at', 'twdr_open_idx');
        });

        $now = now();
        DB::table('twdr_sites')->insert(array_map(
            fn ($r) => $r + ['created_at' => $now, 'updated_at' => $now],
            [
            ['state' => 'AZ', 'city' => 'PHOENIX', 'latitude' => 33.42056, 'longitude' => -112.16278, 'freq_mhz' => 5610],
            ['state' => 'CO', 'city' => 'DENVER', 'latitude' => 39.7275, 'longitude' => -104.52639, 'freq_mhz' => 5615],
            ['state' => 'FL', 'city' => 'FT LAUDERDALE', 'latitude' => 26.14333, 'longitude' => -80.34417, 'freq_mhz' => 5645],
            ['state' => 'FL', 'city' => 'MIAMI', 'latitude' => 25.7575, 'longitude' => -80.49111, 'freq_mhz' => 5605],
            ['state' => 'FL', 'city' => 'ORLANDO', 'latitude' => 28.34361, 'longitude' => -81.32583, 'freq_mhz' => 5640],
            ['state' => 'FL', 'city' => 'TAMPA', 'latitude' => 27.85972, 'longitude' => -82.51778, 'freq_mhz' => 5620],
            ['state' => 'FL', 'city' => 'WEST PALM BEACH', 'latitude' => 26.68806, 'longitude' => -80.27306, 'freq_mhz' => 5615],
            ['state' => 'GA', 'city' => 'ATLANTA', 'latitude' => 33.64667, 'longitude' => -84.26222, 'freq_mhz' => 5615],
            ['state' => 'IL', 'city' => 'MCCOOK', 'latitude' => 41.79722, 'longitude' => -87.85861, 'freq_mhz' => 5615],
            ['state' => 'IL', 'city' => 'CRESTWOOD', 'latitude' => 41.65139, 'longitude' => -87.72972, 'freq_mhz' => 5645],
            ['state' => 'IN', 'city' => 'INDIANAPOLIS', 'latitude' => 39.63722, 'longitude' => -86.43556, 'freq_mhz' => 5605],
            ['state' => 'KS', 'city' => 'WICHITA', 'latitude' => 37.50722, 'longitude' => -97.43694, 'freq_mhz' => 5603],
            ['state' => 'KY', 'city' => 'COVINGTON CINCINNATI', 'latitude' => 38.89806, 'longitude' => -84.58, 'freq_mhz' => 5610],
            ['state' => 'KY', 'city' => 'LOUISVILLE', 'latitude' => 38.04583, 'longitude' => -85.61056, 'freq_mhz' => 5646],
            ['state' => 'LA', 'city' => 'NEW ORLEANS', 'latitude' => 30.02167, 'longitude' => -90.40306, 'freq_mhz' => 5645],
            ['state' => 'MA', 'city' => 'BOSTON', 'latitude' => 42.15833, 'longitude' => -70.93361, 'freq_mhz' => 5610],
            ['state' => 'MD', 'city' => 'BRANDYWINE', 'latitude' => 38.69528, 'longitude' => -76.845, 'freq_mhz' => 5635],
            ['state' => 'MD', 'city' => 'BENFIELD', 'latitude' => 39.08972, 'longitude' => -76.63, 'freq_mhz' => 5645],
            ['state' => 'MD', 'city' => 'CLINTON', 'latitude' => 38.75889, 'longitude' => -76.96194, 'freq_mhz' => 5615],
            ['state' => 'MI', 'city' => 'DETROIT', 'latitude' => 42.11111, 'longitude' => -83.515, 'freq_mhz' => 5615],
            ['state' => 'MN', 'city' => 'MINNEAPOLIS', 'latitude' => 44.87139, 'longitude' => -92.93278, 'freq_mhz' => 5610],
            ['state' => 'MO', 'city' => 'KANSAS CITY', 'latitude' => 39.49861, 'longitude' => -94.74194, 'freq_mhz' => 5605],
            ['state' => 'MO', 'city' => 'SAINT LOUIS', 'latitude' => 38.80556, 'longitude' => -90.48917, 'freq_mhz' => 5610],
            ['state' => 'MS', 'city' => 'DESOTO COUNTY', 'latitude' => 34.89583, 'longitude' => -89.9925, 'freq_mhz' => 5610],
            ['state' => 'NC', 'city' => 'CHARLOTTE', 'latitude' => 35.36083, 'longitude' => -80.885, 'freq_mhz' => 5608],
            ['state' => 'NC', 'city' => 'RALEIGH DURHAM', 'latitude' => 36.00194, 'longitude' => -78.69722, 'freq_mhz' => 5647],
            ['state' => 'NJ', 'city' => 'WOODBRIDGE', 'latitude' => 40.59361, 'longitude' => -74.27028, 'freq_mhz' => 5620],
            ['state' => 'NJ', 'city' => 'PENNSAUKEN', 'latitude' => 39.94917, 'longitude' => -75.07, 'freq_mhz' => 5610],
            ['state' => 'NV', 'city' => 'LAS VEGAS', 'latitude' => 36.14361, 'longitude' => -115.00722, 'freq_mhz' => 5645],
            ['state' => 'NY', 'city' => 'FLOYD BENNETT FIELD', 'latitude' => 40.58889, 'longitude' => -73.88028, 'freq_mhz' => 5647],
            ['state' => 'OH', 'city' => 'DAYTON', 'latitude' => 40.02194, 'longitude' => -84.12306, 'freq_mhz' => 5640],
            ['state' => 'OH', 'city' => 'CLEVELAND', 'latitude' => 41.28972, 'longitude' => -82.00778, 'freq_mhz' => 5645],
            ['state' => 'OH', 'city' => 'COLUMBUS', 'latitude' => 40.00556, 'longitude' => -82.71528, 'freq_mhz' => 5605],
            ['state' => 'OK', 'city' => 'AERO. CTR TDWR #1', 'latitude' => 35.40528, 'longitude' => -97.62528, 'freq_mhz' => 5610],
            ['state' => 'OK', 'city' => 'AERO. CTR TDWR #2', 'latitude' => 35.39278, 'longitude' => -97.62861, 'freq_mhz' => 5620],
            ['state' => 'OK', 'city' => 'TULSA', 'latitude' => 36.07056, 'longitude' => -95.82611, 'freq_mhz' => 5605],
            ['state' => 'OK', 'city' => 'OKLAHOMA CITY', 'latitude' => 35.27611, 'longitude' => -97.51, 'freq_mhz' => 5603],
            ['state' => 'PA', 'city' => 'HANOVER', 'latitude' => 40.50139, 'longitude' => -80.48611, 'freq_mhz' => 5615],
            ['state' => 'PR', 'city' => 'SAN JUAN', 'latitude' => 18.47389, 'longitude' => -66.17944, 'freq_mhz' => 5610],
            ['state' => 'TN', 'city' => 'NASHVILLE', 'latitude' => 35.97972, 'longitude' => -86.66167, 'freq_mhz' => 5605],
            ['state' => 'TX', 'city' => 'HOUSTON INTERCONTL', 'latitude' => 30.065, 'longitude' => -95.56694, 'freq_mhz' => 5605],
            ['state' => 'TX', 'city' => 'PEARLAND', 'latitude' => 29.51639, 'longitude' => -95.24167, 'freq_mhz' => 5645],
            ['state' => 'TX', 'city' => 'DALLAS LOVE FIELD', 'latitude' => 32.92583, 'longitude' => -96.96833, 'freq_mhz' => 5608],
            ['state' => 'TX', 'city' => 'LEWISVILLE DFW', 'latitude' => 33.06472, 'longitude' => -96.91806, 'freq_mhz' => 5640],
            ['state' => 'UT', 'city' => 'SALT LAKE CITY', 'latitude' => 40.96722, 'longitude' => -111.92972, 'freq_mhz' => 5610],
            ['state' => 'VA', 'city' => 'LEESBURG', 'latitude' => 39.08389, 'longitude' => -77.52944, 'freq_mhz' => 5605],
            ['state' => 'WI', 'city' => 'MILWAUKEE', 'latitude' => 42.81944, 'longitude' => -88.04639, 'freq_mhz' => 5603],
            ]
        ));
    }

    public function down(): void
    {
        Schema::dropIfExists('twdr_risk_events');
        Schema::dropIfExists('twdr_sites');
    }
};
