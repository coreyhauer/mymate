<?php

namespace Tests\Feature;

use App\Actions\Sites\ImportSiteLinks;
use App\Actions\Sites\ResolveSiteLinkEndpoints;
use App\Models\Device;
use App\Models\NetworkInterface;
use App\Models\Site;
use App\Models\SiteLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResolveSiteLinkEndpointsTest extends TestCase
{
    use RefreshDatabase;

    private function link(Site $a, Site $b): SiteLink
    {
        return SiteLink::create([
            'site_a_id' => $a->id, 'site_b_id' => $b->id, 'media_type' => 'wireless',
            'external_ref' => 'pair:'.min($a->id, $b->id).'-'.max($a->id, $b->id),
        ]);
    }

    public function test_reciprocal_name_match_is_highest_confidence(): void
    {
        $kerkhoff = Site::factory()->at(44.0, -93.0)->create(['name' => 'Keith Kerkhoff']);
        $salfer = Site::factory()->at(44.02, -93.02)->create(['name' => 'Salfer']);
        $ap = Device::factory()->create(['name' => 'kerkhoff2salfer AF5xHD UD24 5700 AP', 'site_id' => $kerkhoff->id]);
        $st = Device::factory()->create(['name' => 'salfer2kerkhoff ST Tik 5180', 'site_id' => $salfer->id]);
        $link = $this->link($kerkhoff, $salfer);

        app(ResolveSiteLinkEndpoints::class)();

        $link->refresh();
        $this->assertSame($ap->id, $link->device_a_id);
        $this->assertSame($st->id, $link->device_b_id);
        $this->assertSame('reciprocal_name', $link->endpoint_confidence);
        $this->assertNotNull($link->endpoints_resolved_at);
        $this->assertSame('AP', $link->endpoint_evidence['a']['role']);
        $this->assertSame('ST', $link->endpoint_evidence['b']['role']);
    }

    public function test_single_sided_match_without_sibling_is_name_only(): void
    {
        $a = Site::factory()->at(44.0, -93.0)->create(['name' => 'Alpha Tower']);
        $b = Site::factory()->at(44.02, -93.02)->create(['name' => 'Bravo Tower']);
        $dev = Device::factory()->create(['name' => 'alpha2bravo AF5xHD 5700', 'site_id' => $a->id, 'mgmt_ip' => '10.1.2.10']);
        $link = $this->link($a, $b);

        app(ResolveSiteLinkEndpoints::class)();

        $link->refresh();
        $this->assertSame($dev->id, $link->device_a_id);
        $this->assertNull($link->device_b_id);
        $this->assertSame('name_only', $link->endpoint_confidence);
    }

    public function test_single_sided_match_with_subnet29_sibling_is_upgraded(): void
    {
        $a = Site::factory()->at(44.0, -93.0)->create(['name' => 'Alpha Tower']);
        $b = Site::factory()->at(44.02, -93.02)->create(['name' => 'Bravo Tower']);
        $dev = Device::factory()->create(['name' => 'alpha2bravo AF5xHD 5700', 'site_id' => $a->id, 'mgmt_ip' => '10.1.2.10']);
        // Sibling device at the same site, same /29 (10.1.2.8/29 covers .8-.15) -> corroborates.
        Device::factory()->create(['name' => 'Alpha Tower switch', 'site_id' => $a->id, 'mgmt_ip' => '10.1.2.12']);
        $link = $this->link($a, $b);

        app(ResolveSiteLinkEndpoints::class)();

        $link->refresh();
        $this->assertSame($dev->id, $link->device_a_id);
        $this->assertSame('name_subnet29', $link->endpoint_confidence);
        $this->assertTrue($link->endpoint_evidence !== []);
    }

    public function test_ambiguous_multiple_candidates_on_one_side_resolves_to_null_not_a_guess(): void
    {
        $a = Site::factory()->at(44.0, -93.0)->create(['name' => 'Alpha Tower']);
        $b = Site::factory()->at(44.02, -93.02)->create(['name' => 'Bravo Tower']);
        // Two devices at A both name-match toward B, neither carrying an AP/ST tag to disambiguate.
        Device::factory()->create(['name' => 'alpha2bravo Tik 5700', 'site_id' => $a->id]);
        Device::factory()->create(['name' => 'alpha2bravo Rocket 5800', 'site_id' => $a->id]);
        $link = $this->link($a, $b);

        app(ResolveSiteLinkEndpoints::class)();

        $link->refresh();
        $this->assertNull($link->device_a_id);
        $this->assertSame('unresolved', $link->endpoint_confidence);
        $this->assertCount(2, $link->endpoint_evidence['a']['ambiguous_alternates']);
    }

    public function test_ap_switch_and_pppoe_named_devices_are_never_treated_as_radios(): void
    {
        $a = Site::factory()->at(44.0, -93.0)->create(['name' => 'Alpha Tower']);
        $b = Site::factory()->at(44.02, -93.02)->create(['name' => 'Bravo Tower']);
        Device::factory()->create(['name' => 'alpha2bravo AP Switch', 'site_id' => $a->id]);
        Device::factory()->create(['name' => 'alpha2bravo pppoe', 'site_id' => $a->id]);
        $link = $this->link($a, $b);

        app(ResolveSiteLinkEndpoints::class)();

        $link->refresh();
        $this->assertNull($link->device_a_id);
        $this->assertSame('unresolved', $link->endpoint_confidence);
    }

    public function test_sixty_ghz_gear_is_rejected_when_sites_are_more_than_two_miles_apart(): void
    {
        // ~9 miles apart - too far for 60GHz, even though the name matches perfectly.
        $a = Site::factory()->at(44.00, -93.00)->create(['name' => 'Alpha Tower']);
        $b = Site::factory()->at(44.13, -93.00)->create(['name' => 'Bravo Tower']);
        Device::factory()->create(['name' => 'alpha2bravo AP Wave60LR 68040 5200', 'site_id' => $a->id]);
        $link = $this->link($a, $b);

        app(ResolveSiteLinkEndpoints::class)();

        $link->refresh();
        $this->assertNull($link->device_a_id);
        $this->assertSame('unresolved', $link->endpoint_confidence);
    }

    public function test_sixty_ghz_gear_is_accepted_within_two_miles(): void
    {
        $a = Site::factory()->at(44.000, -93.000)->create(['name' => 'Alpha Tower']);
        $b = Site::factory()->at(44.010, -93.000)->create(['name' => 'Bravo Tower']); // ~0.69 mi
        $dev = Device::factory()->create(['name' => 'alpha2bravo AP Wave60LR 68040 5200', 'site_id' => $a->id]);
        $link = $this->link($a, $b);

        app(ResolveSiteLinkEndpoints::class)();

        $link->refresh();
        $this->assertSame($dev->id, $link->device_a_id);
    }

    public function test_interface_resolution_prefers_known_radio_interface_names(): void
    {
        $a = Site::factory()->at(44.0, -93.0)->create(['name' => 'Alpha Tower']);
        $b = Site::factory()->at(44.02, -93.02)->create(['name' => 'Bravo Tower']);
        $dev = Device::factory()->create(['name' => 'alpha2bravo AP Wave60 68040', 'site_id' => $a->id]);
        NetworkInterface::factory()->create(['device_id' => $dev->id, 'name' => 'eth0']);
        $ath0 = NetworkInterface::factory()->create(['device_id' => $dev->id, 'name' => 'ath0']);
        NetworkInterface::factory()->create(['device_id' => $dev->id, 'name' => 'br0']);
        $link = $this->link($a, $b);

        app(ResolveSiteLinkEndpoints::class)();

        $link->refresh();
        $this->assertSame($ath0->id, $link->interface_a_id);
    }

    public function test_device_with_no_matching_radio_interface_leaves_interface_null_but_keeps_device(): void
    {
        $a = Site::factory()->at(44.0, -93.0)->create(['name' => 'Alpha Tower']);
        $b = Site::factory()->at(44.02, -93.02)->create(['name' => 'Bravo Tower']);
        $dev = Device::factory()->create(['name' => 'alpha2bravo AP 5700', 'site_id' => $a->id]);
        NetworkInterface::factory()->create(['device_id' => $dev->id, 'name' => 'ether1']);
        $link = $this->link($a, $b);

        app(ResolveSiteLinkEndpoints::class)();

        $link->refresh();
        $this->assertSame($dev->id, $link->device_a_id);
        $this->assertNull($link->interface_a_id);
    }

    public function test_a_device_named_for_the_wrong_end_is_not_used_as_evidence(): void
    {
        // Device physically assigned to site A but named as if it lives at B - naming and
        // placement disagree, so it must never be treated as A-side evidence.
        $a = Site::factory()->at(44.0, -93.0)->create(['name' => 'Alpha Tower']);
        $b = Site::factory()->at(44.02, -93.02)->create(['name' => 'Bravo Tower']);
        Device::factory()->create(['name' => 'bravo2alpha ST 5700', 'site_id' => $a->id]);
        $link = $this->link($a, $b);

        app(ResolveSiteLinkEndpoints::class)();

        $link->refresh();
        $this->assertNull($link->device_a_id);
        $this->assertSame('unresolved', $link->endpoint_confidence);
    }

    public function test_dry_run_computes_but_does_not_write(): void
    {
        $a = Site::factory()->at(44.0, -93.0)->create(['name' => 'Alpha Tower']);
        $b = Site::factory()->at(44.02, -93.02)->create(['name' => 'Bravo Tower']);
        Device::factory()->create(['name' => 'alpha2bravo AP 5700', 'site_id' => $a->id]);
        $link = $this->link($a, $b);

        $summary = app(ResolveSiteLinkEndpoints::class)(null, true);

        $this->assertSame(1, $summary['name_only']);
        $link->refresh();
        $this->assertNull($link->device_a_id);
        $this->assertNull($link->endpoints_resolved_at);
    }

    public function test_reimporting_site_links_never_wipes_previously_resolved_endpoints(): void
    {
        $a = Site::factory()->at(44.0, -93.0)->create(['name' => 'Alpha Tower', 'external_ref' => 'uisp:a']);
        $b = Site::factory()->at(44.02, -93.02)->create(['name' => 'Bravo Tower', 'external_ref' => 'uisp:b']);
        $dev = Device::factory()->create(['name' => 'alpha2bravo AP 5700', 'site_id' => $a->id]);
        $this->link($a, $b);

        app(ResolveSiteLinkEndpoints::class)();
        $resolved = SiteLink::first();
        $this->assertSame($dev->id, $resolved->device_a_id);
        $confidenceBeforeReimport = $resolved->endpoint_confidence;

        // Re-run the ordinary CSV importer, as ops would on a routine re-sync.
        $csvPath = tempnam(sys_get_temp_dir(), 'mmcsv').'.csv';
        file_put_contents($csvPath, "site_a_ref,site_b_ref,media_type\nuisp:a,uisp:b,wireless\n");
        app(ImportSiteLinks::class)($csvPath);

        $resolved->refresh();
        $this->assertSame($dev->id, $resolved->device_a_id, 'ImportSiteLinks must never null out a resolved endpoint');
        $this->assertSame($confidenceBeforeReimport, $resolved->endpoint_confidence);
    }

    /**
     * Generalises site_links 4573 (`wangen2heinrichST`, hand-fixed) - a glued role suffix
     * with no separator before it, so the plain regex reads the far-end token as
     * "heinrichst", which matches no site. Also proves the reciprocal side (an exact match)
     * combined with a suffix-stripped side downgrades the tier to reciprocal_name_fuzzy
     * rather than the top reciprocal_name tier - the "confirm fuzzy/suffix gets a distinct,
     * lower tier" requirement.
     */
    public function test_glued_role_suffix_is_stripped_and_matched_at_a_lower_tier(): void
    {
        $wangen = Site::factory()->at(43.6631, -93.2394)->create(['name' => 'Wangen']);
        $heinrich = Site::factory()->at(43.6600, -93.2007)->create(['name' => 'Todd Heinrich']);
        // No word boundary before "ST" - the plain PAIR_PATTERN reads t2 as "heinrichst".
        $wangenDevice = Device::factory()->create(['name' => 'wangen2heinrichST Wave Nano 69120 / 5740', 'site_id' => $wangen->id]);
        $heinrichDevice = Device::factory()->create(['name' => 'heinrich2wangen AP Wave Nano 69120 / 5740', 'site_id' => $heinrich->id]);
        $link = $this->link($wangen, $heinrich);

        app(ResolveSiteLinkEndpoints::class)();

        $link->refresh();
        $this->assertSame($wangenDevice->id, $link->device_a_id);
        $this->assertSame($heinrichDevice->id, $link->device_b_id);
        $this->assertSame('reciprocal_name_fuzzy', $link->endpoint_confidence, 'a suffix-stripped side must downgrade the tier below reciprocal_name');
        $this->assertSame('role_suffix', $link->endpoint_evidence['a']['match_method']['other']);
        $this->assertTrue($link->endpoint_evidence['a']['weak_match']);
        $this->assertFalse($link->endpoint_evidence['b']['weak_match'], 'the exact reciprocal side must not itself be marked weak');
        // The suffix strip should also recover the role tag the glued "ST" represents.
        $this->assertSame('ST', $link->endpoint_evidence['a']['role']);
    }

    /** Separator variance: `alpha 2 bravo` (spaces) must resolve, and purely via exact matching - spacing alone is not weak evidence. */
    public function test_spaced_separator_is_accepted_at_full_confidence(): void
    {
        $sutters = Site::factory()->at(44.0, -93.0)->create(['name' => 'Sutters']);
        $kline = Site::factory()->at(44.02, -93.02)->create(['name' => 'Kline']);
        $dev = Device::factory()->create(['name' => 'Sutters 2 Kline AP AF5xHD 5700', 'site_id' => $sutters->id]);
        $link = $this->link($sutters, $kline);

        app(ResolveSiteLinkEndpoints::class)();

        $link->refresh();
        $this->assertSame($dev->id, $link->device_a_id);
        $this->assertSame('name_only', $link->endpoint_confidence, 'spacing alone must not downgrade to a fuzzy tier');
        $this->assertFalse($link->endpoint_evidence['a']['weak_match']);
    }

    /** Digits embedded in a token (`anderson14`) must survive the spaced-separator parse. */
    public function test_spaced_separator_token_with_embedded_digits(): void
    {
        $anderson = Site::factory()->at(44.0, -93.0)->create(['name' => 'Anderson14']);
        $terpstra = Site::factory()->at(44.02, -93.02)->create(['name' => 'Terpstra']);
        $dev = Device::factory()->create(['name' => 'anderson14 2 terpstra AP Wave60LR 65880', 'site_id' => $anderson->id]);
        $link = $this->link($anderson, $terpstra);

        app(ResolveSiteLinkEndpoints::class)();

        $link->refresh();
        $this->assertSame($dev->id, $link->device_a_id);
        $this->assertSame('name_only', $link->endpoint_confidence);
    }

    /**
     * Generalises site_links 5661 (`ceck2strouf`, hand-fixed) - a typo in the self token
     * ("ceck" for "cech"). Exact substring matching can never bridge a typo; bounded fuzzy
     * matching (edit distance 1, unique across the whole fleet) does.
     */
    public function test_fuzzy_matching_bridges_a_real_typo_at_a_lower_tier(): void
    {
        $cech = Site::factory()->at(43.58, -93.17)->create(['name' => 'David Cech']);
        $strouf = Site::factory()->at(43.63, -93.14)->create(['name' => 'Strouf']);
        // "ceck" instead of "cech" - the real fleet typo behind site_link 5661.
        $dev = Device::factory()->create(['name' => 'ceck2strouf Wave LR AP 68040 | 5800', 'site_id' => $cech->id]);
        $link = $this->link($cech, $strouf);

        app(ResolveSiteLinkEndpoints::class)();

        $link->refresh();
        $this->assertSame($dev->id, $link->device_a_id);
        $this->assertSame('name_only_fuzzy', $link->endpoint_confidence);
        $this->assertStringStartsWith('fuzzy', $link->endpoint_evidence['a']['match_method']['self']);
        $this->assertTrue($link->endpoint_evidence['a']['weak_match']);
    }

    /**
     * Hard safeguard: when a token is within edit distance of TWO different sites' name-words
     * at the same distance, that is genuine ambiguity, not a coin flip - fuzzy matching must
     * refuse rather than guess, exactly like the existing multi-candidate ambiguity rule.
     */
    public function test_fuzzy_matching_refuses_a_tie_between_two_equally_close_sites(): void
    {
        $camp = Site::factory()->at(44.0, -93.0)->create(['name' => 'Camp']);
        $boxer = Site::factory()->at(44.02, -93.02)->create(['name' => 'Boxer']);
        // Decoy site elsewhere in the fleet, equally (edit distance 1) close to the far token "coxer".
        Site::factory()->at(41.0, -90.0)->create(['name' => 'Foxer']);
        Device::factory()->create(['name' => 'camp2coxer AF5xHD 5700', 'site_id' => $camp->id]);
        $link = $this->link($camp, $boxer);

        app(ResolveSiteLinkEndpoints::class)();

        $link->refresh();
        $this->assertNull($link->device_a_id);
        $this->assertSame('unresolved', $link->endpoint_confidence);
    }

    /** Role-suffix stripping must never be attempted on the SELF token - real surnames end in "st"/"ap" (Durst, Nuest, Armbrust...). */
    public function test_role_suffix_stripping_never_applies_to_the_self_token(): void
    {
        $durst = Site::factory()->at(44.0, -93.0)->create(['name' => 'Durst']);
        $sween = Site::factory()->at(44.02, -93.02)->create(['name' => 'Sween']);
        $dev = Device::factory()->create(['name' => 'durst2sween AF5xHD 5700', 'site_id' => $durst->id]);
        $link = $this->link($durst, $sween);

        app(ResolveSiteLinkEndpoints::class)();

        $link->refresh();
        $this->assertSame($dev->id, $link->device_a_id, 'Durst is a real surname ending in "st" and must match exactly, unstripped');
        $this->assertSame('name_only', $link->endpoint_confidence);
        $this->assertSame('exact', $link->endpoint_evidence['a']['match_method']['self']);
    }

    /** "WT" (Water Tower) is a real site-name qualifier in this fleet, not a role tag - must never be stripped. */
    public function test_wt_site_qualifier_is_not_treated_as_a_role_suffix(): void
    {
        $milaca = Site::factory()->at(44.0, -93.0)->create(['name' => 'Milaca']);
        $pease = Site::factory()->at(44.02, -93.02)->create(['name' => 'Pease Water Tower']);
        Device::factory()->create(['name' => 'Milaca2PeaseWT AP wave60LR 68040 / 5800', 'site_id' => $milaca->id]);
        $link = $this->link($milaca, $pease);

        app(ResolveSiteLinkEndpoints::class)();

        $link->refresh();
        // "peasewt" doesn't match "Pease Water Tower" (WT isn't stripped, and isn't an
        // abbreviation this resolver expands) - correctly left unresolved rather than guessed.
        $this->assertNull($link->device_a_id);
        $this->assertSame('unresolved', $link->endpoint_confidence);
    }

    public function test_command_backs_up_before_writing_and_reports_tiers(): void
    {
        $a = Site::factory()->at(44.0, -93.0)->create(['name' => 'Alpha Tower']);
        $b = Site::factory()->at(44.02, -93.02)->create(['name' => 'Bravo Tower']);
        Device::factory()->create(['name' => 'alpha2bravo AP 5700', 'site_id' => $a->id]);
        $this->link($a, $b);

        $dir = sys_get_temp_dir().'/mymate_test_backup_'.uniqid();

        $this->artisan('mymate:site-links:resolve-endpoints', ['--backup-dir' => $dir])
            ->assertExitCode(0);

        $files = glob($dir.'/site_links_backup_*.csv');
        $this->assertNotEmpty($files, 'command should have written a backup CSV');
        $lines = file($files[0]);
        $this->assertGreaterThanOrEqual(2, count($lines)); // header + at least the one row

        SiteLink::first()->refresh();
        $this->assertSame('name_only', SiteLink::first()->endpoint_confidence);
    }
}
