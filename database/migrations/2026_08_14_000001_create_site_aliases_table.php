<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The OTHER names a site is known by, so device naming can be matched against sites recorded
 * under a different vocabulary.
 *
 * The `alpha2bravo` matcher compares a device's tokens against SITE NAMES. That silently fails
 * whenever a tower is recorded by street address but called something else in the field. Austin is
 * the case that exposed it: the site is `403 2nd St NW`, while every radio on it says "Austin" -
 * `AustinFiber2 310MainAP`, `AustinSE Wave 60 AP`, `Twin towers 2 Austin60E ST`. No amount of
 * fuzzy matching bridges "austinfiber" to "403 2nd St NW", so the links to 310 Main St S and Twin
 * Towers were simply never created and both sites looked backhaul-less on the map.
 *
 * Neither side is wrong - the devices are correctly named and the site is correctly addressed.
 * They just use different words, and that is what an alias is for.
 *
 * Aliases are matched exactly like a site's own name words, so adding one immediately lets both
 * DeriveSiteLinksFromNaming (does a link EXIST) and ResolveSiteLinkEndpoints (WHICH radios carry
 * it) see the connection.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_aliases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            // normalised (lowercase, alphanumeric only) - matched the same way site name words are
            $table->string('alias', 64);
            // 'manual' = an operator said so; 'derived' = suggested by the unmatched-token sweep
            $table->string('source', 12)->default('manual');
            $table->timestamps();

            $table->unique(['site_id', 'alias']);
            $table->index('alias');
        });

        // The one that exposed the gap. Seeded here so the fix is not dependent on someone
        // remembering to re-add it.
        $austin = DB::table('sites')->where('name', '403 2nd St NW')->value('id');
        if ($austin) {
            DB::table('site_aliases')->insert([
                'site_id' => $austin, 'alias' => 'austin', 'source' => 'manual',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('site_aliases');
    }
};
