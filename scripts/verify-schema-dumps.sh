#!/usr/bin/env bash
#
# Verify the committed schema dumps are CI-clean (mirrors the two schema
# verification steps of the Static Analysis CI job).
#
# 1. larastan-schema.sql must be in sync with the squashed baseline —
#    catches drift after a re-squash (schema:dump --prune) where the
#    Larastan-compatible schema was not regenerated. Incremental
#    migrations don't need this: Larastan stacks them on the baseline.
# 2. pgsql-schema.sql must be normalized for the postgres:16 CI/deploy
#    target: pg_dump 17+ emits \restrict/\unrestrict meta-commands and
#    SET transaction_timeout, which break or noisy-fail a fresh load.
#    See the re-squash runbook in database/schema/README.md.
#
# Usage: bash scripts/verify-schema-dumps.sh
set -euo pipefail

fail() { printf "❌ %s\n" "$1" >&2; exit 1; }

php scripts/generate-larastan-schema.php

if ! git diff --exit-code --quiet database/schema/larastan-schema.sql; then
    git --no-pager diff --stat database/schema/larastan-schema.sql >&2
    fail "database/schema/larastan-schema.sql is out of sync — regenerate it (run this script, then commit the regenerated file) after a re-squash."
fi

if grep -nE '^\\(un)?restrict |SET transaction_timeout' database/schema/pgsql-schema.sql; then
    fail "database/schema/pgsql-schema.sql contains pg_dump 17+ artifacts that break loading on postgres:16 — strip them per database/schema/README.md."
fi

printf "✅ schema dumps verified (larastan-schema in sync, pgsql-schema normalized)\n"
