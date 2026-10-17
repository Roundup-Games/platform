#!/usr/bin/env bash
# Shared per-worktree setup for roundup-games-laravel.
#
# ONE source of truth for BOTH worktree factories:
#   - Orca: orca.yaml scripts.setup      -> runs full mode (blocking, at creation)
#   - GSD:  git.worktree_post_create     -> runs --fast (<30s hook budget);
#                                            heavy steps continue in background
#
# Modes:
#   (default)  full setup: composer deps, .env, assets, git hooks, MCP seed
#   --fast     fast steps only (.env, MCP seed), then full setup continues in
#              the background (log: .worktree-setup.log in the worktree root)
#   --heavy    dependency/asset steps only (used internally by --fast)
#
# GSD's post-create hook provides SOURCE_DIR (main checkout) and
# WORKTREE_DIR; the script always operates on the current working directory.
#
# Deliberately does NOT run migrations: worktrees share nothing with the main
# checkout's dev database. Tests boot their own throwaway Postgres
# testcontainer (tests/bootstrap.php), exactly like CI.
set -euo pipefail

MODE="${1:-full}"

log() { echo "[worktree-setup:$MODE] $*"; }

need_env() {
  # .env from example with a freshly generated APP_KEY. Pure PHP, no
  # framework boot, so it also works before composer install has run.
  if [ -f .env ]; then
    return 0
  fi
  if [ ! -f .env.example ]; then
    log "WARNING: no .env.example present, skipping .env creation"
    return 0
  fi
  php -r '
    copy(".env.example", ".env");
    $key = "APP_KEY=base64:" . base64_encode(random_bytes(32));
    $env = file_get_contents(".env");
    $updated = preg_replace("/^APP_KEY=$/m", $key, $env, 1, $count);
    if ($count === 0) { $updated = rtrim($env) . "\n" . $key . "\n"; }
    file_put_contents(".env", $updated);
  '
  log ".env created with generated APP_KEY"
}

seed_mcp() {
  # Seed laravel-boost MCP into .mcp.json (machine-generated, git-ignored).
  # /gsd later merges the gsd-workflow/gsd-browser servers in and preserves
  # this one. Existing servers are never overwritten.
  node -e "const fs=require('fs');let d={};try{d=JSON.parse(fs.readFileSync('.mcp.json'))}catch{};d.mcpServers={...d.mcpServers,'laravel-boost':{type:'stdio',command:'php',args:['artisan','boost:mcp']}};fs.writeFileSync('.mcp.json',JSON.stringify(d,null,2)+'\n')"
  log ".mcp.json seeded with laravel-boost"
}

heavy_steps() {
  log "composer install (dev deps: pest, phpstan, pint)"
  composer install --no-interaction
  need_env
  log "frontend assets"
  npm ci
  npm run build
  log "git hooks (pre-commit static gates + pre-push full suite)"
  composer install-hooks
}

case "$MODE" in
  --fast)
    need_env
    seed_mcp
    # Heavy steps cannot fit GSD's 30s post-create hook budget: continue in
    # the background so the worktree is fully provisioned by the time agent
    # execution starts. Non-fatal by design (hook failures are logged, never
    # block the worktree).
    nohup bash "$0" --heavy >.worktree-setup.log 2>&1 &
    log "heavy setup running in background (see .worktree-setup.log)"
    ;;
  --heavy)
    heavy_steps
    seed_mcp
    log "done — composer ci-mirror / smoke are the quick gates"
    ;;
  *)
    heavy_steps
    seed_mcp
    log "done — composer ci-mirror / smoke are the quick gates"
    ;;
esac
