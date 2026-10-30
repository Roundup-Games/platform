#!/usr/bin/env bash
#
# Guardrail: platform invariant checks — the "rule of one" for query shapes
# and logic that encode security or race-sensitivity rules.
#
# Background: the 2026-10 sustainability audit found the platform's most
# dangerous debt was not bad code but DUPLICATED critical logic whose copies
# drift: the connection-aware visibility clause existed 4x (one copy had
# already re-implemented itself), the nearby-games geohash scaffold 5x (the
# trending copy had silently dropped its capacity filter), the slug
# transliteration map 2x, the share-token predicate 3x, and the share-link
# join pipeline 3x. Every copy was consolidated to exactly one definition;
# this script keeps them at one.
#
# Each check is HIGH-PRECISION (low false-positive) so it can gate CI:
#   INV-1  visibility rule      → only VisibleToScope defines scopeVisibleTo
#   INV-2  geohash bbox clause  → only Geohash::applyBounds (+ ProximityQuery,
#                                 whose bbox derives from radius, not a tile)
#   INV-3  nearby scaffold      → scopeNearbyOpen defined only on Game
#   INV-4  slug algorithm       → transliteration only in SlugService
#   INV-5  share-token check    → hash_equals token compare only in the trait
#   INV-6  join pipeline        → both detail components route joins through
#                                 ShareLinkJoinService (no inline transactions)
#   INV-7  removed footguns stay removed (demo --force, inspire, queueAction,
#                                 one-off backfill signatures)
#   INV-8  webhook secret       → unconfigured secret refuses the route
#                                 (site degrades; never processes unverified)
#   INV-9  no reflection into app internals from tests (use real APIs;
#                                 LegacyLocationJsonLeakTest's ReflectionClass
#                                 read of buildEventPlace is allowlisted)
#   INV-10 uuid PK generation     -> ids are assigned only by the
#                                 HasPlatformUuid trait (uuidv7); no model
#                                 hand-rolls ->id = (string) Str::...
#   INV-11 morph writers          -> raw *_type writes/queries use
#                                 getMorphClass()/whereMorphedTo, never
#                                 get_class()/::class constants
#   INV-12 city cluster identity  -> locations cluster through the city
#                                 registry (CityRegistryService + FK);
#                                 Str::slug string-matching on location
#                                 city outside the registry splits data
#   INV-13 entity SEO rendering   -> seo()->for() receives an SEOData
#                                 from HasEntitySeo::entitySeoData()
#                                 (or an explicit SEOData), never a bare
#                                 model (the retired seo-table row layer)
#   INV-14 country intake         -> country fields use CountrySelect /
#                                 App\Support\Country (ISO 3166-1
#                                 alpha-3), never free-text inputs
#   INV-16 discovery interface     -> DiscoveryQueryService's public surface
#                                 is the D251/D254 contract (results/curated,
#                                 getRecommendations, the two builders, the
#                                 three test-contract helpers) and the only
#                                 external builder consumer is the landing
#                                 service — new feed features enter through
#                                 results()/curated(), never new public
#                                 methods or page-local pipelines
#   INV-15 participant writes     -> game_participants/campaign_participants
#                                 creation + status writes live only in
#                                 sanctioned modules (D161 rule-of-one):
#                                 ParticipantLifecycle (the join intake),
#                                 WaitlistService, Roster,
#                                 OwnerParticipantService, CapacityService,
#                                 ParticipantService (host invites),
#                                 OverflowRouter, HandlesWaitlist,
#                                 HasParticipantDefaults boot defaults
#
# Scope: app/, resources/, routes/, tests/ as noted per check. Patterns use
# fixed strings where possible (grep -F) to stay bash-safe.
#
# Requires: grep (no rg dependency). Any unexpected hit is a HARD FAIL.
# Scope caveat: these greps are tripwires, not boundaries — multi-hop builder
# chains and novel write shapes can evade them. The sanctioned-module
# allowlist is the policy; review enforces what grep cannot see.
#
# Run:    composer invariants
# Bypass: not in the pre-commit hook; invoke explicitly or via CI.

set -uo pipefail

RED=$(printf '\033[0;31m')
GRN=$(printf '\033[0;32m')
YLW=$(printf '\033[0;33m')
NC=$(printf '\033[0m')

failures=0

fail() {
    echo "  ${RED}FAIL${NC} $1"
    failures=$((failures + 1))
}

pass() {
    echo "  ${GRN}ok${NC}   $1"
}

# --- INV-1: the visibility rule lives only in VisibleToScope ----------------
# Allowlist: EventAnnouncement::scopeVisibleTo is a DIFFERENT rule — event-
# scoped disclosure levels (all/registered/private, gated on confirmed
# registration or event-management permission) — not the connection-aware
# public/protected/private content rule. It is intentionally its own scope.
holders=$(grep -rl "function scopeVisibleTo" app/ 2>/dev/null | grep -v 'app/Models/EventAnnouncement.php' | sort)
expected=$'app/Models/Concerns/VisibleToScope.php'
if [ "$holders" = "$expected" ]; then
    pass "INV-1 visibility scope defined only in VisibleToScope"
else
    fail "INV-1 scopeVisibleTo must be defined ONLY in app/Models/Concerns/VisibleToScope.php (found: $(echo "$holders" | tr '\n' ' ')). Re-implementing the visibility clause inline is how discovery and dashboards drift apart — compose ->visibleTo(\$user) instead."
fi
if grep -rq "buildVisibilityClause" app/ 2>/dev/null; then
    fail "INV-1 buildVisibilityClause must not return — visibility is scopeVisibleTo (VisibleToScope trait)"
else
    pass "INV-1 no buildVisibilityClause re-implementation"
fi

# --- INV-2: geohash bbox clause lives only in Geohash::applyBounds ----------
bbox_files=$(grep -rl "whereBetween('locations.latitude'" app/ 2>/dev/null | sort)
expected_bbox=$'app/Services/Geohash.php\napp/Services/ProximityQuery.php'
if [ "$bbox_files" = "$expected_bbox" ]; then
    pass "INV-2 geohash bbox defined only in Geohash::applyBounds (+ ProximityQuery radius-based bbox)"
else
    fail "INV-2 inline 'locations.latitude' bounding box found in: $(echo "$bbox_files" | tr '\n' ' '). Tile math is Geohash::applyBounds; nearby queries compose it (directly or via Game::scopeNearbyOpen). ProximityQuery.php is the only allowlisted inline copy (radius-derived, not tile-derived)."
fi

# --- INV-3: the nearby-games scaffold is Game::scopeNearbyOpen --------------
scaffold=$(grep -rl "function scopeNearbyOpen" app/ 2>/dev/null | sort)
if [ "$scaffold" = "app/Models/Game.php" ]; then
    pass "INV-3 scopeNearbyOpen defined only on Game"
else
    fail "INV-3 scopeNearbyOpen must stay on Game only (found: $(echo "$scaffold" | tr '\n' ' ')). Dashboard computers compose it; never copy the join/bbox/window SQL."
fi

# --- INV-4: slug transliteration lives only in SlugService ------------------
translit=$(grep -rl "function transliterate" app/ 2>/dev/null | sort)
if [ "$translit" = "app/Services/SlugService.php" ]; then
    pass "INV-4 transliteration defined only in SlugService"
else
    fail "INV-4 transliterate() found in: $(echo "$translit" | tr '\n' ' '). User and Location MUST slug byte-identically — the map lives once, in SlugService."
fi

# --- INV-5: the share-token predicate lives only in HasShareToken -----------
if grep -rq 'hash_equals($this->share_token' app/Models/Concerns/HasShareToken.php \
    && ! grep -rq 'hash_equals($this->share_token' app/Models/Game.php app/Models/Campaign.php app/Models/Event.php app/Models/Team.php 2>/dev/null; then
    pass "INV-5 share-token timing-safe compare only in HasShareToken"
else
    fail "INV-5 hasValidShareToken()/hash_equals token compare drifted out of App\\Models\\Concerns\\HasShareToken — keep the predicate in the trait applied by Game and Campaign."
fi

# --- INV-6: joins route through ShareLinkJoinService ------------------------
for component in app/Livewire/Games/GameDetail.php app/Livewire/Campaigns/CampaignDetail.php; do
    if grep -q "ShareLinkJoinService" "$component"; then
        pass "INV-6 $(basename "$component") routes joins through ShareLinkJoinService"
    else
        fail "INV-6 $component no longer references ShareLinkJoinService — the lock-guarded join pipeline (lockForUpdate + short-link revalidation + capacity + overflow + approved_at) must exist exactly once, in that service."
    fi
done
if grep -rq "DB::transaction" app/Livewire/Games/GameDetail.php app/Livewire/Campaigns/CampaignDetail.php 2>/dev/null; then
    fail "INV-6 inline DB::transaction returned to a detail component — join/capacity mutations belong in services (ShareLinkJoinService / ParticipantLifecycle / CapacityService)."
else
    pass "INV-6 no inline join transactions in detail components"
fi

# --- INV-7: removed footguns stay removed -----------------------------------
footguns=0
grep -rq "queueAction" resources/ public/sw.js 2>/dev/null && { fail "INV-7 offline-queue queueAction resurrected (dropped by decision — the SW queue had zero callers)"; footguns=1; }
grep -q "{--force : Skip environment check}" app/Console/Dev/DemoSeedCommand.php 2>/dev/null && { fail "INV-7 demo:seed --force prod bypass resurrected (registration is environment-gated in AppServiceProvider)"; footguns=1; }
grep -rq "Inspiring::quote" routes/ 2>/dev/null && { fail "INV-7 inspire scaffold command resurrected"; footguns=1; }
for sig in "location:migrate" "location:add-geohash" "users:backfill-slugs" "locations:backfill-slugs" "migrate:share-tokens" "short-links:hash-ips"; do
    grep -rq "'$sig\|$sig " app/Console/Commands/ 2>/dev/null && { fail "INV-7 retired one-off command signature '$sig' re-registered in app/Console/Commands (deleted by decision; recover from git history if a migration must re-run)"; footguns=1; }
done
[ "$footguns" = "0" ] && pass "INV-7 removed footguns stay removed"

# --- INV-8: unconfigured Paddle webhooks are refused at the route -----------
# With PADDLE_WEBHOOK_SECRET absent, Cashier skips VerifyWebhookSignature
# entirely — the production /paddle/webhook route must refuse traffic
# (EnsurePaddleWebhookConfigured) instead of processing unverified payloads.
# The site itself degrades, it does not block: AppServiceProvider logs the
# boot-time warning instead of throwing.
if grep -q "cashier.webhook_secret" app/Http/Middleware/EnsurePaddleWebhookConfigured.php \
    && grep -qF "abort(503" app/Http/Middleware/EnsurePaddleWebhookConfigured.php \
    && grep -q "EnsurePaddleWebhookConfigured" routes/web.php \
    && grep -q "cashier.webhook_secret" app/Providers/AppServiceProvider.php; then
    pass "INV-8 unconfigured webhook secret refuses /paddle/webhook (route-level) + boot warning"
else
    fail "INV-8 unconfigured-secret handling drifted: the EnsurePaddleWebhookConfigured middleware (abort 503 when cashier.webhook_secret is empty in production), its routes/web.php wiring, or the AppServiceProvider boot warning is missing. Production must never process unverified Paddle payloads — and must not fail boot either: the site runs, the route refuses."
fi

# --- INV-9: no reflection into app internals from tests ---------------------
refl=$(grep -rln "new \\\\ReflectionMethod" tests/ 2>/dev/null | sort)
if [ -z "$refl" ]; then
    pass "INV-9 no ReflectionMethod in tests"
else
    fail "INV-9 ReflectionMethod found in: $(echo "$refl" | tr '\n' ' '). Tests must exercise real APIs (HTTP endpoints, services) — reflection tests break on refactor while asserting nothing about behavior."
fi


# --- INV-10: primary-key id generation lives only in HasPlatformUuid ------
# D170: RFC 9562 uuidv7 for owned ids via the framework HasUuids trait.
# Any hand-rolled id assignment in a model is a regression to the
# pre-D170 state (43 duplicated creating hooks, mixed v4/comb schemes).
hits=$(grep -rn -- "->id = (string) Str::" app/Models/ 2>/dev/null | sort)
if [ -z "$hits" ]; then
    pass "INV-10 no hand-rolled PK id assignment in app/Models (HasPlatformUuid only)"
else
    fail "INV-10 hand-rolled id assignment found (use App\\Models\\Concerns\\HasPlatformUuid): $(echo "$hits" | tr '\n' ' ')"
fi

# --- INV-14: country intake is a select, never free text ------------------
# D173: TextInput::make('country') reintroduces ISO-2/mixed-case values
# that split country grouping; every Filament surface uses CountrySelect.
hits=$(grep -rn "TextInput::make('country')" app/Filament/ 2>/dev/null | grep -vE ":[0-9]+:\s*(\*|//)" | sort)
if [ -z "$hits" ]; then
    pass "INV-14 no free-text country inputs in Filament (CountrySelect only)"
else
    fail "INV-14 free-text country input found (use App\Filament\Components\CountrySelect): $(echo "$hits" | tr '\n' ' ')"
fi

# --- INV-13: entity SEO renders through HasEntitySeo, not the seo table ---
# D172: a bare seo()->for($model) call reads the retired single-language
# seo-table row layer and silently bypasses the per-locale curated
# overrides. Model SEO must resolve via entitySeoData(locale).
hits=$(grep -rnE "seo\(\)->for\(\s*\\$" app/ --include='*.php' 2>/dev/null | grep -v "entitySeoData" | grep -vE ":[0-9]+:\s*(\*|//)" | sort)
if [ -z "$hits" ]; then
    pass "INV-13 no bare-model seo()->for() calls (HasEntitySeo::entitySeoData only)"
else
    fail "INV-13 seo()->for() called with a model (use \$model->entitySeoData(app()->getLocale())): $(echo "$hits" | tr '\n' ' ')"
fi

# --- INV-12: city clusters resolve through the registry, not strings ------
# D171: cluster membership lives in locations.city_id (FK to the cities
# registry). Deriving clusters by Str::slug()-matching raw city strings
# outside CityRegistryService (the single write authority) reintroduces
# the geocoder-variant data split the registry cured.
hits=$(grep -rnE "Str::slug\(.{0,30}\$city" app/ --include='*.php' 2>/dev/null | grep -v "Services/CityRegistryService.php" | sort)
if [ -z "$hits" ]; then
    pass "INV-12 no string-matched city clustering outside CityRegistryService (registry FK only)"
else
    fail "INV-12 Str::slug() applied to a location city value outside CityRegistryService: $(echo "$hits" | tr '\n' ' ')"
fi

# --- INV-11: raw morph-type writes/queries go through the morph map ------
# With the enforced morph map (D170), raw FQCN strings in *_type columns
# no longer match stored alias values. Writers/readers must use
# getMorphClass()/whereMorphedTo; get_class() and ::class constants in
# morph contexts silently split the dataset.
hits=$(grep -rnE "where\('[a-z_.]+_type'\s*,\s*(get_class\(|[A-Z][A-Za-z]+::class)" app/ 2>/dev/null | grep -vE "event_type|game_type|venue_type|ticket_type|agent_type|preference_type|tool_type|bgg_type|join_source" | sort)
if [ -z "$hits" ]; then
    pass "INV-11 no raw FQCN morph-type comparisons (getMorphClass/whereMorphedTo only)"
else
    fail "INV-11 raw morph-type comparison found (use getMorphClass()/whereMorphedTo): $(echo "$hits" | tr '\n' ' ')"
fi

# --- INV-15: participant writes live only in sanctioned modules -------------
# M072: every join path (apply, share-intent, share-link game+campaign legs,
# Discord RSVP, campaign fan-out) routes through ParticipantLifecycle::
# createOrReactivate() — the join intake owns the signup cutoff, overflow
# routing, approved_at stamping, and audit columns. A participant create or
# status write anywhere else reintroduces per-path drift. Sanctioned writers:
#   app/Services/ParticipantLifecycle.php    the join intake: create/reactivate,
#                                            promote, depart/kick, ban
#   app/Services/WaitlistService.php         waitlist/bench placement + promotion
#   app/Services/Roster.php                  host departures (status -> Rejected)
#   app/Services/OwnerParticipantService.php owner rows (updateOrCreate upgrade)
#   app/Services/CapacityService.php         LIFO capacity demotion (D108 — a
#                                            demotion is not a departure)
#   app/Services/ParticipantService.php      host-initiated invites (Pending
#                                            rows; cutoff-exempt per D124)
#   app/Services/OverflowRouter.php          overflow placement for invitees
#   app/Traits/HandlesWaitlist.php           user waitlist-leave (Rejected)
#   app/Models/Concerns/HasParticipantDefaults.php  boot defaults (created_at)
# Model observers stamp approved_at reactively on save (not writers). The
# dev seeder (app/Console/Dev) mass-inserts synthetic rows with raw batches
# and is scope-excluded; TeamMember rows share the ParticipantStatus enum but
# are a different table — the status check only fires on files that also carry
# game/campaign participant vocabulary.
participant_sanctioned='^app/(Services/(ParticipantLifecycle|WaitlistService|Roster|OwnerParticipantService|CapacityService|ParticipantService|OverflowRouter)|Traits/HandlesWaitlist|Models/Concerns/HasParticipantDefaults)\.php:'
creation_hits=$(grep -rnE "(GameParticipant|CampaignParticipant)::(create|forceCreate|updateOrCreate|firstOrCreate|firstOrNew|insert|upsert)|->participants\(\)->(create|attach|save|createMany|firstOrCreate|firstOrNew|updateOrCreate)|participantClass::(create|forceCreate|updateOrCreate|firstOrCreate|firstOrNew)|new (GameParticipant|CampaignParticipant)\(" app/ --include='*.php' 2>/dev/null | grep -v '^app/Console/Dev/' | grep -vE ':[0-9]+:\s*(\*|//)' | grep -vE "$participant_sanctioned" | sort)
if [ -z "$creation_hits" ]; then
    pass "INV-15 participant rows created only in sanctioned modules (join intake)"
else
    fail "INV-15 participant row created outside sanctioned modules: $(echo "$creation_hits" | tr '\n' ' '). Joins must route through ParticipantLifecycle::createOrReactivate(); invites/overflow/waitlist writes belong in the sanctioned services listed in the header."
fi
status_violators=''
for file in $(grep -rlE "['\"]status['\"] => (ParticipantStatus::|\\\$overflow)|->status = ParticipantStatus" app/ --include='*.php' 2>/dev/null | grep -v '^app/Console/Dev/' | sort); do
    # $casts lines ('status' => ParticipantStatus::class) are not writes;
    # TeamMember-only files share the enum but never touch participant rows.
    if grep -nE "['\"]status['\"] => (ParticipantStatus::|\\\$overflow)|->status = ParticipantStatus" "$file" | grep -v 'ParticipantStatus::class' | grep -qvE '^[0-9]+:\s*(\*|//)' \
        && grep -qE '(GameParticipant|CampaignParticipant|->participants\(\)|participantClass)' "$file" \
        && ! echo "${file}:" | grep -qE "$participant_sanctioned"; then
        status_violators="$status_violators $file"
    fi
done
if [ -z "$status_violators" ]; then
    pass "INV-15 participant status written only in sanctioned modules"
else
    fail "INV-15 participant status written outside sanctioned modules:$status_violators. Status transitions (approved/waitlisted/benched/rejected/removed) belong in the sanctioned lifecycle services listed in the header."
fi

# --- INV-15b: no raw query-builder writes to participant tables -------------
# The checks above police Eloquent vocabulary; a DB::table('game_participants')
# ->insert/update chain would slip past them. Same-line and next-line chain
# shapes are detected (longer chains can still evade — this is a tripwire,
# not a boundary; the sanctioned-module allowlist is the policy).
raw_writes=$(grep -rnE "DB::table\(['\"](game_participants|campaign_participants)['\"]\)\s*->\s*(insert|update|upsert|delete|insertUsing|updateOrInsert|increment|decrement|truncate)\(" app/ --include='*.php' 2>/dev/null | grep -v '^app/Console/Dev/')
for match_file in $(grep -rlE "DB::table\(['\"](game_participants|campaign_participants)['\"]\)\s*$" app/ --include='*.php' 2>/dev/null | grep -v '^app/Console/Dev/'); do
    line_no=$(grep -nE "DB::table\(['\"](game_participants|campaign_participants)['\"]\)\s*$" "$match_file" | head -1 | cut -d: -f1)
    if sed -n "$((line_no + 1))p" "$match_file" | grep -qE "^\s*->\s*(insert|update|upsert|delete|insertUsing|updateOrInsert)\("; then
        raw_writes="$raw_writes
$match_file:$line_no: (participant-table write chained on the next line)"
    fi
done
if [ -z "$raw_writes" ]; then
    pass "INV-15 no raw query-builder writes to participant tables"
else
    fail "INV-15 participant tables written via DB::table(): $(echo "$raw_writes" | tr '\n' ' '). Route through the sanctioned lifecycle services (ParticipantLifecycle et al.) — raw chains bypass every Eloquent guard."
fi

# --- INV-15c: no bare-string participant status writes -----------------------
# The enum-based status check misses 'status' => 'approved' literals. Same
# file-level participant-vocabulary discriminator as the enum check; the
# applications tables and cache arrays legitimately use these strings too,
# which is why the vocabulary gate (not the literal) is load-bearing.
raw_status_files=''
for file in $(grep -rlE "[\"]status[\"] => [\"](approved|pending|waitlisted|benched|rejected|removed)[\"]" app/ --include="*.php" 2>/dev/null | grep -v "^app/Console/Dev/" | sort); do
    if grep -qE "(GameParticipant|CampaignParticipant|->participants\(\)|participantClass)" "$file" \
        && ! echo "${file}:" | grep -qE "$participant_sanctioned"; then
        raw_status_files="$raw_status_files $file"
    fi
done
if [ -z "$raw_status_files" ]; then
    pass "INV-15 participant status not written as bare strings"
else
    fail "INV-15 bare-string participant status write in:$raw_status_files. Use ParticipantStatus enum values — sanctioned lifecycle services never write raw strings."
fi

# --- INV-16: DiscoveryQueryService interface contract (D251/D254) -----------
# The discovery module's whole point (M072/S03) is that feed policy lives in
# one pipeline: a caller of a raw builder silently misses everything around
# it (mode constraints, MERGE_HYDRATION_CAP, merge-key ordering, gathering
# cap, merge/pagination) — the drift class S03 removed. Two tripwires:
#   (a) the public method set is exactly the recorded contract — widening it
#       needs a decision (D251/D254) in the same change;
#   (b) the five seam methods' only external app/ consumer is the landing
#       service's buildGamesQuery ride (D250) — a second consumer is a
#       routing decision: route through results()/curated() or record it.
dqs_public=$(grep -oE 'public (static )?function [A-Za-z_]+' app/Services/DiscoveryQueryService.php | awk '{print $NF}' | grep -v '^__construct$' | sort -u)
expected_dqs=$'applyGatheringCap\nbuildCampaignsQuery\nbuildGamesQuery\ncurated\ngetProximityCampaignDistances\ngetRecommendations\norderCampaignsByMergeKey\nresults'
if [ "$dqs_public" = "$expected_dqs" ]; then
    pass "INV-16 DiscoveryQueryService public surface matches the D251/D254 contract"
else
    fail "INV-16 DiscoveryQueryService public surface drifted (D251/D254). Expected exactly: applyGatheringCap, buildCampaignsQuery, buildGamesQuery, curated, getProximityCampaignDistances, getRecommendations, orderCampaignsByMergeKey, results. Found: $(echo "$dqs_public" | tr '\n' ' '). New feed features enter through results()/curated() — or record the widening as a decision in the same change."
fi
dqs_callers=$(grep -rnE -e "->(buildGamesQuery|buildCampaignsQuery|applyGatheringCap|getProximityCampaignDistances|orderCampaignsByMergeKey)\(" app/ --include='*.php' 2>/dev/null | grep -v 'app/Services/DiscoveryQueryService.php' | cut -d: -f1 | sort -u)
expected_dqs_callers=$'app/Services/GameSystemLandingService.php'
if [ "$dqs_callers" = "$expected_dqs_callers" ]; then
    pass "INV-16 discovery seam methods consumed externally only by the landing service"
else
    fail "INV-16 discovery seam methods have callers outside the contract: $(echo "$dqs_callers" | tr '\n' ' '). A second builder consumer bypasses mode scoping, the hydration cap, and merge ordering — route through results()/curated() or record the exception as a decision in the same change."
fi

# --- Summary ----------------------------------------------------------------
echo
if [ "$failures" -gt 0 ]; then
    echo "${RED}${failures} platform invariant(s) violated.${NC} See .ai/ or the audit decisions: each invariant names the single canonical home for the rule."
    exit 1
fi
echo "${GRN}All platform invariants hold.${NC}"
exit 0
