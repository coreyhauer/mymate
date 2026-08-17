#!/usr/bin/env bash
#
# MyMate atomic-release deploy — ONE-TIME cutover from the legacy in-place
# install (/opt/mymate) to the atomic-release layout ($APP_ROOT).
#
#   bootstrap-cutover.sh <git-ref>
#
# This runs ONCE, when migrating a box that currently runs the old
# rsync-overlay layout. It is deliberately STAGED and SAFE:
#
#   1. READS /opt/mymate and copies its live .env + storage/ into
#      $APP_ROOT/shared/   (the persistent, symlinked-in state).
#   2. Builds the FIRST release from <git-ref> (via deploy.sh).
#   3. Flips $APP_ROOT/current to that first release (symlink only).
#   4. PRINTS — but never executes — the remaining human steps that touch
#      live system config: the per-unit systemd ExecStart path change, the
#      nginx docroot change, daemon-reload, and the restarts.
#
# It NEVER writes to /opt/mymate, NEVER edits a systemd unit, NEVER touches
# nginx, and NEVER restarts a live service. Those are the human's call, done
# once, after reviewing the printed diff.
#
# Refuses to run if APP_ROOT == /opt/mymate.
# ---------------------------------------------------------------------------
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=config.sh
. "$HERE/config.sh"
# shellcheck source=lib.sh
. "$HERE/lib.sh"

REF="${1:-}"
[ -n "$REF" ] || die "usage: bootstrap-cutover.sh <git-ref>"

require_not_live   # hard-refuses APP_ROOT==/opt/mymate (and anything under it)

LIVE="$LEGACY_LIVE_PATH"
SHARED="$APP_ROOT/shared"

log "ONE-TIME cutover: $LIVE  ->  $APP_ROOT (atomic releases)"
[ -d "$LIVE" ] || die "legacy install not found at $LIVE — nothing to cut over from"

mkdir -p "$SHARED"

# --- 1. copy live shared state into the new shared/ ----------------------
# .env — the real secret file. Copy only if we don't already have one (never
# clobber a shared/.env you may have already prepared).
if [ -f "$SHARED/.env" ]; then
    warn "$SHARED/.env already exists — keeping it, NOT overwriting from live"
elif [ -f "$LIVE/.env" ]; then
    cp -p "$LIVE/.env" "$SHARED/.env"
    ok "copied live .env -> $SHARED/.env"
else
    warn "no .env at $LIVE/.env — you must create $SHARED/.env before going live"
fi

# storage/ — uploads, logs, caches. rsync if present, else cp -a.
if [ -d "$SHARED/storage" ]; then
    warn "$SHARED/storage already exists — keeping it, NOT overwriting from live"
elif [ -d "$LIVE/storage" ]; then
    log "copying live storage/ -> $SHARED/storage (this preserves uploads + logs)"
    if command -v rsync >/dev/null 2>&1; then
        rsync -a "$LIVE/storage/" "$SHARED/storage/"
    else
        cp -a "$LIVE/storage" "$SHARED/storage"
    fi
    ok "copied live storage/"
else
    warn "no storage/ at $LIVE — creating an empty one"
    mkdir -p "$SHARED/storage"
fi

apply_ownership "$SHARED"

# --- 2 + 3. build the first release and flip current ---------------------
log "building the first release from '$REF'"
FIRST_ID="$( "$HERE/deploy.sh" "$REF" | tail -1 )"
[ -n "$FIRST_ID" ] || die "deploy.sh did not return a release id"
ok "first release built: $FIRST_ID"

log "flipping current -> $FIRST_ID (symlink only; nothing live yet)"
"$HERE/activate.sh" "$FIRST_ID" --no-restart --no-config-cache --no-storage-link >/dev/null 2>&1 || \
    atomic_symlink "$APP_ROOT/releases/$FIRST_ID" "$APP_ROOT/current"
ok "current -> $(readlink "$APP_ROOT/current")"

# --- 4. print the remaining HUMAN steps ----------------------------------
CUR="$APP_ROOT/current"
cat <<BANNER

============================================================================
  CUTOVER STAGED. The new layout is ready at:  $APP_ROOT
      $APP_ROOT/shared/.env
      $APP_ROOT/shared/storage/
      $APP_ROOT/current  ->  releases/$FIRST_ID

  The following steps TOUCH LIVE SYSTEM CONFIG and are LEFT FOR YOU to run
  once, after reviewing them. This script did NOT perform any of them.
----------------------------------------------------------------------------

  A) Point each systemd unit at the new path. For every unit, change:
        WorkingDirectory=$LIVE          ->  WorkingDirectory=$CUR
        ExecStart=/usr/bin/php $LIVE/artisan ...  ->  /usr/bin/php $CUR/artisan ...

     Units + their ExecStart commands:
        mymate-horizon     : php $CUR/artisan horizon
        mymate-loop        : php $CUR/artisan mymate:loop
        mymate-scheduler   : php $CUR/artisan schedule:work
        mymate-reverb      : php $CUR/artisan reverb:start --host=127.0.0.1 --port=8080
        mymate-agent-hub   : php $CUR/artisan mymate:agent-hub --host=127.0.0.1 --port=9091

     Edit the unit files under /usr/lib/systemd/system/mymate-*.service
     (prefer a drop-in override:  systemctl edit mymate-<unit>  and set
     WorkingDirectory + ExecStart there so package updates don't clobber it).

  B) Point nginx at the new docroot:
        /etc/nginx/sites-available/mymate:
            root $LIVE/public;   ->   root $CUR/public;
        Then:  nginx -t

  C) Apply and restart:
        systemctl daemon-reload
        systemctl restart mymate-horizon mymate-loop mymate-scheduler mymate-reverb mymate-agent-hub
        systemctl reload nginx

  D) Verify, then from now on deploy with:
        deploy/release/deploy.sh <ref>
        deploy/release/activate.sh latest [--migrate]

  Rollback of code is then instant:  deploy/release/rollback.sh
============================================================================

BANNER

ok "cutover staging complete — perform steps A–D by hand to finish"
