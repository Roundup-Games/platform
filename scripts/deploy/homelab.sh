#!/usr/bin/env bash
#
# Zero-downtime blue/green pull-deploy for roundup.games — runs ON the unRAID
# host as deploy@ (K009).
#
# ═══════════════════════════════════════════════════════════════════════
# ARCHITECTURE
# ═══════════════════════════════════════════════════════════════════════
#
#   Cloudflare Tunnel (remotely managed — never touched by this script)
#     └─> host :8199 → edge (caddy; the tunnel's one stable origin)
#           └─> live app slot: roundup-games-blue | roundup-games-green
#
# A deploy starts the IDLE slot on the new image, health-gates it, flips the
# edge upstream (caddy reload — zero dropped connections), verifies through
# the public URL, then retires the old slot and rolls the worker off the
# critical path. The old app serves traffic until the instant the new one
# takes over; the worker's stop/drain never gates user traffic.
#
# ═══════════════════════════════════════════════════════════════════════
# USAGE
# ═══════════════════════════════════════════════════════════════════════
#   bash scripts/deploy/homelab.sh                 deploy current HEAD
#   bash scripts/deploy/homelab.sh --rollback      redeploy the previous
#                                                  image and flip to it
#   bash scripts/deploy/homelab.sh --rollback sha-<short>
#                                                  rollback to a specific
#                                                  previously-deployed tag
#
# ROLLBACK PROCEDURE (two flavors)
#   1. Immediate flip-back (seconds, app still running): if the OLD slot
#      container still exists (it is retired only AFTER the new one is
#      verified publicly), its edge entry is one edit away:
#        printf ':8199 {\n\treverse_proxy roundup-games-<old>:8080\n}\n' \
#          > edge/Caddyfile
#        docker exec roundup-games-edge caddy reload --config /etc/caddy/Caddyfile
#   2. Full rollback (any time, old container already retired):
#        bash scripts/deploy/homelab.sh --rollback [sha-<short>]
#      Without an explicit tag it redeploys the tag recorded as previous in
#      deploy-logs/slots.json. This starts the idle slot on the old image,
#      health-gates it, flips, verifies, retires the bad slot, and rolls the
#      worker to the same tag.
#
# FIRST RUN (bootstrap, auto-detected): migrates the legacy single-container
#   stack (roundup-games / roundup-games-worker on :8199) to the blue/green
#   topology. It starts GREEN first (no port conflict), then stops the legacy
#   containers, then brings up the edge on :8199 — one deliberate ~5s blip,
#   the last one this pipeline will ever cause. The legacy worker may be
#   wedged by the s6 notification-fd deadlock (issue #637); bootstrap kills
#   it rather than waiting out its 5m grace.
#
# SAFETY
#   - Working-tree drift check: the checkout must be clean.
#   - The flip is never taken unless the candidate is container-healthy AND
#     serving a real page in-container.
#   - Post-flip public verification (through Cloudflare) must return 200 for
#     /up AND /en, or the edge flips back automatically and the deploy fails
#     with the old app still serving.
#   - DEPLOY_TAG is pinned on EVERY compose invocation — nothing ever runs
#     the floating `latest` tag by accident.
#
# Rollback of last resort (images gone/registry down):
#   DEPLOY_TAG=sha-<any> docker compose up -d app worker   (legacy in-place)
#
# Logs: deploy-logs/deploy-<UTC-stamp>.log (+ slots.json slot/tag state).
# ═══════════════════════════════════════════════════════════════════════

set -euo pipefail

REPO_DIR="${REPO_DIR:-/mnt/user/appdata/roundup-games-laravel}"
LOG_DIR="${LOG_DIR:-$REPO_DIR/deploy-logs}"
EDGE_DIR="$REPO_DIR/edge"
EDGE_CONF="$EDGE_DIR/Caddyfile"
APP_URL="${APP_URL:-https://roundup.games}"
EDGE=roundup-games-edge
STATE_FILE="$LOG_DIR/slots.json"

log() { printf '[%s] %s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$*"; }
die() { log "ABORT: $*"; exit 1; }

[[ $(id -un) == "deploy" ]] || die "must run as deploy@ (K009), not $(id -un)."
cd "$REPO_DIR"
mkdir -p "$LOG_DIR" "$EDGE_DIR"
LOG="$LOG_DIR/deploy-$(date -u +%Y%m%dT%H%M%SZ).log"
exec > >(tee -a "$LOG") 2>&1

MODE=deploy
ROLLBACK_TAG="${2:-}"
case "${1:-}" in
    "") ;;
    --rollback) MODE=rollback ;;
    -h|--help) sed -n '2,60p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
    *) die "unknown argument '$1' (see --help)" ;;
esac

# ── Slot helpers ──────────────────────────────────────────────────────────

cname() { echo "roundup-games-$1"; }

live_slot() {  # ground truth = the upstream in the LIVE edge container's config.
    # Only meaningful when the edge is actually running — an aborted
    # bootstrap can leave a stale Caddyfile behind, and treating that as
    # "a live slot" once skipped bootstrap and stopped the legacy app
    # with nothing to replace it (2026-10-29 misfire, ~40s outage).
    docker inspect "$EDGE" >/dev/null 2>&1 || return 1
    grep -oE 'roundup-games-(blue|green)' "$EDGE_CONF" 2>/dev/null | head -1 | sed 's/roundup-games-//' || true
}
other_slot() { [[ "$1" == blue ]] && echo green || echo blue; }

write_edge_conf() {  # $1 = blue|green
    printf ':8199 {\n\treverse_proxy roundup-games-%s:8080\n}\n' "$1" > "$EDGE_CONF"
}
edge_reload() { docker exec "$EDGE" caddy reload --config /etc/caddy/Caddyfile >/dev/null; }

flip_to() {  # $1 = blue|green — rewrite + lossless reload
    write_edge_conf "$1"
    edge_reload
    log "edge flipped: live slot = $1"
}

slot_service() { [[ "$1" == green ]] && echo app-green || echo app; }

compose_for() {  # $1 = blue|green (consumed); remaining args go to docker compose
    local slot="$1"; shift
    if [[ "$slot" == green ]]; then
        docker compose --profile bluegreen "$@"
    else
        docker compose "$@"
    fi
}

wait_healthy() {  # $1 = container, $2 = timeout seconds
    local deadline=$(( $(date +%s) + $2 )) h missing_for=0
    while :; do
        h=$(docker inspect "$1" --format '{{.State.Health.Status}}' 2>/dev/null || echo missing)
        [[ "$h" == healthy ]] && return 0
        # A container that never appeared (compose failure) fails fast
        # instead of burning the whole gate timeout.
        if [[ "$h" == missing ]] && (( ++missing_for > 15 )); then
            log "health gate FAILED: $1 never appeared (start command failed?)"
            return 1
        fi
        if (( $(date +%s) >= deadline )); then
            log "health gate FAILED: $1 status=$h after ${2}s"
            docker logs "$1" --tail 20 2>&1 | sed 's/^/    /'
            return 1
        fi
        sleep 2
    done
}

probe_in_container() {  # $1 = container — a REAL page through its own nginx
    docker exec "$1" curl -fs -o /dev/null --max-time 10 http://127.0.0.1:8080/en
}

verify_public() {  # $1 = path — through Cloudflare, the real user path
    local code
    code=$(curl -s -o /dev/null -w '%{http_code}' --max-time 15 "$APP_URL/$1" 2>/dev/null || echo 000)
    [[ "$code" == "200" ]]
}

verify_public_retry() {  # $1 = path, retries ×3 — Cloudflare edge can lag a flip
    local i
    for i in 1 2 3; do
        verify_public "$1" && return 0
        [[ $i -lt 3 ]] && sleep 5
    done
    return 1
}

save_state() {  # $1 = live slot, $2 = live tag, $3 = previous tag
    printf '{"live":"%s","tag":"%s","prev_tag":"%s","ts":"%s"}\n' \
        "$1" "$2" "$3" "$(date -u +%Y-%m-%dT%H:%M:%SZ)" > "$STATE_FILE"
}

read_state_field() {  # $1 = jq-style key (crude grep — no jq dependency)
    grep -oE "\"$1\"[^,}]+" "$STATE_FILE" 2>/dev/null | cut -d'"' -f4
}

# ── Preflight ─────────────────────────────────────────────────────────────

TAG="sha-$(git rev-parse --short=7 HEAD)"
log "deploy start: mode=$MODE HEAD=$(git rev-parse HEAD) image-tag=$TAG"
log "log file: $LOG"

dirty=$(git status --porcelain | wc -l)
[[ "$dirty" -eq 0 ]] || die "$dirty dirty file(s) in the checkout — resolve drift first."

start_new_slot() {  # $1 = slot — image pinned to $2 (tag)
    local slot="$1" tag="$2"
    log "starting candidate $(cname "$slot") on image $tag"
    DEPLOY_TAG="$tag" compose_for "$slot" up -d --no-deps "$(slot_service "$slot")"
    wait_healthy "$(cname "$slot")" 300 || return 1
    probe_in_container "$(cname "$slot")" || { log "candidate $slot failed the in-container /en probe"; return 1; }
    log "candidate $(cname "$slot") healthy and serving"
}

retire_slot() {  # $1 = slot — graceful stop + remove (never the live one)
    local slot="$1" c; c=$(cname "$slot")
    log "retiring $c"
    docker stop -t 35 "$c" >/dev/null 2>&1 || true
    docker rm -f "$c" >/dev/null 2>&1 || true
}

roll_worker() {  # $1 = tag — off the critical path, after the flip
    local want="ghcr.io/roundup-games/platform-worker:$1" cur
    cur=$(docker inspect roundup-games-worker --format '{{.Config.Image}}' 2>/dev/null || echo none)
    if [[ "$cur" == "$want" ]]; then
        log "worker already on $1 — skipping"
        return 0
    fi
    log "rolling worker ($cur → $want)"
    DEPLOY_TAG="$1" docker compose up -d --no-deps worker
    wait_healthy roundup-games-worker 300 || log "WARN: worker not healthy yet (draining long jobs?) — not user-facing"
}

# ── Bootstrap (legacy single-container stack → blue/green) ────────────────

bootstrap() {
    log "BOOTSTRAP: legacy stack detected (no edge container) — one ~5s blip follows, the last one."
    write_edge_conf green

    log "pulling images for $TAG"
    DEPLOY_TAG="$TAG" docker compose --profile bluegreen pull app app-green worker

    # 1. Green candidate boots alongside the legacy app (no port conflict —
    #    slots publish no host ports; legacy owns :8199 until step 2).
    start_new_slot green "$TAG" || die "bootstrap candidate failed — legacy stack untouched, still serving."

    # 2. Retire the legacy containers. The old worker may be wedged by the
    #    s6 notification-fd deadlock (issue #637) — cap its stop at 30s and
    #    kill it rather than waiting out its 5m grace; queues are empty
    #    enough that Horizon retry semantics absorb it.
    log "retiring legacy containers (roundup-games, roundup-games-worker)"
    docker stop -t 30 roundup-games >/dev/null 2>&1 || true
    docker rm -f roundup-games roundup-games-worker >/dev/null 2>&1 || true

    # 3. Edge takes :8199 pointing at green — the blip.
    docker compose up -d edge
    wait_healthy "$EDGE" 60 || die "edge not healthy — check edge/Caddyfile + caddy logs"

    verify_public_retry up && verify_public_retry en \
        || die "public verification failed after bootstrap — edge/Caddyfile points at green; inspect roundup-games-green."

    roll_worker "$TAG"
    docker compose up -d backup
    save_state green "$TAG" legacy
    log "bootstrap complete: green live on $TAG, edge owns :8199. Future deploys are zero-downtime."
}

# ── Deploy ────────────────────────────────────────────────────────────────

deploy() {
    local live cand
    live=$(live_slot) || true
    if [[ -z "$live" ]]; then
        bootstrap
        live=$(live_slot) || true
    fi
    [[ -n "$live" ]] || die "no live slot after bootstrap — edge container missing? Inspect $EDGE."

    cand=$(other_slot "$live")
    log "live slot: $live — candidate: $cand"

    log "pulling images for $TAG (a 'manifest unknown' here means the Images workflow for this commit has not finished)"
    DEPLOY_TAG="$TAG" docker compose --profile bluegreen pull app app-green worker

    start_new_slot "$cand" "$TAG" || {
        retire_slot "$cand"
        die "candidate $cand failed pre-flip gates — live slot $live untouched and still serving."
    }

    local prev_tag
    prev_tag=$(read_state_field tag || echo unknown)

    flip_to "$cand"

    if verify_public_retry up && verify_public_retry en; then
        log "public verification OK (up + en → 200 through Cloudflare)"
    else
        log "PUBLIC VERIFICATION FAILED after flip — flipping back to $live"
        flip_to "$live"
        verify_public_retry up || die "flip-back verification ALSO failing — site is down, investigate the edge/app manually NOW."
        retire_slot "$cand"
        die "rolled back to $live — candidate $cand rejected post-flip."
    fi

    retire_slot "$live"
    roll_worker "$TAG"
    docker compose up -d backup
    save_state "$cand" "$TAG" "$prev_tag"

    log "deploy complete: $cand live on $TAG (previous: $live/$prev_tag)."
    log "rollback: bash scripts/deploy/homelab.sh --rollback${prev_tag:+ $prev_tag}"
}

# ── Rollback ──────────────────────────────────────────────────────────────

rollback() {
    local live cand tag="${ROLLBACK_TAG:-$(read_state_field prev_tag || true)}"
    live=$(live_slot) || true
    [[ -n "$live" ]] || die "no live slot — nothing to roll back from."
    [[ -n "$tag" && "$tag" != unknown ]] || die "no rollback tag (pass one: --rollback sha-<short>, from deploy-logs history)."
    cand=$(other_slot "$live")
    log "ROLLBACK: live=$live/$TAG → $cand/$tag"

    DEPLOY_TAG="$tag" docker compose --profile bluegreen pull app app-green worker
    start_new_slot "$cand" "$tag" || die "rollback candidate failed — live slot $live/$TAG still serving."

    local bad_tag; bad_tag=$(read_state_field tag || echo "$TAG")
    flip_to "$cand"

    verify_public_retry up && verify_public_retry en \
        || die "rollback flip failed public verification — manual intervention required (edge/Caddyfile, $EDGE logs)."

    retire_slot "$live"
    roll_worker "$tag"
    save_state "$cand" "$tag" "$bad_tag"
    log "rollback complete: $cand live on $tag (was $live/$bad_tag)."
}

case "$MODE" in
    deploy)    deploy ;;
    rollback)  rollback ;;
esac

log "done."
