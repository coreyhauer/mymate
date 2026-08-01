<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Give each site_link its actual radio endpoints, so live device/interface health can be
 * joined onto the map's site-to-site backhaul lines (which otherwise carry geometry only -
 * see the create_site_links_table migration).
 *
 * device_a_id/device_b_id are best-effort - resolved from this ISP's `alpha2bravo` device
 * naming convention (see ResolveSiteLinkEndpoints), not from any authoritative link table -
 * so every row also gets an `endpoint_confidence` tier and an `endpoint_evidence` audit trail
 * instead of silently asserting an endpoint. Downstream alerting keyed off these columns
 * should treat anything below "reciprocal_name" as corroborating, not conclusive.
 *
 * interface_a_id/interface_b_id are nullable independently of the device columns: a device can
 * be resolved with confidence while its wireless interface can't (missing/ambiguous interface
 * discovery), and that's a strictly weaker claim than not knowing the device at all.
 *
 * All four FKs nullOnDelete rather than cascade: deleting a device/interface elsewhere in the
 * app must not delete backhaul geometry, only un-link it (leaving honest NULLs for a later
 * re-resolve, same principle as the confidence tiers themselves).
 *
 * Safety for the existing importer: ImportSiteLinks (upsert-on-external_ref, never deletes)
 * only ever assigns site_a_id/site_b_id/media_type on the model before save() - it never
 * touches these columns, so they're never in the dirty attribute set and a re-import cannot
 * blank out previously-resolved endpoints. See ResolveSiteLinkEndpointsTest for the regression
 * test that pins this down.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_links', function (Blueprint $table) {
            $table->foreignId('device_a_id')->nullable()->after('site_a_id')
                ->constrained('devices')->nullOnDelete();
            $table->foreignId('interface_a_id')->nullable()->after('device_a_id')
                ->constrained('interfaces')->nullOnDelete();

            $table->foreignId('device_b_id')->nullable()->after('site_b_id')
                ->constrained('devices')->nullOnDelete();
            $table->foreignId('interface_b_id')->nullable()->after('device_b_id')
                ->constrained('interfaces')->nullOnDelete();

            // reciprocal_name | reciprocal_name_fuzzy | name_subnet29 | name_subnet29_fuzzy |
            // name_only | name_only_fuzzy | unresolved (see ResolveSiteLinkEndpoints)
            $table->string('endpoint_confidence', 24)->nullable()->after('external_ref');
            // Short human summary of how the pairing was derived, e.g. which devices/tokens matched.
            $table->string('endpoint_method', 255)->nullable()->after('endpoint_confidence');
            // Structured evidence (matched device names/tokens/roles, distance, /29 overlap,
            // ambiguous alternates considered) - the audit trail behind endpoint_confidence.
            $table->json('endpoint_evidence')->nullable()->after('endpoint_method');
            // Set whenever a resolution pass has touched this row, even when it resolved
            // nothing - distinguishes "tried, found nothing" from "never processed".
            $table->timestamp('endpoints_resolved_at')->nullable()->after('endpoint_evidence');

            $table->index('endpoint_confidence');
        });
    }

    public function down(): void
    {
        Schema::table('site_links', function (Blueprint $table) {
            $table->dropConstrainedForeignId('device_a_id');
            $table->dropConstrainedForeignId('interface_a_id');
            $table->dropConstrainedForeignId('device_b_id');
            $table->dropConstrainedForeignId('interface_b_id');
            $table->dropColumn(['endpoint_confidence', 'endpoint_method', 'endpoint_evidence', 'endpoints_resolved_at']);
        });
    }
};
