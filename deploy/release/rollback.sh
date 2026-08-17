#!/usr/bin/env bash
#
# MyMate atomic-release deploy — ROLLBACK to a prior release.
#
#   rollback.sh [<timestamp>]
#
# With no argument: repoint `current` to the immediately-previous release
# (the newest release older than the one currently live).
# With a <timestamp>: repoint `current` to that specific release.
#
# Then restart SERVICES and health-check. Because this is a symlink flip, CODE
# rollback is instant. Remember: DB migrations already applied do NOT revert —
# see the expand/contract rule in the README.
# ---------------------------------------------------------------------------
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=config.sh
. "$HERE/config.sh"
# shellcheck source=lib.sh
. "$HERE/lib.sh"

WANT="${1:-}"
require_not_live

CURRENT="$APP_ROOT/current"
REL_DIR="$APP_ROOT/releases"
[ -L "$CURRENT" ] || die "no current symlink at $CURRENT — nothing to roll back from"

CURRENT_ID="$(basename "$(_canon "$CURRENT")")"

if [ -n "$WANT" ]; then
    TARGET="$(resolve_release "$WANT")"
else
    # Newest release strictly older than the live one.
    PREV_ID="$(ls -1 "$REL_DIR" 2>/dev/null | sort -r | awk -v cur="$CURRENT_ID" '$0 < cur {print; exit}')"
    [ -n "$PREV_ID" ] || die "no release older than the current one ($CURRENT_ID) to roll back to"
    TARGET="$REL_DIR/$PREV_ID"
fi

TARGET_ID="$(basename "$TARGET")"
[ "$TARGET_ID" = "$CURRENT_ID" ] && die "target ($TARGET_ID) is already live — nothing to do"

log "rolling back: $CURRENT_ID -> $TARGET_ID"
log "current before: $(readlink "$CURRENT")"

atomic_symlink "$TARGET" "$CURRENT"
ok "current after : $(readlink "$CURRENT")"

apply_ownership "$TARGET"
restart_services

if health_check; then
    echo
    ok "rolled back to $TARGET_ID"
    echo "$TARGET_ID"
else
    die "rollback to $TARGET_ID is unhealthy — manual intervention required"
fi
