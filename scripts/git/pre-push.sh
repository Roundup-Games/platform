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

# Only run for branch pushes (e.g. main); tag pushes skip.
remote="$1"
branch="$(git rev-parse --abbrev-ref HEAD 2>/dev/null || echo unknown)"
if [[ "$branch" == "HEAD" ]]; then
    branch="detached"
fi

# Skip-on-identical-tree for homelab pushes (M075): homelab is the deploy
# remote (K012) and every homelab push follows an origin push of the same
# tree that just passed this exact gate — re-running the ~6-13 min suite on
# a byte-identical tree is pure redundant gating (~45 min measured
# 2026-11-07). When this homelab push is exactly the tree origin/main
# already gated green, skip the suite and let the push through: origin
# pushes always gate, and a homelab push of a tree origin/main has not
# seen gates as before. An unresolvable ref (fresh clone, no origin/main)
# never compares equal — a failing rev-parse echoes its argument instead
# of a SHA — so the guard cannot misfire; || true keeps a failing
# rev-parse from tripping set -e.
head_sha="$(git rev-parse HEAD 2>/dev/null || true)"
origin_main_sha="$(git rev-parse origin/main 2>/dev/null || true)"
if [[ "$remote" == "homelab" && -n "$origin_main_sha" && "$head_sha" == "$origin_main_sha" ]]; then
    printf "\n${CYN}⏭️ Pre-push skip: %s push of %s is the identical tree already gated on origin/main — full suite skipped.${RST}\n\n" "$remote" "$branch"
    exit 0
fi

printf "\n${CYN}🧪 Pre-push: full test suite (CI Tests-job mirror) on %s → %s${RST}\n" "$branch" "$remote"
printf "    This takes a while — the same minutes CI would spend finding it for you.\n\n"

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
if APP_DEBUG=true vendor/bin/pest --ci --parallel --compact --exclude-group=ddl-serial \
    > "$suite_log" 2>&1 < /dev/null \
    && vendor/bin/pest --ci --compact --group=ddl-serial >> "$suite_log" 2>&1 < /dev/null; then
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
