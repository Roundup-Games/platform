#!/bin/sh
#
# roundup.games backup runner — nightly via the `backup` compose sidecar
# (docker/backup/crontab). Dump + tar + offsite copy + retention.
#
# RESTORE RUNBOOK (rehearsed 2026-10-26; see commit for evidence):
#
#   Database (full, latest):
#     docker compose up -d postgres  # or: any postgres:17 reachable
#     docker run --rm -i --network roundup-games-laravel_roundup \
#       -e PGPASSWORD='<DB_PASSWORD>' postgres:17-alpine \
#       pg_restore -h postgresql17 -U <DB_USERNAME> -d <DB_DATABASE> --clean --if-exists \
#       < /backups/db/roundup-<stamp>.dump        # or pipe from the offsite copy
#     # For a side-by-side rehearsal instead of overwriting prod, restore into
#     # a scratch container first and compare row counts (what the rehearsal did).
#
#   Storage volume (media, charters, journals):
#     docker run --rm -v roundup-games-laravel_app_storage:/restore \
#       -v "$(pwd)/deploy-logs:/src" alpine \
#       sh -c 'cd /restore && tar -xf /src/../backups/storage/storage-<stamp>.tar'
#     # Simplest real path: stop app+worker, untar over the volume, start again:
#     docker compose stop app worker
#     docker run --rm -v roundup-games-laravel_app_storage:/restore -v /mnt/user/roundup-games-backups:/src:ro alpine \
#       sh -c 'cd /restore && tar -xf /src/storage/storage-<stamp>.tar'
#     docker compose start
#
# Failure semantics: `set -eu` aborts on the first failed step; the Phare
# heartbeat (PHARE_CRON_PING_URL, optional) only fires on COMPLETE success —
# a missed ping is the alert. Logs: docker logs roundup-games-backup and
# /backups/backup.log (inside the backup_data volume).
set -eu

log() { echo "[$(date -u +%Y-%m-%dT%H:%M:%SZ)] $*"; }

STAMP="$(date -u +%Y%m%d-T%H%M%SZ)"
KEEP="${BACKUP_KEEP_COUNT:-8}"
DB_HOST="${DB_HOST:-postgresql17}"
: "${DB_USERNAME:?DB_USERNAME missing}"
: "${DB_PASSWORD:?DB_PASSWORD missing}"
: "${DB_DATABASE:?DB_DATABASE missing}"
export PGPASSWORD="$DB_PASSWORD"

log "backup start (host=$DB_HOST db=$DB_DATABASE keep=$KEEP)"
mkdir -p /backups/db /backups/storage

# --- Database: custom-format dump (compressed, pg_restore-able, catalogable)
DB_OUT="/backups/db/roundup-$STAMP.dump"
pg_dump -h "$DB_HOST" -U "$DB_USERNAME" -Fc -f "$DB_OUT" "$DB_DATABASE"
pg_restore --list "$DB_OUT" >/dev/null
log "db dump ok: $(du -h "$DB_OUT" | cut -f1)"

# --- Storage volume: plain tar (payload is already-compressed media; gzip
# would burn CPU for single-digit % savings). Mounted read-only at
# /mnt/app-storage. Excluded as regenerable: ./media-library (Spatie temp —
# grew to 1.1GB of stale conversion scratch before 2026-10-26 prune) and
# ./framework (compiled views/caches, rebuilt on boot by 99-laravel-init).
ST_OUT="/backups/storage/storage-$STAMP.tar"
tar -C /mnt/app-storage -cf "$ST_OUT" --exclude='./media-library' --exclude='./framework' .
log "storage tar ok: $(du -h "$ST_OUT" | cut -f1)"

# --- Offsite copy: second medium (share on the array pools — set the share
# to "Use cache: no" in unRAID once). Copy failure is FATAL on purpose: a
# silently stale offsite set is how restore stories die.
if [ -d /mnt/offsite ]; then
    mkdir -p /mnt/offsite/db /mnt/offsite/storage
    cp "$DB_OUT" /mnt/offsite/db/
    cp "$ST_OUT" /mnt/offsite/storage/
    log "offsite copies ok"
else
    log "FATAL: offsite mount /mnt/offsite missing — aborting before retention"
    exit 1
fi

# --- Retention: newest KEEP per kind, local and offsite.
for base in /backups /mnt/offsite; do
    for kind in db storage; do
        ls -1t "$base/$kind" 2>/dev/null | tail -n +"$((KEEP + 1))" | \
            while IFS= read -r f; do rm -f "$base/$kind/$f"; done
    done
done
log "retention applied (keep=$KEEP)"

# --- Heartbeat: success-only ping. Phare cron check fires when this lands.
if [ -n "${PHARE_CRON_PING_URL:-}" ]; then
    if wget -q -O /dev/null "$PHARE_CRON_PING_URL" 2>&1; then
        log "phare heartbeat ok"
    else
        log "WARN: phare ping failed (backup itself succeeded)"
    fi
fi

log "backup complete: db=roundup-$STAMP.dump storage=storage-$STAMP.tar"
