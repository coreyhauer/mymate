#!/usr/bin/env bash
#
# MyMate atomic-release deploy — ACTIVATE a built release (go live).
#
#   activate.sh [<timestamp>|latest]     (default: latest)
#
# Steps, in order (each post-step is individually skippable):
#   1. ATOMIC flip of `current` -> the chosen release   (the actual go-live)
#   2. php artisan config:cache        (--no-config-cache to skip)
#   3. php artisan migrate --force     (OFF by default; requires --migrate)
#   4. php artisan storage:link        (--no-storage-link to skip)
#   5. restart SERVICES                (--no-restart to skip)
#   6. health check                    (skipped when HEALTH_URL empty)
#
# If the health check fails, `current` is AUTOMATICALLY rolled back to the
# release that was live before this activation, and services are restarted.
#
# ⚠ MIGRATIONS ARE NOT SYMLINK-ROLLBACK-SAFE. Flipping `current` back is
#   instant for CODE, but a schema change applied by `migrate --force` stays
#   applied. That is why migrate is OFF by default and gated behind --migrate.
#   Follow the expand/contract rule (see README) so old code keeps working
#   against the new schema.
# ---------------------------------------------------------------------------
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=config.sh
. "$HERE/config.sh"
# shellcheck source=lib.sh
. "$HERE/lib.sh"

DO_CONFIG_CACHE=1
DO_MIGRATE=0
DO_STORAGE_LINK=1
DO_RESTART=1
WANT=""

for arg in "$@"; do
    case "$arg" in
        --migrate)          DO_MIGRATE=1 ;;
        --no-config-cache)  DO_CONFIG_CACHE=0 ;;
        --no-storage-link)  DO_STORAGE_LINK=0 ;;
        --no-restart)       DO_RESTART=0 ;;
        --*)                die "unknown flag: $arg" ;;
        *)                  WANT="$arg" ;;
    esac
done

require_not_live

RELEASE="$(resolve_release "$WANT")"
RELEASE_ID="$(basename "$RELEASE")"
CURRENT="$APP_ROOT/current"

# Remember what was live so we can auto-rollback on a failed health check.
PREVIOUS=""
if [ -L "$CURRENT" ]; then
    PREVIOUS="$(_canon "$CURRENT")"
fi

log "activating release $RELEASE_ID"
log "current before: $( [ -L "$CURRENT" ] && readlink "$CURRENT" || echo '(none)')"

# --- 1. atomic flip ------------------------------------------------------
atomic_symlink "$RELEASE" "$CURRENT"
ok "current after : $(readlink "$CURRENT")"

# artisan runs against the LIVE symlink path so per-release realpath is used
# (this is also why opcache invalidates naturally — the script paths change).
ARTISAN="$CURRENT/artisan"

run_artisan() {
    local desc="$1"; shift
    log "artisan $desc"
    if [ -f "$ARTISAN" ]; then
        ( cd "$CURRENT" && "$PHP_BIN" artisan "$@" ) || warn "artisan $desc failed (continuing)"
    else
        warn "no artisan at $ARTISAN — skipping $desc (plumbing/scratch run?)"
    fi
}

# --- 2. config cache -----------------------------------------------------
if [ "$DO_CONFIG_CACHE" = 1 ]; then run_artisan "config:cache" config:cache
else log "skipping config:cache (--no-config-cache)"; fi

# --- 3. migrate (opt-in) -------------------------------------------------
if [ "$DO_MIGRATE" = 1 ]; then
    warn "running migrations (--migrate). NOTE: schema changes do NOT roll back with a symlink flip."
    run_artisan "migrate --force" migrate --force
else
    log "skipping migrations (default; pass --migrate to run them)"
fi

# --- 4. storage:link -----------------------------------------------------
if [ "$DO_STORAGE_LINK" = 1 ]; then run_artisan "storage:link" storage:link
else log "skipping storage:link (--no-storage-link)"; fi

apply_ownership "$RELEASE"

# --- 5. restart services -------------------------------------------------
if [ "$DO_RESTART" = 1 ]; then restart_services
else log "skipping service restart (--no-restart)"; fi

# --- 6. health check + auto-rollback ------------------------------------
if health_check; then
    echo
    ok "activated release $RELEASE_ID"
    echo "$RELEASE_ID"
else
    warn "health check failed after activating $RELEASE_ID"
    if [ -n "$PREVIOUS" ] && [ -d "$PREVIOUS" ]; then
        warn "AUTO-ROLLBACK -> $(basename "$PREVIOUS")"
        atomic_symlink "$PREVIOUS" "$CURRENT"
        [ "$DO_RESTART" = 1 ] && restart_services
        if health_check; then
            die "rolled back to $(basename "$PREVIOUS") after failed activation of $RELEASE_ID"
        else
            die "activation of $RELEASE_ID FAILED and rollback to $(basename "$PREVIOUS") is ALSO unhealthy — manual intervention required"
        fi
    else
        die "activation of $RELEASE_ID failed health check and there is no prior release to roll back to"
    fi
fi
