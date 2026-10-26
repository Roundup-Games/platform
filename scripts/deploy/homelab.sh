#!/usr/bin/env bash
#
# Homelab pull-deploy for roundup.games — runs ON the unRAID host.
#
# Hard rules encoded here:
#   - K009: MUST run as deploy@ (refuses root). Never deploy as root.
#   - Working-tree drift check: the checkout must be clean (deploy-drift
#     trap — mixed images ship when the tree has local edits).
#
# Pipeline: git push homelab main (updateInstead) → CI images.yml has
# pushed ghcr.io/roundup-games/platform[-worker]:sha-<short> → run this
# script. It pulls the pinned images, rolls the stack, gates on container
# health, smoke-tests public routes, and logs everything with timestamps
# under deploy-logs/ (gitignored — the tree stays clean).
#
# Rollback: DEPLOY_TAG=sha-<previous> docker compose up -d
#
# Usage: bash scripts/deploy/homelab.sh
set -euo pipefail

REPO_DIR="${REPO_DIR:-/mnt/user/appdata/roundup-games-laravel}"
LOG_DIR="${LOG_DIR:-$REPO_DIR/deploy-logs}"
APP_URL="${APP_URL:-https://roundup.games}"
HEALTH_TIMEOUT="${HEALTH_TIMEOUT:-300}"

log() { printf '[%s] %s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$*"; }

[[ $(id -un) == "deploy" ]] || {
    echo "REFUSING: this script must run as deploy@ (K009), not $(id -un)." >&2
    exit 2
}

cd "$REPO_DIR"
mkdir -p "$LOG_DIR"
LOG="$LOG_DIR/deploy-$(date -u +%Y%m%dT%H%M%SZ).log"
exec > >(tee -a "$LOG") 2>&1

TAG="sha-$(git rev-parse --short=7 HEAD)"
log "deploy start: HEAD=$(git rev-parse HEAD) image-tag=$TAG"
log "log file: $LOG"

dirty=$(git status --porcelain | wc -l)
if [[ "$dirty" -ne 0 ]]; then
    log "ABORT: $dirty dirty file(s) in the checkout — resolve drift before deploying."
    git status --short | head -10
    exit 1
fi

prev_app=$(docker inspect roundup-games --format '{{.Image}}' 2>/dev/null || echo none)
prev_worker=$(docker inspect roundup-games-worker --format '{{.Image}}' 2>/dev/null || echo none)
log "previous images: app=$prev_app worker=$prev_worker"

export DEPLOY_TAG="$TAG"
log "pulling images for $TAG (if this fails with 'manifest unknown', the Images workflow for this commit has not finished — check gh run list)"
docker compose pull app worker

log "rolling containers"
docker compose up -d

deadline=$(( $(date +%s) + HEALTH_TIMEOUT ))
while :; do
    app_h=$(docker inspect roundup-games --format '{{.State.Health.Status}}' 2>/dev/null || echo missing)
    worker_h=$(docker inspect roundup-games-worker --format '{{.State.Health.Status}}' 2>/dev/null || echo missing)
    log "health: app=$app_h worker=$worker_h"
    if [[ "$app_h" == "healthy" && "$worker_h" == "healthy" ]]; then
        break
    fi
    if (( $(date +%s) >= deadline )); then
        log "TIMEOUT: containers not healthy after ${HEALTH_TIMEOUT}s — dumping diagnostics."
        docker ps --filter name=roundup --format '{{.Names}} {{.Status}}'
        log "--- last app log lines:"
        docker logs roundup-games --tail 40 2>&1 | tail -15
        log "ROLLBACK: DEPLOY_TAG=<older-sha> docker compose up -d (previous image ids are logged above)"
        exit 1
    fi
    sleep 10
done

for path in up en; do
    code=$(curl -s -o /dev/null -w '%{http_code}' --max-time 15 "$APP_URL/$path")
    if [[ "$code" != "200" ]]; then
        log "SMOKE FAIL: $APP_URL/$path -> $code"
        exit 1
    fi
    log "smoke: /$path -> $code"
done

log "deploy complete: $TAG is live and healthy."
log "rollback if needed: DEPLOY_TAG=sha-<previous> docker compose up -d"
