#!/usr/bin/env bash
#
# Guardrail: detect violations of the Eloquent best-practices baseline
# documented in CONTRIBUTING.md ("Eloquent Best Practices").
#
# These rules prefer Laravel's model-aware APIs over manual ID plumbing:
#   route(model) · create-through-relation · attach(model) · whereBelongsTo
#   · whereKey · is()/isNot() · associate() · touch() · Gate/policy auth.
#
# The patterns below are HIGH-PRECISION (low false-positive) so the check
# can gate CI. Because the production codebase has been migrated to zero
# baseline hits, ANY match in app/ or resources/views/ is a regression.
#
# R2 (create-through-relation) uses rg MULTILINE mode (-U): the violation
# spans lines when Model::create([ ... 'fk_id' => $model->id ... ]) is split
# across rows, which is the common formatting. [^]]* then crosses newlines
# inside the array literal (it stops at the closing ]) so each match is one
# create() call regardless of how it is wrapped.
#
# Scope:
#   - app/ and resources/views/  → HARD FAIL on any match
#   - tests/                     → REPORTED only (not yet fully migrated)
#
# Patterns use \x27 for literal single-quotes (bash-safe in single quotes)
# and rg -e so patterns starting with '-' are not read as flags. Lines that
# are plain query-builder (DB::table() / ->from() subqueries) are excluded —
# the model-aware APIs do not exist there and they are not violations.
#
# Requires: ripgrep (rg). In CI the script hard-fails if rg is absent so that
# enforcement never silently no-ops (an image change dropping rg would
# otherwise turn every scan into a clean PASS). Locally it warns and exits 0.
#
# Run:    composer practices
# Bypass: not in the pre-commit hook; invoke explicitly or via CI.

set -eo pipefail

RED='\033[0;31m'
GRN='\033[0;32m'
YLW='\033[0;33m'
CYN='\033[0;36m'
RST='\033[0m'

prod_hits=0
test_hits=0

# rg is mandatory. Without it every scan would silently report "clean",
# defeating the guardrail. Fail loud in CI; warn-and-skip locally.
if ! command -v rg &>/dev/null; then
    if [[ "${CI:-false}" == "true" || -n "${GITHUB_ACTIONS:-}" ]]; then
        printf "${RED}❌ FAIL:${RST} ripgrep (rg) not found — guardrail cannot run.\n" >&2
        exit 2
    fi
    printf "${YLW}⚠️  rg not found — guardrail skipped locally.${RST}\n" >&2
    exit 0
fi

# scan <label> <pattern> <path>
scan() {
    local label="$1" pattern="$2" path="$3"
    local matches
    matches=$(rg -n -e "${pattern}" "${path}" 2>/dev/null || true)
    # Drop non-Eloquent query-builder contexts (no model-aware API available).
    matches=$(printf '%s\n' "${matches}" | grep -v -e 'DB::table(' -e '->from(' || true)
    if [[ -z "${matches}" ]]; then
        printf "  ${GRN}✅ %-9s${RST} %s\n" "${label}" "clean"
        return 0
    fi
    local count
    count=$(printf '%s\n' "${matches}" | grep -c . || true)
    printf "  ${RED}❌ %-9s${RST} %d hit(s) in %s\n" "${label}" "${count}" "${path}"
    printf '%s\n' "${matches}" | sed 's/^/      /'
    record_hits "${path}" "${count}"
}

# scan_ml <label> <pattern> <path>  — multiline (-U) variant for R2, whose
# violation spans lines (Model::create([ ... 'fk_id' => $model->id ... ])).
# [^]]* crosses newlines inside the array and stops at the closing ]; each
# create() call is therefore one match. Counts MATCHES not lines via
# --count-matches, because a single match may occupy several rows.
scan_ml() {
    local label="$1" pattern="$2" path="$3"
    local display
    display=$(rg -U -n -e "${pattern}" "${path}" 2>/dev/null || true)
    if [[ -z "${display}" ]]; then
        printf "  ${GRN}✅ %-9s${RST} %s\n" "${label}" "clean"
        return 0
    fi
    local count
    count=$(rg -U --count-matches -e "${pattern}" "${path}" 2>/dev/null | awk -F: '{s+=$NF} END{print s+0}' || true)
    printf "  ${RED}❌ %-9s${RST} %d hit(s) in %s\n" "${label}" "${count}" "${path}"
    printf '%s\n' "${display}" | sed 's/^/      /'
    record_hits "${path}" "${count}"
}

# record_hits <path> <count>  — route hits to the prod or test bucket.
record_hits() {
    if [[ "$1" == "tests/"* ]]; then
        test_hits=$((test_hits + $2))
    else
        prod_hits=$((prod_hits + $2))
    fi
}

# check_morph_map <file>  — assert User and Location are NOT aliased in any
# Relation::morphMap([...]) registration. Both must resolve to their FQCN so
# polymorphic ticket/review data (written as FQCN via whereMorphedTo /
# morphMany) stays queryable. Aliasing them here would store a short alias on
# new rows while existing rows keep the FQCN — silently splitting the data.
# See the NOTE at the morphMap registration in AppServiceProvider.
check_morph_map() {
    local file="$1"
    # Capture every morphMap([...]) block (multiline; [^]]* crosses newlines
    # inside the array and stops at the closing ]). -N drops the filename prefix.
    local blocks
    blocks=$(rg -U -N -e 'Relation::morphMap\(\[[^]]*\]\)' "${file}" 2>/dev/null || true)
    if [[ -z "${blocks}" ]]; then
        printf "  ${YLW}⚠️  %-9s${RST} %s\n" "morphMap" "no morphMap registration found in ${file}"
        return 0
    fi
    local bad
    bad=$(printf '%s\n' "${blocks}" | rg -n -e "'(user|location)'\s*=>" || true)
    if [[ -n "${bad}" ]]; then
        local count
        count=$(printf '%s\n' "${bad}" | grep -c . || true)
        printf "  ${RED}❌ %-9s${RST} %d forbidden alias(es) in %s (would orphan morph data)\n" "morphMap" "${count}" "${file}"
        printf '%s\n' "${bad}" | sed 's/^/      /'
        prod_hits=$((prod_hits + count))
    else
        printf "  ${GRN}✅ %-9s${RST} %s\n" "morphMap" "no user/location aliases"
    fi
}

printf "\n${CYN}🔍 Eloquent best-practices guardrail${RST}\n\n"

# ── Production code (app/ + resources/views/) — HARD FAIL ────────────────────
printf "${CYN}Production code (app/, resources/views/):${RST}\n"

# Rule 1 — routing: pass the model, not its id (games/campaigns are {id} routes)
scan "R1-route" \
    'route\(\x27(games|campaigns)\.[a-z_-]+\x27,\s*\$\w+->(id|getKey\(\))' \
    "app/"
scan "R1-route" \
    'route\(\x27(games|campaigns)\.[a-z_-]+\x27,\s*\$\w+->(id|getKey\(\))' \
    "resources/views/"

# Rule 2 — create related models through the relationship (multiline: the
# 'fk_id' => $model->id pair usually sits on its own line inside create([...]))
scan_ml "R2-create" \
    '::(create|firstOrCreate|firstOrNew|updateOrCreate|createQuietly)\(\[[^]]*?\w+_id\x27 => \$\w+->(id|getKey\(\))' \
    "app/"

# Rule 3 — pivot attach/sync/detach accept models directly
scan "R3-pivot" \
    '->(attach|detach|sync|syncWithoutDetaching|updateExistingPivot)\(\s*\$?\{?\[?\s*\$\w+->id\b' \
    "app/"

# Rule 4 — whereBelongsTo for conventional FKs (the common, safe-inference case).
# Matches both static Model::where() and chained ->where() call sites.
scan "R4-where" \
    '(?:->|::)where\(\s*\x27(user_id|game_id|campaign_id|event_id|location_id|team_id|ticket_id|game_system_id|gm_profile_id|short_link_id)\x27\s*,\s*\$\w+->(id|getKey\(\))\)' \
    "app/"

# Rule 5 — compare models with is()/isNot(), not ->id ===/==/!= ->id.
# Covers both strict (===, !==) and loose (==, !=) ID comparisons; the latter
# are strictly worse (no type safety) so they are never acceptable either.
scan "R5-compare" \
    '->id\s*[!=]==?\s*\$\w+->id' \
    "app/"

# Rule 6 — whereBelongsTo for owner_id too (positive comparisons only). The
# relation is non-conventional so the 2nd 'owner' arg is required. Negative
# where('owner_id','!=',...) and primitive where('owner_id',$userId) have no
# model-aware equivalent and are intentionally out of scope.
scan "R6-owner" \
    '(?:->|::)where\(\s*\x27owner_id\x27\s*,\s*\$\w+->(id|getKey\(\))\)' \
    "app/"

# Rule 7 — morphMap integrity: User and Location must never be aliased.
check_morph_map "app/Providers/AppServiceProvider.php"

# ── Tests — REPORTED only (not fully migrated) ──────────────────────────────
printf "\n${CYN}Tests (informational — not gating):${RST}\n"
scan "R4-where" \
    '->where\(\s*\x27(user_id|game_id|campaign_id)\x27\s*,\s*\$\w+->(id|getKey\(\))\)' \
    "tests/"
scan_ml "R2-create" \
    '::(create|firstOrCreate|firstOrNew|updateOrCreate)\(\[[^]]*?\w+_id\x27 => \$\w+->(id|getKey\(\))' \
    "tests/"

# ── Verdict ──────────────────────────────────────────────────────────────────
printf "\n"
if [[ "$prod_hits" -gt 0 ]]; then
    printf "${RED}❌ FAIL:${RST} ${prod_hits} production-code violation(s) of the Eloquent baseline.\n"
    printf "   See CONTRIBUTING.md → \"Eloquent Best Practices\". Use the model-aware API.\n"
    exit 1
fi
printf "${GRN}✅ PASS:${RST} no production-code violations"
if [[ "$test_hits" -gt 0 ]]; then
    printf " (${YLW}${test_hits} test-only hits, informational${RST})"
fi
printf ".\n"
