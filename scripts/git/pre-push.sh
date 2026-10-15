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
# typically has it false) and --parallel.
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

printf "\n${CYN}🧪 Pre-push: full test suite (CI Tests-job mirror) on %s → %s${RST}\n" "$branch" "$remote"
printf "    This takes a while — the same minutes CI would spend finding it for you.\n\n"

if [[ ! -x vendor/bin/pest ]]; then
    printf "${RED}⛔ vendor/bin/pest not found — run composer install.${RST}\n\n"
    exit 1
fi

start=$(date +%s)
if APP_DEBUG=true vendor/bin/pest --ci --parallel --compact; then
    end=$(date +%s)
    printf "\n${GRN}✅ Suite green (%d min) — pushing.${RST}\n\n" $(( (end - start) / 60 ))
    exit 0
fi

printf "\n${RED}⛔ Test suite failed — push blocked (CI's Tests job fails on this too).${RST}\n"
printf "${RED}   Fix locally and push again, or bypass once with: git push --no-verify${RST}\n\n"
exit 1
