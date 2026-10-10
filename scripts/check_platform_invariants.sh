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
#
# Scope: app/, resources/, routes/, tests/ as noted per check. Patterns use
# fixed strings where possible (grep -F) to stay bash-safe.
#
# Requires: grep (no rg dependency). Any unexpected hit is a HARD FAIL.
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

# --- Summary ----------------------------------------------------------------
echo
if [ "$failures" -gt 0 ]; then
    echo "${RED}${failures} platform invariant(s) violated.${NC} See .ai/ or the audit decisions: each invariant names the single canonical home for the rule."
    exit 1
fi
echo "${GRN}All platform invariants hold.${NC}"
exit 0
