<?php

namespace App\Actions\Sites;

use App\Models\Site;
use App\Models\SiteLink;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Backfill site_links.device_a_id/device_b_id (and, where determinable, the wireless
 * interface on each end) from this ISP's device naming convention, so live device/interface
 * health can eventually be joined onto the geo map's backhaul lines.
 *
 * THE CONVENTION (see CLAUDE.md): a device named `alpha2bravo` physically lives at site
 * `alpha` and radios toward site `bravo`. Role suffixes `AP`/`ST` mark the two ends of a
 * point-to-point pair. `24o`/`5o`/`5n`/`5e`/`5s`/`5w` mark omni/sector APs, not backhaul
 * ends - the `<letters>2<letters>` pattern already excludes these, since the frequency
 * suffix always has a digit immediately after the `2` (`kulseth24o` has no `2<letters>` to
 * match), never a second word.
 *
 * MATCHING: for a site_link between site A and site B, a device only counts as a candidate
 * for the A-side if it (a) is already assigned to site A in mymate, and (b) its own
 * `alpha2bravo` self-token matches site A's name while the other token matches site B's -
 * i.e. naming and current placement agree. A device already at A but named `B2A...` (it
 * claims to live at B) is a contradiction, not evidence, and is never used - that is a device
 * mis-placement question, out of this action's guardrails (it never touches `devices`).
 *
 * NAMING VARIANCE handled beyond the plain glued `alpha2bravo` form (see the two hand-fixed
 * rows this generalises: site_links 4573 `wangen2heinrichST`, 5661 `ceck2strouf`):
 *
 *  - SEPARATOR VARIANCE: `alpha 2 bravo` (spaces around the `2`) is accepted alongside the
 *    glued form. Tokens may contain digits (`anderson14`), as long as they start with a
 *    letter (keeps this from matching frequency listings like "5700 2 5800", which are
 *    digit-only on both sides of a literal "2").
 *
 *  - ROLE-SUFFIX STRIPPING: a trailing `AP`/`ST` (optionally version-tailed - `APv5`, `STv3`)
 *    glued onto the OTHER-site token (`heinrichST`) is stripped before matching, once the
 *    unstripped token has already failed to match. This is deliberately ONE-SIDED - never
 *    applied to the SELF token - because plenty of real surnames in this fleet end in "st"
 *    or "ap" (Durst, Nuest, Armbrust, Rosenquist, Forst, Outpost...); stripping there would
 *    silently mangle a correct name into a wrong, shorter one. It is also deliberately never
 *    applied when the untouched token would already match (e.g. `torellST` against site
 *    "Torell" already matches via plain prefix containment - no stripping needed) - stripping
 *    only ever fires as a fallback after the exact check has already failed. Separately,
 *    trailing site-name qualifiers such as "WT" (Water Tower - `Milaca2PeaseWT` against site
 *    "Pease Water Tower") are deliberately NOT in the stripped vocabulary: unlike AP/ST/v\d,
 *    "WT" is itself part of dozens of real site names in this fleet, not a role tag, and
 *    stripping it would risk manufacturing false matches. The vocabulary here is drawn only
 *    from what a fleet-wide device-name audit actually showed as role tags (see
 *    ResolveSiteLinkEndpointsTest and the delivery notes for the audit).
 *
 *  - BOUNDED FUZZY MATCHING: when a token still doesn't match (self OR other) after the
 *    above, it is checked against every site's individual name-words (>=4 chars each, e.g.
 *    "Cech" out of "David Cech") at edit distance <=1 - catching real fleet typos like
 *    ceck/cech, Kanderson/kanserson, Meservy/meservey. This is intentionally narrow: the
 *    token must itself be >=4 chars, the match must be the SOLE site achieving that distance
 *    across the entire fleet (a tie against a second site is treated as no match, not a
 *    guess), and the winning site must be the specific site this call is testing against (a
 *    token that fuzzy-matches some other, unrelated site more/equally well is not forced onto
 *    this one). A wrong endpoint drives a false RF alert later, so this stays conservative by
 *    design - see CONFIDENCE below for how these weaker matches are marked for downstream
 *    consumers.
 *
 * CANDIDATE PRIORITY, when a site has more than one device that parses into a matching pair
 * (common in this fleet - old/replaced radios are often left in place rather than deleted):
 * a glued-form exact match, if one exists, wins outright over every other candidate at that
 * site, full stop - never blended into a tie, never out-voted by role tie-breaking. Only when
 * no glued-exact candidate exists does a spaced-form exact match get a turn, and only when
 * neither exists does a weak (role-suffix/fuzzy) match get considered at all. This guarantees
 * every link the original, glued-only algorithm already resolved keeps resolving to exactly
 * the same device - the production diff behind this change surfaced several links where a
 * second device, only visible once spaces were accepted, would otherwise have out-voted (via
 * an incidental role tag) or tied against (via genuine new ambiguity) an already-correct
 * glued match. Newly-discovered spaced-only or weak-only sites are unaffected by this and
 * still gain coverage.
 *
 * CONFIDENCE (highest to lowest, see CLAUDE.md's ranking):
 *  - reciprocal_name       - both sides resolved and cross-confirm each other, both via exact
 *                             (non-fuzzy, non-suffix-stripped) matching
 *  - reciprocal_name_fuzzy - both sides resolved and cross-confirm each other, but at least
 *                             one side only matched via role-suffix stripping or fuzzy edit
 *                             distance - weaker corroboration, worth a distinct lower tier
 *  - name_subnet29         - one side resolved via exact matching, and that device shares a
 *                             /29 with another device already at the same site
 *  - name_subnet29_fuzzy   - as above, but the resolved side used suffix-stripping/fuzzy
 *  - name_only             - one side resolved via exact matching, no /29 corroboration
 *  - name_only_fuzzy       - as above, but the resolved side used suffix-stripping/fuzzy
 *  - unresolved            - no naming evidence on either side; left NULL, not guessed
 *
 * Downstream alerting that only wants strong evidence should filter to the non-`_fuzzy` tiers.
 *
 * An ambiguous side (2+ equally-plausible candidates after role tie-breaking) is also left
 * NULL rather than guessed, and the alternates are recorded in endpoint_evidence.
 *
 * A device whose name/model reads as 60GHz gear (Wave-60, af60, GigaBeam - short range,
 * under ~2 miles) is rejected as a candidate when the two sites are more than 2 miles apart:
 * the naming matched, but physics didn't, so it's treated as no signal rather than resolved.
 */
class ResolveSiteLinkEndpoints
{
    /** @var string[] Ordered priority for "which interface is the radio" across vendors seen in the fleet. */
    private const INTERFACE_PRIORITY = ['radio1', 'radio0', 'ath0', 'air0', 'wlan0', 'wlan1', 'wifi0'];

    /** Device names containing any of these are switches/monitors/PPPoE concentrators, never a radio end. */
    private const BANNED_NAME_SUBSTRINGS = ['sector switch', 'ap switch', 'battery monitor', 'pppoe'];

    private const SIXTY_GHZ_MARKERS = ['wave60', 'wave 60', 'gigabeam', 'af60', '60ghz', '60 ghz'];

    /** Glued form: `alpha2bravo` - both sides pure letters, no separator. The original, strictest pattern. */
    private const PAIR_PATTERN = '/([a-z]{3,})2([a-z]{3,})/i';

    /**
     * Spaced form: `alpha 2 bravo`. Tokens must START with a letter (so purely-numeric
     * frequency listings like "5700 2 5800" never match) but may contain digits after that
     * (`anderson14`). The literal "2" must be its own whitespace-delimited word, which is what
     * keeps this from matching things like "AF5xHD 2ft 5490" (no space after the "2").
     */
    private const SPACED_PAIR_PATTERN = '/\b([a-z][a-z0-9]{2,})\s+2\s+([a-z][a-z0-9]{2,})\b/i';

    /**
     * A trailing AP/ST role tag (optionally version-tailed: APv5, STv3) glued onto the END of
     * a token, e.g. "heinrichST" -> prefix "heinrich". The prefix must be >=4 chars so this
     * can never fire down to a near-empty residual.
     */
    private const ROLE_SUFFIX_PATTERN = '/^([a-z0-9]{4,})(?:ap|st)(?:v?\d{1,2})?$/i';

    /** A token shorter than this is never attempted against the fuzzy matcher - too easy to collide. */
    private const FUZZY_MIN_TOKEN_LEN = 4;

    /** A site name-word shorter than this is never indexed for fuzzy matching, for the same reason. */
    private const FUZZY_MIN_SITE_WORD_LEN = 4;

    /** Maximum Levenshtein distance accepted - deliberately tight; see class docblock. */
    private const FUZZY_MAX_DISTANCE = 1;

    /**
     * @return array{
     *     processed: int, reciprocal_name: int, reciprocal_name_fuzzy: int,
     *     name_subnet29: int, name_subnet29_fuzzy: int, name_only: int, name_only_fuzzy: int,
     *     unresolved: int, both_endpoints: int, one_endpoint: int, neither_endpoint: int,
     *     interfaces_resolved: int,
     * }
     */
    public function __invoke(?int $limit = null, bool $dryRun = false): array
    {
        $sites = Site::query()->get(['id', 'name', 'latitude', 'longitude'])
            ->map(fn (Site $s): array => [
                'id' => $s->id,
                'nname' => self::normalize($s->name),
                'words' => self::siteWords($s->name),
                'lat' => $s->latitude,
                'lon' => $s->longitude,
            ])
            ->keyBy('id');

        $fuzzyIndex = self::buildFuzzyIndex($sites);

        $devicesBySite = DB::table('devices')
            ->select(['id', 'name', 'mgmt_ip', 'site_id', 'model'])
            ->whereNotNull('site_id')
            ->get()
            ->groupBy('site_id');

        $interfacesByDevice = DB::table('interfaces')
            ->select(['id', 'device_id', 'name'])
            ->get()
            ->groupBy('device_id');

        $summary = [
            'processed' => 0,
            'reciprocal_name' => 0, 'reciprocal_name_fuzzy' => 0,
            'name_subnet29' => 0, 'name_subnet29_fuzzy' => 0,
            'name_only' => 0, 'name_only_fuzzy' => 0,
            'unresolved' => 0,
            'both_endpoints' => 0, 'one_endpoint' => 0, 'neither_endpoint' => 0, 'interfaces_resolved' => 0,
        ];

        // 2,908 rows total - a plain cursor is simpler and plenty fast; no need for chunkById's
        // id-window pagination (which would also fight with an explicit --limit).
        $query = SiteLink::query()->orderBy('id');
        if ($limit !== null) {
            $query->limit($limit);
        }

        foreach ($query->cursor() as $link) {
            $result = $this->resolveOne($link, $sites, $devicesBySite, $interfacesByDevice, $fuzzyIndex);
            $summary['processed']++;
            $summary[$result['endpoint_confidence']]++;

            $hasA = $result['device_a_id'] !== null;
            $hasB = $result['device_b_id'] !== null;
            $summary[$hasA && $hasB ? 'both_endpoints' : ($hasA || $hasB ? 'one_endpoint' : 'neither_endpoint')]++;
            $summary['interfaces_resolved'] += ($result['interface_a_id'] !== null ? 1 : 0) + ($result['interface_b_id'] !== null ? 1 : 0);

            if (! $dryRun) {
                $link->forceFill([
                    ...$result,
                    'endpoints_resolved_at' => now(),
                ])->save();
            }
        }

        return $summary;
    }

    /**
     * Pure resolution for a single row - no DB writes. Exposed separately so tests can assert
     * against it directly without a full table scan.
     *
     * @param  Collection<int, array{id:int,nname:string,words:string[],lat:?float,lon:?float}>  $sites
     * @param  Collection<int, Collection<int, object>>  $devicesBySite
     * @param  Collection<int, Collection<int, object>>  $interfacesByDevice
     * @param  array<int, array{word:string,site_id:int}[]>  $fuzzyIndex  Keyed by word length.
     * @return array{
     *     device_a_id: ?int, interface_a_id: ?int, device_b_id: ?int, interface_b_id: ?int,
     *     endpoint_confidence: string, endpoint_method: string, endpoint_evidence: array<string,mixed>,
     * }
     */
    public function resolveOne(SiteLink $link, Collection $sites, Collection $devicesBySite, Collection $interfacesByDevice, array $fuzzyIndex = []): array
    {
        $a = $sites->get($link->site_a_id);
        $b = $sites->get($link->site_b_id);

        if ($a === null || $b === null) {
            return $this->unresolved('site A or site B no longer exists');
        }

        $distanceMi = ($a['lat'] !== null && $a['lon'] !== null && $b['lat'] !== null && $b['lon'] !== null)
            ? self::haversineMiles((float) $a['lat'], (float) $a['lon'], (float) $b['lat'], (float) $b['lon'])
            : null;

        $sideA = $this->pickCandidate($devicesBySite->get($a['id'], collect()), $a['nname'], $b['nname'], $a['id'], $b['id'], 'AP', $distanceMi, $fuzzyIndex);
        $sideB = $this->pickCandidate($devicesBySite->get($b['id'], collect()), $b['nname'], $a['nname'], $b['id'], $a['id'], 'ST', $distanceMi, $fuzzyIndex);

        $deviceA = $sideA['candidate'];
        $deviceB = $sideB['candidate'];

        if ($deviceA !== null && $deviceB !== null) {
            $weak = $sideA['weak'] || $sideB['weak'];
            $confidence = $weak ? 'reciprocal_name_fuzzy' : 'reciprocal_name';
            $method = sprintf("reciprocal%s: '%s' (site A, role %s) <-> '%s' (site B, role %s)",
                $weak ? ' [weak: suffix/fuzzy match on one or both sides]' : '',
                $deviceA->name, $sideA['role'] ?? '-', $deviceB->name, $sideB['role'] ?? '-');
        } elseif ($deviceA !== null || $deviceB !== null) {
            $only = $deviceA !== null ? $sideA : $sideB;
            $ownSiteId = $deviceA !== null ? $a['id'] : $b['id'];
            $subnetCorroborated = $this->hasSubnet29Sibling($only['candidate'], $ownSiteId, $devicesBySite);
            $weak = $only['weak'];
            $confidence = $subnetCorroborated
                ? ($weak ? 'name_subnet29_fuzzy' : 'name_subnet29')
                : ($weak ? 'name_only_fuzzy' : 'name_only');
            $method = sprintf("single-sided%s: '%s' matches naming convention%s; other end has no matching device",
                $weak ? ' [weak: suffix/fuzzy match]' : '',
                $only['candidate']->name, $subnetCorroborated ? ' (/29-corroborated at its site)' : '');
        } else {
            $confidence = 'unresolved';
            $method = 'no device at either site matches the alpha2bravo naming convention (exact, suffix-stripped, or fuzzy)';
        }

        $interfaceA = $deviceA !== null ? $this->pickInterface($interfacesByDevice->get($deviceA->id, collect())) : null;
        $interfaceB = $deviceB !== null ? $this->pickInterface($interfacesByDevice->get($deviceB->id, collect())) : null;

        return [
            'device_a_id' => $deviceA?->id,
            'interface_a_id' => $interfaceA?->id,
            'device_b_id' => $deviceB?->id,
            'interface_b_id' => $interfaceB?->id,
            'endpoint_confidence' => $confidence,
            'endpoint_method' => $method,
            'endpoint_evidence' => [
                'distance_mi' => $distanceMi !== null ? round($distanceMi, 2) : null,
                'a' => $this->evidenceFor($sideA),
                'b' => $this->evidenceFor($sideB),
            ],
        ];
    }

    /** @return array{endpoint_confidence: 'unresolved'} plus null endpoints. */
    private function unresolved(string $reason): array
    {
        return [
            'device_a_id' => null, 'interface_a_id' => null,
            'device_b_id' => null, 'interface_b_id' => null,
            'endpoint_confidence' => 'unresolved',
            'endpoint_method' => $reason,
            'endpoint_evidence' => [],
        ];
    }

    /**
     * Find the device (if any) at $ownSiteDevices whose name parses into a `self2other` pair
     * (self-token matches this site, other-token matches the far site - via exact match,
     * role-suffix stripping, or bounded fuzzy matching, in that priority order), preferring an
     * explicit role tag matching $expectedRole. Ambiguous (2+ equally-good matches) resolves
     * to no candidate.
     *
     * @param  array<int, array{word:string,site_id:int}[]>  $fuzzyIndex
     */
    private function pickCandidate(Collection $ownSiteDevices, string $ownNname, string $otherNname, int $ownSiteId, int $otherSiteId, string $expectedRole, ?float $distanceMi, array $fuzzyIndex): array
    {
        $matches = [];

        foreach ($ownSiteDevices as $device) {
            $name = (string) $device->name;
            $lower = mb_strtolower($name);
            if (self::containsBanned($lower)) {
                continue;
            }

            foreach (self::parsePairs($name) as $pair) {
                // Self token: exact or fuzzy only - NEVER role-suffix-stripped (see class docblock).
                $selfMatch = self::matchToken($pair['t1'], $ownNname, $ownSiteId, false, $fuzzyIndex);
                if ($selfMatch === null) {
                    continue;
                }

                // Other token: exact, then role-suffix-stripped, then fuzzy.
                $otherMatch = self::matchToken($pair['t2'], $otherNname, $otherSiteId, true, $fuzzyIndex);
                if ($otherMatch === null) {
                    continue;
                }

                if (self::isSixtyGhz($lower) && $distanceMi !== null && $distanceMi > 2.0) {
                    // Naming matched, physics didn't - not usable evidence.
                    continue;
                }

                $role = self::detectRole($name) ?? $otherMatch['role'] ?? $selfMatch['role'];
                $matches[] = [
                    'device' => $device,
                    'token_self' => $pair['t1'], 'token_other' => $pair['t2'],
                    'role' => $role,
                    'weak' => $selfMatch['weak'] || $otherMatch['weak'],
                    'source' => $pair['source'],
                    'method_self' => $selfMatch['method'], 'method_other' => $otherMatch['method'],
                ];
            }
        }

        if ($matches === []) {
            return [
                'candidate' => null, 'role' => null, 'token_self' => null, 'token_other' => null,
                'alternates' => [], 'weak' => false, 'method_self' => null, 'method_other' => null,
            ];
        }

        // Three priority tiers, evaluated in order - the first non-empty tier is used
        // EXCLUSIVELY (never blended with a weaker tier, even to break a tie):
        //
        //  1. glued + exact  - byte-for-byte the original algorithm's matching universe. When
        //     a link already resolves this way, it must resolve to exactly this device,
        //     unconditionally - see below for why.
        //  2. any source + exact - covers spaced-separator matches once glued doesn't apply.
        //  3. weak (role-suffix/fuzzy), any source - the new, lower-confidence fallback.
        //
        // Both boundaries here were found via the production diff, comparing this class
        // against its own pre-change behaviour on live data:
        //
        //  - tier 1 vs. everything else: a device with a coincidental role-suffix-derived
        //    "AP"/"ST" tag was out-voting a plain exact match that carried no tag at all (e.g.
        //    site_link 8, Braham WT -> Mora/Nelson: "BrahamWT2MoraAP" only matches at all via
        //    role-suffix stripping, but was winning the role tie-break over the already-exact
        //    "BrahamWt2Mora/NelsonAP" simply for having a recovered tag). A weak match must
        //    never crowd out or steal the tie-break from an exact one.
        //  - glued vs. spaced specifically: sites with a working glued-form match sometimes
        //    ALSO have a second device whose name only parses as a pair once spaces are
        //    accepted (an older or duplicate radio also pointed at the same neighbour, a
        //    common real pattern in this fleet). Letting that newly-visible device compete on
        //    equal footing flipped several already-resolved links to a different device, or to
        //    a new, genuine ambiguity - a real behaviour change this action must not cause.
        //    Requiring glued-exact to win outright, whenever it exists, keeps every
        //    already-resolved link answering exactly as before; spaced-only sites (no glued
        //    rival) still gain new coverage via tier 2 untouched.
        $gluedExact = array_values(array_filter($matches, fn (array $m): bool => ! $m['weak'] && $m['source'] === 'glued'));
        $anyExact = array_values(array_filter($matches, fn (array $m): bool => ! $m['weak']));
        $tierMatches = match (true) {
            $gluedExact !== [] => $gluedExact,
            $anyExact !== [] => $anyExact,
            default => $matches,
        };

        // Prefer explicit-role matches for this side; among those (or, if none, among all
        // matches in this tier), a single survivor is used - 2+ survivors is genuine
        // ambiguity -> no guess.
        $roled = array_values(array_filter($tierMatches, fn (array $m): bool => $m['role'] === $expectedRole));
        $pool = $roled !== [] ? $roled : $tierMatches;

        // De-dup by device id (a name can theoretically parse into >1 matching pair).
        $byDevice = [];
        foreach ($pool as $m) {
            $byDevice[$m['device']->id] ??= $m;
        }

        if (count($byDevice) !== 1) {
            return [
                'candidate' => null, 'role' => null, 'token_self' => null, 'token_other' => null,
                'alternates' => array_map(fn (array $m): string => $m['device']->name, array_values($byDevice)),
                'weak' => false, 'method_self' => null, 'method_other' => null,
            ];
        }

        $only = array_values($byDevice)[0];

        return [
            'candidate' => $only['device'], 'role' => $only['role'],
            'token_self' => $only['token_self'], 'token_other' => $only['token_other'], 'alternates' => [],
            'weak' => $only['weak'], 'method_self' => $only['method_self'], 'method_other' => $only['method_other'],
        ];
    }

    /**
     * Try to match $token against a specific target site ($targetNname / $targetSiteId), in
     * priority order: exact containment, then (if $allowSuffixStrip) a role-suffix-stripped
     * retry, then bounded fuzzy matching against the target site's own name-words (tried on
     * both the original and, if applicable, the stripped token). Returns null when nothing
     * clears the bar - never a guess.
     *
     * @param  array<int, array{word:string,site_id:int}[]>  $fuzzyIndex
     * @return array{method: string, matched: string, weak: bool, role: ?string}|null
     */
    public static function matchToken(string $token, string $targetNname, int $targetSiteId, bool $allowSuffixStrip, array $fuzzyIndex): ?array
    {
        if (self::tokMatch($token, $targetNname)) {
            return ['method' => 'exact', 'matched' => $token, 'weak' => false, 'role' => null];
        }

        $stripped = $allowSuffixStrip ? self::stripRoleSuffix($token) : null;

        if ($stripped !== null && self::tokMatch($stripped, $targetNname)) {
            return ['method' => 'role_suffix', 'matched' => $stripped, 'weak' => true, 'role' => self::roleFromSuffix($token, $stripped)];
        }

        $fuzzy = self::fuzzyMatch($token, $fuzzyIndex);
        if ($fuzzy !== null && $fuzzy['site_id'] === $targetSiteId) {
            return ['method' => "fuzzy(d={$fuzzy['distance']})", 'matched' => $fuzzy['word'], 'weak' => true, 'role' => null];
        }

        if ($stripped !== null) {
            $fuzzyStripped = self::fuzzyMatch($stripped, $fuzzyIndex);
            if ($fuzzyStripped !== null && $fuzzyStripped['site_id'] === $targetSiteId) {
                return [
                    'method' => "role_suffix+fuzzy(d={$fuzzyStripped['distance']})", 'matched' => $fuzzyStripped['word'],
                    'weak' => true, 'role' => self::roleFromSuffix($token, $stripped),
                ];
            }
        }

        return null;
    }

    /** True when the resolved device shares a /29 (mgmt IP) with another device already at the same site. */
    private function hasSubnet29Sibling(object $device, int $siteId, Collection $devicesBySite): bool
    {
        $mine = self::slash29((string) $device->mgmt_ip);
        if ($mine === null) {
            return false;
        }

        foreach ($devicesBySite->get($siteId, collect()) as $sibling) {
            if ($sibling->id === $device->id) {
                continue;
            }
            if (self::slash29((string) $sibling->mgmt_ip) === $mine) {
                return true;
            }
        }

        return false;
    }

    /** Highest-priority wireless interface on a device, or null when none of the known radio names are present. */
    private function pickInterface(Collection $interfaces): ?object
    {
        $byName = $interfaces->keyBy(fn (object $i): string => mb_strtolower((string) $i->name));

        foreach (self::INTERFACE_PRIORITY as $candidate) {
            if ($byName->has($candidate)) {
                return $byName->get($candidate);
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function evidenceFor(array $side): array
    {
        return [
            'device_id' => $side['candidate']?->id,
            'device_name' => $side['candidate']?->name,
            'token_self' => $side['token_self'],
            'token_other' => $side['token_other'],
            'role' => $side['role'],
            'weak_match' => $side['weak'],
            'match_method' => $side['candidate'] !== null
                ? ['self' => $side['method_self'], 'other' => $side['method_other']]
                : null,
            'ambiguous_alternates' => $side['alternates'],
        ];
    }

    public static function normalize(string $s): string
    {
        return preg_replace('/[^a-z0-9]+/', '', mb_strtolower($s)) ?? '';
    }

    /** Individual normalized words of a site name (e.g. "Todd Heinrich" -> ["todd", "heinrich"]). */
    public static function siteWords(string $name): array
    {
        $words = preg_split('/\s+/', trim($name)) ?: [];

        return array_values(array_filter(array_map(self::normalize(...), $words), fn (string $w): bool => $w !== ''));
    }

    public static function tokMatch(string $token, string $nname): bool
    {
        if ($token === '' || $nname === '') {
            return false;
        }

        return str_contains($nname, $token) || str_contains($token, $nname);
    }

    /**
     * Every glued (`alpha2bravo`) and spaced (`alpha 2 bravo`) pair found in a device name,
     * tagged with which pattern produced it - see pickCandidate for why that tag matters
     * (glued-exact outranks everything else, to guarantee zero change on links the original,
     * glued-only algorithm already resolved).
     *
     * @return array<int, array{t1: string, t2: string, source: 'glued'|'spaced'}>
     */
    public static function parsePairs(string $name): array
    {
        $pairs = [];

        preg_match_all(self::PAIR_PATTERN, $name, $glued, PREG_SET_ORDER);
        foreach ($glued as $m) {
            $pairs[] = ['t1' => mb_strtolower($m[1]), 't2' => mb_strtolower($m[2]), 'source' => 'glued'];
        }

        preg_match_all(self::SPACED_PAIR_PATTERN, $name, $spaced, PREG_SET_ORDER);
        foreach ($spaced as $m) {
            $pairs[] = ['t1' => mb_strtolower($m[1]), 't2' => mb_strtolower($m[2]), 'source' => 'spaced'];
        }

        return $pairs;
    }

    /**
     * Strip a trailing AP/ST role tag (optionally version-tailed) glued onto a token, e.g.
     * "heinrichst" -> "heinrich". Returns null when the pattern doesn't apply - including when
     * stripping it would leave fewer than 4 characters. Deliberately NOT applied to every
     * token ending in "st"/"ap" blindly by callers - see class docblock on why this is
     * one-sided (never the self token).
     */
    public static function stripRoleSuffix(string $token): ?string
    {
        if (preg_match(self::ROLE_SUFFIX_PATTERN, $token, $m)) {
            return mb_strtolower($m[1]);
        }

        return null;
    }

    /** AP or ST, inferred from the suffix that stripRoleSuffix() removed. */
    public static function roleFromSuffix(string $originalToken, string $strippedPrefix): ?string
    {
        $suffix = mb_strtolower(mb_substr($originalToken, mb_strlen($strippedPrefix)));

        if (str_starts_with($suffix, 'ap')) {
            return 'AP';
        }
        if (str_starts_with($suffix, 'st')) {
            return 'ST';
        }

        return null;
    }

    /**
     * @param  Collection<int, array{id:int,nname:string,words:string[],lat:?float,lon:?float}>  $sites
     * @return array<int, array{word:string,site_id:int}[]> Keyed by word length, for a cheap length-window scan.
     */
    public static function buildFuzzyIndex(Collection $sites): array
    {
        $byLength = [];

        foreach ($sites as $site) {
            foreach ($site['words'] as $word) {
                $len = mb_strlen($word);
                if ($len < self::FUZZY_MIN_SITE_WORD_LEN) {
                    continue;
                }
                $byLength[$len][] = ['word' => $word, 'site_id' => $site['id']];
            }
        }

        return $byLength;
    }

    /**
     * Bounded fuzzy match: $token against every indexed site name-word within
     * FUZZY_MAX_DISTANCE. Returns null unless exactly one site achieves the minimum distance
     * found (a tie against a second site is treated as ambiguous, not a guess).
     *
     * @param  array<int, array{word:string,site_id:int}[]>  $fuzzyIndex
     * @return array{site_id: int, distance: int, word: string}|null
     */
    public static function fuzzyMatch(string $token, array $fuzzyIndex): ?array
    {
        $token = mb_strtolower($token);
        $tokenLen = mb_strlen($token);
        if ($tokenLen < self::FUZZY_MIN_TOKEN_LEN) {
            return null;
        }

        $bestDistance = null;
        $bestSiteIds = [];
        $bestWord = null;

        for ($len = $tokenLen - self::FUZZY_MAX_DISTANCE; $len <= $tokenLen + self::FUZZY_MAX_DISTANCE; $len++) {
            foreach ($fuzzyIndex[$len] ?? [] as $entry) {
                $d = levenshtein($token, $entry['word']);
                if ($d > self::FUZZY_MAX_DISTANCE) {
                    continue;
                }
                if ($bestDistance === null || $d < $bestDistance) {
                    $bestDistance = $d;
                    $bestSiteIds = [$entry['site_id'] => true];
                    $bestWord = $entry['word'];
                } elseif ($d === $bestDistance) {
                    $bestSiteIds[$entry['site_id']] = true;
                }
            }
        }

        if ($bestDistance === null || count($bestSiteIds) !== 1) {
            return null;
        }

        return ['site_id' => array_key_first($bestSiteIds), 'distance' => $bestDistance, 'word' => (string) $bestWord];
    }

    private static function containsBanned(string $lowerName): bool
    {
        foreach (self::BANNED_NAME_SUBSTRINGS as $needle) {
            if (str_contains($lowerName, $needle)) {
                return true;
            }
        }

        return false;
    }

    private static function isSixtyGhz(string $lowerName): bool
    {
        foreach (self::SIXTY_GHZ_MARKERS as $needle) {
            if (str_contains($lowerName, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * AP/ST as a standalone word, case-insensitive, optionally version-tailed (STv3, APv5).
     * Null when neither tag is present.
     */
    private static function detectRole(string $name): ?string
    {
        if (preg_match('/\bAP(?:V\d{1,2})?\b/i', $name)) {
            return 'AP';
        }
        if (preg_match('/\bST(?:V\d{1,2})?\b/i', $name)) {
            return 'ST';
        }

        return null;
    }

    /** IPv4 /29 network as a string key (e.g. "10.80.2.0"), or null for anything unparsable/non-IPv4. */
    private static function slash29(string $ip): ?string
    {
        $long = ip2long($ip);
        if ($long === false) {
            return null;
        }

        $network = $long & ~7; // /29 = 3 host bits

        return long2ip($network);
    }

    private static function haversineMiles(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadiusMi = 3958.8;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;

        return $earthRadiusMi * 2 * asin(min(1.0, sqrt($a)));
    }
}
