#!/usr/bin/env bash
#
# Pre-commit hook: quality gate for staged files.
#
# Policy:
# 1. Secret detection (gitleaks)     → HARD FAIL   (never commit secrets)
#   2. PHP syntax check (php -l)        → HARD FAIL   (unparseable code)
#   3. PHP code style (Pint)            → AUTO-FIX    (fixes + re-stages; HARD FAILS if anything remains unfixable)
#      …applied to all staged PHP EXCEPT lang/ — Weblate owns that
#      serialization (see pint.json exclude); Pint on lang/ re-adds the
#      blank line after <?php that Weblate strips, so it must never run there.
#   4. Eloquent practices + invariants  → HARD FAIL   (cheap greps, CI gates)
#   5. Translation integrity            → HARD FAIL   (when lang/ is staged)
#   6. PHP static analysis (Larastan)   → HARD FAIL   (same rule set as CI)
#
# Goal: "green locally" == "green on CI" for every static-analysis job.
# Pest and the JS build remain CI-side (too slow per-commit) — run
# `composer ci-mirror` for the full static mirror, `composer smoke` for
# the fast test signal, and the full suite before deploying.
#
# Auto-fix re-staging skips files that ALSO have unstaged (partial) changes —
# re-adding those would sweep the unstaged hunks into the commit, so the fix
# is left in the working tree with a warning to re-stage deliberately.
#
# Install: composer install-hooks
# Bypass:  git commit --no-verify
#
# Closeout mode (--stage):
#   scripts/git/pre-commit.sh --stage
# Stages the working tree (git add -A) BEFORE the checks, so the full chain
# — gitleaks (staged scan), pint (staged pathspec), php -l — covers
# uncommitted work. Used by GSD's verification_commands gate: the agent
# runs the exact CI-parity chain at closeout time, where it still has the
# tools and context to fix failures, instead of failing the orchestrator's
# commit later with no remediation path (exit 10 pause).

set -eo pipefail

# ── Colours ──────────────────────────────────────────────────────────────────
RED='\033[0;31m'
GRN='\033[0;32m'
YLW='\033[0;33m'
CYN='\033[0;36m'
RST='\033[0m'

failed=0
warned=0
skipped=0

# ── Closeout mode (--stage) ──────────────────────────────────────────
# Stage first so the index-driven checks (gitleaks --staged, pint pathspec,
# php -l file list) see the working tree. `.gsd/` and other ignores are
# respected by `git add -A`; an already-clean tree is a no-op.
if [[ "${1:-}" == "--stage" ]]; then
    if ! git diff --quiet || ! git diff --cached --quiet \
        || [[ -n "$(git ls-files --others --exclude-standard)" ]]; then
        git add -A
        printf "  ${CYN}📦 %-8s${RST} %s\n" "stage" "working tree staged for the CI-parity gate"
    fi
elif [[ -n "${1:-}" ]]; then
    printf "usage: scripts/git/pre-commit.sh [--stage]\n" >&2
    exit 1
fi

# ── Helpers ──────────────────────────────────────────────────────────────────
step()  { printf "  ${CYN}⏳ %-8s${RST} %s\n" "$1" "$2"; }
ok()    { printf "  ${GRN}✅ %-8s${RST} %s\n" "$1" "$2"; }
warn()  { printf "  ${YLW}⚠️  %-8s${RST} %s\n" "$1" "$2"; warned=1; }
skip()  { printf "  ${YLW}⏭️  %-8s${RST} %s\n" "$1" "$2"; skipped=1; }
fail()  { printf "  ${RED}❌ %-8s${RST} %s\n" "$1" "$2"; failed=1; }

# ── Commit signature ───────────────────────────────────────────────────────
# All commits on this repo are cryptographically signed (SSH signing keys
# since 2026-10). Signing must be unconditional — commit.gpgsign=true — so
# the guarantee does not depend on remembering -S. Hard fail with the fix
# in the message; `git commit --no-verify` remains the documented emergency
# bypass (see the hook header).
gpgsign=$(git config --get commit.gpgsign || true)
if [[ "$gpgsign" != "true" ]]; then
    printf "  ${RED}❌ signature${RST} commits must be signed: commit.gpgsign is not enabled. Fix once per machine:\n"
    printf "         git config --global gpg.format ssh\n"
    printf "         git config --global user.signingkey 'key::ssh-ed25519 AAAA... your-email'\n"
    printf "         git config --global commit.gpgsign true\n"
    printf "         (bypass once with git commit --no-verify)\n\n"
    exit 1
fi
ok "signature" "commit.gpgsign enabled — commit will be signed"

# ── File lists ───────────────────────────────────────────────────────────────
staged_php=$(git diff --cached --name-only --diff-filter=ACM -- '*.php' || true)

# Pint-only list: lang/ is excluded because Weblate owns that serialization
# (see pint.json exclude); Pint on lang/ re-adds the blank line after <?php
# that Weblate strips. php -l below still checks every staged PHP file.
pint_php=$(git diff --cached --name-only --diff-filter=ACM -- '*.php' ':(exclude)lang/' || true)

php_count=$(echo "$staged_php" | grep -c . || true)

printf "\n${CYN}🔍 Pre-commit checks on ${php_count} PHP file(s)${RST}\n\n"

# Bail early if no source files are staged
if [[ -z "$staged_php" ]]; then
    echo "  No source files staged — skipping checks."
    exit 0
fi

# Partially-staged PHP files (staged AND with unstaged changes). Auto-fix will
# skip re-staging these to avoid merging the unstaged hunks into the commit.
# Intersected with the Pint list — only Pint-eligible files matter here.
partially_staged=$(git diff --name-only -- '*.php' \
    | grep -Fxf <(printf '%s\n' "$pint_php") || true)

# ── 1. Secret detection — HARD FAIL ──────────────────────────────────────────
if command -v gitleaks &>/dev/null; then
    step "secrets" "gitleaks detect"
    if gl_out=$(gitleaks protect --staged --no-banner 2>&1); then
        ok "secrets" "clean"
    else
        fail "secrets" "potential secrets found — commit blocked"
        echo "$gl_out" | sed 's/^/         /'
    fi
else
    skip "secrets" "gitleaks not installed → brew install gitleaks"
fi

# ── 2. PHP syntax check — HARD FAIL ──────────────────────────────────────────
step "lint" "php -l"
lint_ok=true
for file in $staged_php; do
    if ! output=$(php -l "$file" 2>&1); then
        fail "lint" "$file"
        echo "  $output" | sed 's/^/         /'
        lint_ok=false
    fi
done
if $lint_ok; then
    ok "lint" "php -l"
fi

# ── 3. PHP code style (Pint) — AUTO-FIX + RE-STAGE ───────────────────────────
if [[ -x vendor/bin/pint ]]; then
    if [[ -z "$pint_php" ]]; then
        skip "style" "no Pint-eligible files staged (lang/ is excluded — Weblate owns its format)"
    else
    step "style" "pint (auto-fix)"
    # Run Pint WITHOUT --test so it applies fixes in place. A non-zero exit
    # here means some issues could not be auto-fixed — warn, don't block.
    if pint_out=$(echo "$pint_php" | xargs vendor/bin/pint 2>&1); then
        ok "style" "pint"
    else
        warn "style" "pint fixed what it could; some issues remain (non-blocking)"
        echo "$pint_out" | sed 's/^/         /'
    fi

    # Re-stage every file Pint modified, EXCEPT partially-staged ones. Git
    # uses the index state at commit-create time, so re-adding here folds
    # the fixes into this commit.
    restaged=0
    while IFS= read -r file; do
        [[ -z "$file" ]] && continue
        # Skip files with staged + unstaged changes — re-staging would merge
        # the unstaged hunks into the commit.
        if [[ -n "$partially_staged" ]] && grep -qxF -- "$file" <<<"$partially_staged"; then
            continue
        fi
        if ! git diff --quiet -- "$file"; then
            if git add -- "$file" 2>/dev/null; then
                restaged=$((restaged + 1))
            else
                warn "style" "could not re-stage $file (fix left in working tree)"
            fi
        fi
    done <<<"$pint_php"
    [[ "$restaged" -gt 0 ]] \
        && printf "  ${GRN}♻️  %-8s${RST} %s\n" "restage" "$restaged file(s) auto-fixed & re-staged"

    # Warn about partially-staged files Pint touched (fix left in working tree).
    if [[ -n "$partially_staged" ]]; then
        while IFS= read -r pfile; do
            [[ -z "$pfile" ]] && continue
            if ! git diff --quiet -- "$pfile"; then
                warn "style" "$pfile has staged + unstaged changes; fix left in working tree — review & \`git add\` manually"
            fi
        done <<<"$partially_staged"
    fi
    fi
else
    skip "style" "vendor/bin/pint not found — run composer install"
fi

# ── 4. Eloquent practices + platform invariants — HARD FAIL ───────────────
# Cheap greps that CI hard-gates; run them whenever PHP is staged so a
# violation never reaches the push stage.
if practices_out=$(composer --quiet practices 2>&1); then
    ok "eloquent" "best-practices guardrail"
else
    fail "eloquent" "Eloquent best-practices violation — commit blocked"
    echo "$practices_out" | grep -E '❌|hit' | head -10 | sed 's/^/         /'
fi

if bash scripts/check_platform_invariants.sh >/dev/null 2>&1; then
    ok "invariant" "platform invariants"
else
    fail "invariant" "platform invariants violated — commit blocked"
    bash scripts/check_platform_invariants.sh 2>&1 | grep -E 'FAIL|✗' | head -5 | sed 's/^/         /'
fi

# ── 5. Translation integrity — HARD FAIL (lang/ staged) ────────────────────
staged_lang=$(git diff --cached --name-only --diff-filter=ACM -- 'lang/' || true)
if [[ -n "$staged_lang" ]]; then
    if [[ -f .env ]]; then
        if i18n_out=$(php artisan i18n:check 2>&1); then
            ok "i18n" "translation parity and integrity"
        else
            fail "i18n" "i18n:check found issues — commit blocked"
            echo "$i18n_out" | tail -12 | sed 's/^/         /'
        fi
    else
        skip "i18n" "no .env — artisan unavailable (CI still gates this)"
    fi
else
    skip "i18n" "skipped (no lang/ changes)"
fi

# ── 6. PHP static analysis (Larastan) — HARD FAIL ───────────────────────
#
# Larastan analyses the whole codebase (needs full type context), so we gate
# it on app/config/database changes to avoid running on test-only commits
# (phpstan.neon analyses app/ only). Same rule set and memory limit as CI;
# a failure blocks the commit exactly like it fails CI.
#
has_app=$(echo "$staged_php" | grep -cE '^(app|config|database)/' || true)
if [[ -x vendor/bin/phpstan ]]; then
    if [[ "$has_app" -gt 0 ]]; then
        step "stan" "larastan (hard fail — CI parity)"
        if stan_out=$(vendor/bin/phpstan analyse --no-progress --memory-limit=512M 2>&1); then
            ok "stan" "larastan"
        else
            fail "stan" "static analysis found issues — commit blocked (CI fails on these)"
            echo "$stan_out" | tail -30 | sed 's/^/         /'
        fi
    else
        skip "stan" "skipped (no app/config/database changes)"
    fi
else
    skip "stan" "vendor/bin/phpstan not found — run composer install"
fi

# ── Summary ──────────────────────────────────────────────────────────────────
echo ""
if [[ "$failed" -ne 0 ]]; then
    printf "${RED}⛔ Pre-commit blocked — fix the failures above (CI gates them too). Bypass: git commit --no-verify.${RST}\n\n"
    exit 1
fi

if [[ "$warned" -ne 0 ]]; then
    printf "${GRN}✅ Commit allowed${RST} ${YLW}(non-blocking warnings above — review before pushing)${RST}\n\n"
elif [[ "$skipped" -ne 0 ]]; then
    printf "${GRN}✅ All checks passed${RST} ${YLW}(some steps skipped)${RST}\n\n"
else
    printf "${GRN}✅ All checks passed.${RST}\n\n"
fi
