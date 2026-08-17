#!/usr/bin/env bash
#
# MyMate atomic-release deploy — shared helper library.
#
# Sourced by deploy.sh / activate.sh / rollback.sh / bootstrap-cutover.sh
# AFTER config.sh. Pure helpers; sourcing this does nothing on its own.
# ---------------------------------------------------------------------------

# ----- logging -------------------------------------------------------------
log()  { printf '\033[36m==>\033[0m %s\n' "$*"; }
ok()   { printf '\033[32m ok\033[0m %s\n' "$*"; }
warn() { printf '\033[33m !!\033[0m %s\n' "$*" >&2; }
die()  { printf '\033[31mERR\033[0m %s\n' "$*" >&2; exit 1; }

# ----- ids -----------------------------------------------------------------
# A sortable, filesystem-safe UTC timestamp id: 20260817T140501Z.
# Lexical sort == chronological sort, which the prune + "latest" logic rely on.
release_id() { date -u +%Y%m%dT%H%M%SZ; }

# ----- safety --------------------------------------------------------------
# HARD REFUSE if APP_ROOT is (or resolves to) the legacy in-place install.
# This is the guardrail that keeps every script from ever writing to the live
# app. Called at the top of anything that mutates APP_ROOT.
require_not_live() {
    local resolved legacy
    # readlink -f may be absent on some minimal boxes; fall back to a manual
    # canonicalization that does not require the path to already exist.
    resolved="$(_canon "$APP_ROOT")"
    legacy="$(_canon "$LEGACY_LIVE_PATH")"
    if [ -z "$resolved" ]; then
        die "APP_ROOT ('$APP_ROOT') could not be resolved — refusing to proceed."
    fi
    if [ "$resolved" = "$legacy" ]; then
        die "APP_ROOT resolves to the LIVE install ($LEGACY_LIVE_PATH). Refusing. This tooling manages an atomic-release root (e.g. /srv/mymate), never the in-place app."
    fi
    case "$resolved" in
        "$legacy"/*)
            die "APP_ROOT ('$resolved') is INSIDE the live install ($LEGACY_LIVE_PATH). Refusing." ;;
    esac
}

# Canonicalize a path without requiring it to exist (portable readlink -f).
_canon() {
    local p="$1"
    if command -v readlink >/dev/null 2>&1 && readlink -f "$p" >/dev/null 2>&1; then
        readlink -f "$p"; return
    fi
    # Manual fallback: resolve the existing prefix, append the rest.
    local dir base
    if [ -d "$p" ]; then
        ( cd "$p" 2>/dev/null && pwd -P )
    else
        dir="$(dirname "$p")"; base="$(basename "$p")"
        if [ -d "$dir" ]; then echo "$(cd "$dir" && pwd -P)/$base"; else echo "$p"; fi
    fi
}

# ----- atomic symlink swap -------------------------------------------------
# Point $2 (a symlink, e.g. $APP_ROOT/current) at $1 (a release dir) ATOMICALLY.
#
# WHY THIS IS ATOMIC:
#   `ln -sfn X link` when `link` already exists is NOT atomic — ln unlinks the
#   old symlink then creates the new one, leaving a window where `current` does
#   not exist (nginx/php would see a missing docroot mid-swap).
#   Instead we create the new symlink under a TEMP name, then rename it over the
#   live name. rename(2) over an existing path is atomic on POSIX: at every
#   instant `current` points at either the old release or the new one, never at
#   nothing. `mv -T` forces "treat dest as a plain name" (never descend into it
#   if it happens to be a dir), `-f` overwrites without prompting.
atomic_symlink() {
    local target="$1" link="$2"
    [ -e "$target" ] || die "atomic_symlink: target does not exist: $target"
    local tmp="${link}.tmp.$$"
    ln -sfn "$target" "$tmp"
    # GNU coreutils mv supports -T; the swap is a single rename() syscall.
    if ! mv -Tf "$tmp" "$link" 2>/dev/null; then
        # Portability fallback (BSD mv has no -T). Still a rename over the same
        # inode name, still atomic — we just cannot force the no-descend flag,
        # which is fine because `link` is always a symlink, never a real dir.
        mv -f "$tmp" "$link" || { rm -f "$tmp"; die "atomic_symlink: swap failed for $link"; }
    fi
}

# ----- shared entry wiring -------------------------------------------------
# Symlink every SHARED_FILES / SHARED_DIRS entry from $APP_ROOT/shared into the
# given release dir, replacing whatever the build produced. Idempotent.
link_shared_into() {
    local release="$1" shared="$APP_ROOT/shared" name src dst
    [ -d "$shared" ] || die "shared dir missing: $shared (run bootstrap-cutover or create it)"
    for name in $SHARED_FILES; do
        src="$shared/$name"; dst="$release/$name"
        [ -e "$src" ] || warn "shared file '$name' not present at $src — linking anyway (will dangle until created)"
        mkdir -p "$(dirname "$dst")"
        rm -rf "$dst"
        ln -sfn "$src" "$dst"
        ok "linked shared file $name -> $src"
    done
    for name in $SHARED_DIRS; do
        src="$shared/$name"; dst="$release/$name"
        [ -e "$src" ] || warn "shared dir '$name' not present at $src — linking anyway"
        mkdir -p "$(dirname "$dst")"
        rm -rf "$dst"
        ln -sfn "$src" "$dst"
        ok "linked shared dir $name -> $src"
    done
}

# ----- keep-N prune --------------------------------------------------------
# Delete all but the newest KEEP_RELEASES release dirs. NEVER deletes whatever
# `current` currently points at, even if it would fall outside the window.
prune_releases() {
    local rel_dir="$APP_ROOT/releases" keep="${KEEP_RELEASES}"
    [ -d "$rel_dir" ] || return 0
    local current_target=""
    [ -L "$APP_ROOT/current" ] && current_target="$(_canon "$APP_ROOT/current")"

    # Newest-first (ids sort lexically == chronologically).
    local all
    all="$(ls -1 "$rel_dir" 2>/dev/null | sort -r)"
    local i=0 name path
    while IFS= read -r name; do
        [ -n "$name" ] || continue
        path="$rel_dir/$name"
        [ -d "$path" ] || continue
        i=$((i + 1))
        if [ "$i" -le "$keep" ]; then continue; fi
        if [ -n "$current_target" ] && [ "$(_canon "$path")" = "$current_target" ]; then
            warn "prune: skipping $name (it is the LIVE release)"
            continue
        fi
        rm -rf "$path" && ok "pruned old release $name"
    done <<EOF
$all
EOF
}

# ----- service control -----------------------------------------------------
# Restart each unit in SERVICES. NO-OP (logged) when SERVICES is empty.
restart_services() {
    if [ -z "${SERVICES// /}" ]; then
        log "SERVICES empty — no services to restart (no-op)"
        return 0
    fi
    local u
    for u in $SERVICES; do
        log "restarting $u"
        if command -v systemctl >/dev/null 2>&1; then
            systemctl restart "$u" || warn "systemctl restart $u failed"
        else
            warn "systemctl not available — cannot restart $u"
        fi
    done
}

# ----- health check --------------------------------------------------------
# Poll HEALTH_URL until it answers 2xx/3xx or HEALTH_TIMEOUT elapses.
# Returns 0 healthy / 1 unhealthy. Skipped (0) when HEALTH_URL is empty.
health_check() {
    if [ -z "$HEALTH_URL" ]; then
        log "HEALTH_URL empty — skipping health check"
        return 0
    fi
    command -v curl >/dev/null 2>&1 || { warn "curl missing — cannot health-check"; return 0; }
    local deadline=$(( $(date +%s) + HEALTH_TIMEOUT )) code
    log "health check: $HEALTH_URL (up to ${HEALTH_TIMEOUT}s)"
    while [ "$(date +%s)" -lt "$deadline" ]; do
        code="$(curl -sS -o /dev/null -w '%{http_code}' --max-time 10 "$HEALTH_URL" 2>/dev/null || echo 000)"
        case "$code" in
            2??|3??) ok "healthy (HTTP $code)"; return 0 ;;
        esac
        sleep 2
    done
    warn "health check FAILED (last HTTP ${code:-000})"
    return 1
}

# ----- ownership -----------------------------------------------------------
# chown a path to APP_USER:APP_GROUP, but only when root and the user exists.
apply_ownership() {
    local path="$1"
    if [ "$(id -u)" = "0" ] && id "$APP_USER" >/dev/null 2>&1; then
        chown -R "$APP_USER:$APP_GROUP" "$path" 2>/dev/null || true
        ok "ownership $APP_USER:$APP_GROUP applied to $path"
    fi
}

# ----- resolve a release id ------------------------------------------------
# Echo the resolved release dir for an id, or 'latest' (newest), or the current
# link's target when given nothing. Dies if it cannot resolve.
resolve_release() {
    local want="$1" rel_dir="$APP_ROOT/releases"
    if [ -z "$want" ] || [ "$want" = "latest" ]; then
        local newest
        newest="$(ls -1 "$rel_dir" 2>/dev/null | sort -r | head -1)"
        [ -n "$newest" ] || die "no releases under $rel_dir"
        echo "$rel_dir/$newest"; return
    fi
    [ -d "$rel_dir/$want" ] || die "release not found: $want"
    echo "$rel_dir/$want"
}
