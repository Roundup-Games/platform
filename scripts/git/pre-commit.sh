#!/usr/bin/env bash
#
# Pre-commit hook: quality gate for staged files.
#
# Policy:
#   1. Secret detection (gitleaks)     → HARD FAIL   (never commit secrets)
#   2. PHP syntax check (php -l)        → HARD FAIL   (unparseable code)
#   3. PHP code style (Pint)            → AUTO-FIX    (fixes + re-stages; warns if anything remains)
#   4. PHP static analysis (Larastan)   → WARN ONLY   (informs, never blocks)
#
# Auto-fix re-staging skips files that ALSO have unstaged (partial) changes —
# re-adding those would sweep the unstaged hunks into the commit, so the fix
# is left in the working tree with a warning to re-stage deliberately.
#
# Install: composer install-hooks
# Bypass:  git commit --no-verify

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

# ── Helpers ──────────────────────────────────────────────────────────────────
step()  { printf "  ${CYN}⏳ %-8s${RST} %s\n" "$1" "$2"; }
ok()    { printf "  ${GRN}✅ %-8s${RST} %s\n" "$1" "$2"; }
warn()  { printf "  ${YLW}⚠️  %-8s${RST} %s\n" "$1" "$2"; warned=1; }
skip()  { printf "  ${YLW}⏭️  %-8s${RST} %s\n" "$1" "$2"; skipped=1; }
fail()  { printf "  ${RED}❌ %-8s${RST} %s\n" "$1" "$2"; failed=1; }

# ── File lists ───────────────────────────────────────────────────────────────
staged_php=$(git diff --cached --name-only --diff-filter=ACM -- '*.php' || true)

php_count=$(echo "$staged_php" | grep -c . || true)

printf "\n${CYN}🔍 Pre-commit checks on ${php_count} PHP file(s)${RST}\n\n"

# Bail early if no source files are staged
if [[ -z "$staged_php" ]]; then
    echo "  No source files staged — skipping checks."
    exit 0
fi

# Partially-staged PHP files (staged AND with unstaged changes). Auto-fix will
# skip re-staging these to avoid merging the unstaged hunks into the commit.
partially_staged=$(git diff --name-only -- '*.php' \
    | grep -Fxf <(printf '%s\n' "$staged_php") || true)

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
    step "style" "pint (auto-fix)"
    # Run Pint WITHOUT --test so it applies fixes in place. A non-zero exit
    # here means some issues could not be auto-fixed — warn, don't block.
    if pint_out=$(echo "$staged_php" | xargs vendor/bin/pint 2>&1); then
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
    done <<<"$staged_php"
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
else
    skip "style" "vendor/bin/pint not found — run composer install"
fi

# ── 4. PHP static analysis (Larastan) — WARN ONLY ────────────────────────────
#
# Larastan analyses the whole codebase (needs full type context), so we gate
# it on app/config/database changes to avoid running on test-only commits.
# Issues are reported as warnings — they inform but never block the commit.
#
has_app=$(echo "$staged_php" | grep -cE '^(app|config|database)/' || true)
if [[ -x vendor/bin/phpstan ]]; then
    if [[ "$has_app" -gt 0 ]]; then
        step "stan" "larastan (warn-only)"
        if stan_out=$(vendor/bin/phpstan analyse --no-progress --memory-limit=512M 2>&1); then
            ok "stan" "larastan"
        else
            warn "stan" "static analysis found issues (non-blocking)"
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
    printf "${RED}⛔ Pre-commit blocked by secrets/syntax errors. Fix above or use --no-verify.${RST}\n\n"
    exit 1
fi

if [[ "$warned" -ne 0 ]]; then
    printf "${GRN}✅ Commit allowed${RST} ${YLW}(non-blocking warnings above — review before pushing)${RST}\n\n"
elif [[ "$skipped" -ne 0 ]]; then
    printf "${GRN}✅ All checks passed${RST} ${YLW}(some steps skipped)${RST}\n\n"
else
    printf "${GRN}✅ All checks passed.${RST}\n\n"
fi
