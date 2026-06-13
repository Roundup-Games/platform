#!/usr/bin/env bash
#
# Pre-commit hook: quality gate for staged files.
#
# Checks:
#   1. Secret detection (gitleaks)
#   2. PHP syntax check (php -l) on staged PHP files
#   3. PHP code style (Pint) on staged PHP files
#   4. PHP static analysis (Larastan) on app/ changes
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
skipped=0

# ── Helpers ──────────────────────────────────────────────────────────────────
step()  { printf "  ${CYN}⏳ %-8s${RST} %s\n" "$1" "$2"; }
ok()    { printf "  ${GRN}✅ %-8s${RST} %s\n" "$1" "$2"; }
warn()  { printf "  ${YLW}⚠️  %-8s${RST} %s\n" "$1" "$2"; skipped=1; }
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

# ── 1. Secret detection ─────────────────────────────────────────────────────
if command -v gitleaks &>/dev/null; then
    step "secrets" "gitleaks detect"
    if gl_out=$(gitleaks protect --staged --no-banner 2>&1); then
        ok "secrets" "clean"
    else
        fail "secrets" "potential secrets found"
        echo "$gl_out" | sed 's/^/         /'
    fi
else
    warn "secrets" "gitleaks not installed → brew install gitleaks"
fi

# ── 2. PHP syntax check ─────────────────────────────────────────────────────
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

# ── 3. PHP code style (Pint) ────────────────────────────────────────────────
step "style" "pint --test"
if pint_out=$(echo "$staged_php" | xargs vendor/bin/pint --test 2>&1); then
    ok "style" "pint"
else
    fail "style" "pint found issues"
    echo "$pint_out" | sed 's/^/         /'
    echo ""
    echo "         Fix: vendor/bin/pint                  (auto-fix)"
    echo "              vendor/bin/pint --test -v        (preview diff)"
fi

# ── 4. PHP static analysis (Larastan) ───────────────────────────────────────
#
# Larastan analyses the whole codebase (needs full type context), so we gate
# it on app/config/database changes to avoid running on test-only commits.
#
has_app=$(echo "$staged_php" | grep -cE '^(app|config|database)/' || true)
if [[ "$has_app" -gt 0 ]]; then
    step "stan" "larastan (level 8)"
    if stan_out=$(vendor/bin/phpstan analyse --no-progress --memory-limit=512M 2>&1); then
        ok "stan" "larastan"
    else
        fail "stan" "new static analysis errors"
        echo "$stan_out" | tail -30 | sed 's/^/         /'
    fi
else
    warn "stan" "skipped (no app/config/database changes)"
fi

# ── Summary ──────────────────────────────────────────────────────────────────
echo ""
if [[ "$failed" -ne 0 ]]; then
    printf "${RED}⛔ Pre-commit checks failed. Fix above or commit with --no-verify.${RST}\n\n"
    exit 1
fi

if [[ "$skipped" -ne 0 ]]; then
    printf "${GRN}✅ All checks passed${RST} ${YLW}(some steps skipped)${RST}\n\n"
else
    printf "${GRN}✅ All checks passed.${RST}\n\n"
fi
