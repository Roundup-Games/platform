#!/usr/bin/env bash
#
# Pre-push hook: full test suite — the local mirror of CI's Tests (Pest) job.
#
# Why this exists (2026-10-15): the pre-commit hook gates the STATIC CI jobs
# (pint, phpstan, practices, invariants, i18n) in seconds, but Pest was
# deliberately left out of it — the full suite takes ~15 minutes, far too
# slow per-commit. That left exactly one window for a red push: a test
# outside the suites you happened to run locally (the sitemap count pin in
# GuestRouteHygieneTest shipped red through it). Pushes are far less
# frequent than commits, so the full suite belongs HERE.
#
# Mirrors CI exactly: APP_DEBUG=true (CI's .env.example copy sets it —
# Livewire snapshot corruption only surfaces under debug; local .env
# typically has it false) and --parallel. Since M075 the suite is two-phase
# (parallel minus the ddl-serial group + a serial DDL tail) — mirroring
# CI's two Run tests steps.
#
# Install: composer install-hooks
# Bypass:  git push --no-verify   (use sparingly — you are opting into CI red)
set -euo pipefail

RED='\033[0;31m'
GRN='\033[0;32m'
CYN='\033[0;36m'
RST='\033[0m'

# Ref-agnostic gate: the remote + pushed-tree identity decide (skip guard
# below); the checked-out branch is display-only. Tag pushes are NOT special:
# a tag whose target is the gated origin/main tree skips, any other tag gates.
remote="$1"
branch="$(git rev-parse --abbrev-ref HEAD 2>/dev/null || echo unknown)"
if [[ "$branch" == "HEAD" ]]; then
    branch="detached"
fi

# Skip-on-identical-tree for homelab pushes (M075): homelab is the deploy
# remote (K012) and every homelab push follows an origin push of the same
# tree that just passed this exact gate — re-running the ~6-13 min suite on
# a byte-identical tree is pure redundant gating (~45 min measured
# 2026-11-07). When every ref this homelab push delivers is exactly the
# tree origin/main already gated green, skip the suite and let the push
# through: origin pushes always gate, and a homelab push of a tree
# origin/main has not seen gates as before.
#
# The predicate reads the PUSHED REFSPEC from stdin ("<local_ref> <local_sha>
# <remote_ref> <remote_sha>" — the same lines the server-side post-receive
# deploys on), NOT the checked-out HEAD: from an up-to-date main checkout,
# `git push homelab feature-x:main` delivers a tree origin/main never gated,
# and a HEAD-based skip would wave it through to the deploy hook (review
# hardening 2026-11-08). Ref deletions push the all-zero sha, which never
# equals a real origin/main sha — they always gate. An unresolvable ref
# (fresh clone, no origin/main) never compares equal — a failing rev-parse
# echoes its argument instead of a SHA — so the guard cannot misfire;
# || true keeps a failing rev-parse from tripping set -e. A no-ref
# invocation (empty stdin) never skips: nothing was proven gated, so the
# suite runs, preserving the pre-2026-11-08 behavior.
origin_main_sha="$(git rev-parse origin/main 2>/dev/null || true)"
# Capture the pushed refspecs once — the homelab skip guard and the M077
# path gates (below) both consume them; stdin can only be read once.
mapfile -t pushed_lines
if [[ "$remote" == "homelab" && -n "$origin_main_sha" ]]; then
    pushed_shas=()
    for line in "${pushed_lines[@]}"; do
        read -r _local_ref local_sha _remote_ref _remote_sha <<< "$line"
        [[ -n "$local_sha" ]] && pushed_shas+=("$local_sha")
    done

    # Skip only when at least one ref is pushed and EVERY pushed tree is
    # the gated origin/main tree (multi-ref pushes need all-ref identity).
    if [[ "${#pushed_shas[@]}" -gt 0 ]]; then
        all_gated=1
        for sha in "${pushed_shas[@]}"; do
            if [[ "$sha" != "$origin_main_sha" ]]; then
                all_gated=0
                break
            fi
        done

        if [[ "$all_gated" == 1 ]]; then
            printf "\n${CYN}⏭️ Pre-push skip: %s push of %s is the identical tree already gated on origin/main — full suite skipped.${RST}\n\n" "$remote" "$branch"
            exit 0
        fi
    fi
fi

# M077/S04 path gates — mirror ci.yml exactly:
#   ddl      database/migrations | database/schema changes → run the DDL tail
#   deferred nightly-group app-side paths changed → run the nightly group
# Both fail SAFE: an empty or unresolvable ref list gates ON (run the tests).
paths_changed_in_push() {
    # $1: pathspec args…; returns 0 (true) when any pushed range touches them
    local patterns=("$@")
    [[ "${#pushed_lines[@]}" -gt 0 ]] || return 0
    local line local_sha remote_sha
    for line in "${pushed_lines[@]}"; do
        read -r _local_ref local_sha _remote_ref remote_sha <<< "$line"
        [[ -n "$local_sha" && -n "$remote_sha" ]] || return 0
        if [[ "$remote_sha" =~ ^0+$ ]]; then return 0; fi   # new branch — fail safe
        if git diff --quiet "$remote_sha" "$local_sha" -- "${patterns[@]}" 2>/dev/null; then
            :   # this ref touches nothing — keep checking other refs
        else
            return 0
        fi
    done
    return 1
}

ddl_paths=(database/migrations database/schema tests/Feature/Migrations)
deferred_paths=(
    app/Console/Dev
    app/Services/BggSyncService.php
    app/Services/BggClient.php
    app/Services/BggXmlParser.php
    app/Services/GameSystemRequestService.php
    # routes/console.php carries the bgg:weekly-sync closure (no command
    # class exists) and the Schedule definitions ScheduledTasksPage renders.
    routes/console.php
    app/Filament
    app/Providers/Filament
    app/Support/Scheduling
    resources/views/filament
    tests/Feature/Console/DemoSeedCommandTest.php
    tests/Feature/Console/DemoEnvironmentGuardTest.php
    tests/Feature/Badges/DemoCohortProofTest.php
    tests/Feature/Services/BggSyncTest.php
    tests/Feature/Services/GameSystemRequestServiceBggSyncTest.php
    tests/Feature/Services/BggSyncServiceTaxonomyTest.php
    tests/Feature/Console/BggWeeklySyncTest.php
    tests/Feature/Filament/AdminResourceSmokeTest.php
    tests/Feature/Filament/SignupAttributionUatTest.php
    tests/Feature/Filament/ScheduledTasksPageTest.php
    # tests/Pest.php carries the nightly-group bindings — a membership change
    # (new deferred file) must run the group at push time, not wait for cron.
    tests/Pest.php
)

run_ddl_tail=0
paths_changed_in_push "${ddl_paths[@]}" && run_ddl_tail=1
run_deferred=0
paths_changed_in_push "${deferred_paths[@]}" && run_deferred=1

printf "\n${CYN}🧪 Pre-push: full test suite (CI Tests-job mirror) on %s → %s${RST}\n" "$branch" "$remote"
printf "    This takes a while — the same minutes CI would spend finding it for you.\n"
[[ "$run_ddl_tail" == 1 ]] || printf "    ⏭️ DDL serial tail skipped (no database/migrations|schema changes in this push).\n"
[[ "$run_deferred" == 1 ]] && printf "    ➕ Deferred (nightly-group) tests triggered by path changes in this push.\n"
printf "\n"

if [[ ! -x vendor/bin/pest ]]; then
    printf "${RED}⛔ vendor/bin/pest not found — run composer install.${RST}\n\n"
    exit 1
fi

start=$(date +%s)
suite_log="$(mktemp "${TMPDIR:-/tmp}/prepush-suite.XXXXXX")"

# Push-transport hardening (2026-10-26 incident): git connects to the
# remote BEFORE running this hook; a long suite leaves the ssh session
# idle and github.com closes it ("Connection closed by remote host") —
# the subsequent pack write EPIPEs and `git push` dies with exit 141
# AFTER a green suite, every time. The companion fix is ssh keepalives
# for github.com (ServerAliveInterval in ~/.ssh/config). Output is also
# captured to a file with stdin detached so orphaned --parallel workers
# can never touch git's pipes — belt and suspenders.
# Two-phase suite (M075): tests/Feature/Migrations (the 'ddl-serial' group,
# bound in tests/Pest.php) runs REAL DDL that deadlocks sibling --parallel
# workers as bystander victims (SQLSTATE 40P01 — three gated-push blocks
# the week of 2026-11-01). The parallel phase EXCLUDES that group; a serial
# tail after a green parallel phase runs it alone. Both phases must be
# green to push — a red tail falls through to the same failure-reporting
# path below as a red parallel phase (its output is appended to the same
# suite log). The stdin-detached redirect pattern is kept on BOTH
# invocations: orphaned parallel workers must never touch git's pipes.
# Two-phase suite (M075) + nightly deferral (M077/S04): the parallel phase
# excludes 'ddl-serial' AND the 'nightly' group (demo-env proofs, BGG
# integration, Filament UAT walkthroughs — bound in tests/Pest.php). The
# DDL tail runs only when this push changes database/migrations|schema
# (fail-safe: unresolvable refs run it); the deferred nightly group runs
# only when its app-side paths changed (same fail-safe). Both extra phases
# append to the same suite log; any red phase blocks the push.
suite_green=1
if APP_DEBUG=true vendor/bin/pest --ci --parallel --compact --exclude-group=ddl-serial,nightly \
    > "$suite_log" 2>&1 < /dev/null; then
    :
else
    suite_green=0
fi

if [[ "$suite_green" == 1 && "$run_ddl_tail" == 1 ]]; then
    vendor/bin/pest --ci --compact --group=ddl-serial >> "$suite_log" 2>&1 < /dev/null || suite_green=0
fi

if [[ "$suite_green" == 1 && "$run_deferred" == 1 ]]; then
    vendor/bin/pest --ci --compact --group=nightly >> "$suite_log" 2>&1 < /dev/null || suite_green=0
fi

if [[ "$suite_green" == 1 ]]; then
    end=$(date +%s)
    grep -aE "Tests:|Duration" "$suite_log" | tail -2
    printf "\n${GRN}✅ Suite green (%d min) — pushing.${RST}\n\n" $(( (end - start) / 60 ))
    rm -f "$suite_log"
    exit 0
fi

printf "\n${RED}⛔ Test suite failed — push blocked (CI's Tests job fails on this too).${RST}\n"
printf "${RED}   Fix locally and push again, or bypass once with: git push --no-verify${RST}\n\n"
printf "    Last 60 lines of the suite log (%s):\n\n" "$suite_log"
tail -n 60 "$suite_log"
rm -f "$suite_log"
exit 1
