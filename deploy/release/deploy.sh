#!/usr/bin/env bash
#
# MyMate atomic-release deploy — BUILD a new release.
#
#   deploy.sh <git-ref>
#
# Fetches <git-ref> from REPO_URL, materializes it as an immutable
# releases/<timestamp>/ directory, runs BUILD_CMD inside it, links the shared
# .env + storage in, and prunes to KEEP_RELEASES.
#
# It DOES NOT flip `current` — the live app is untouched. Run activate.sh to go
# live. This split (build vs activate) is what makes zero-downtime + instant
# rollback possible: the new code is fully built and on disk before anything
# switches.
#
# Idempotent: each run mints a fresh timestamped release; re-running never
# mutates a prior release. Prints the new release id on success.
# ---------------------------------------------------------------------------
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=config.sh
. "$HERE/config.sh"
# shellcheck source=lib.sh
. "$HERE/lib.sh"

REF="${1:-}"
[ -n "$REF" ] || die "usage: deploy.sh <git-ref>   (branch, tag, or commit)"

require_not_live

log "APP_ROOT   = $APP_ROOT"
log "REPO_URL   = $REPO_URL"
log "git ref    = $REF"

mkdir -p "$APP_ROOT/releases" "$APP_ROOT/shared"

ID="$(release_id)"
RELEASE="$APP_ROOT/releases/$ID"
# Guard the (astronomically unlikely) same-second collision.
[ -e "$RELEASE" ] && die "release id collision: $RELEASE already exists"

log "creating release $ID"
mkdir -p "$RELEASE"

# --- fetch the ref -------------------------------------------------------
# Shallow-fetch exactly the requested ref into the release dir. This resolves
# branches, tags, AND commit SHAs (fallback path) and leaves a clean checkout
# with no .git history bloating the release.
log "fetching $REF from $REPO_URL"
git -C "$RELEASE" init -q
git -C "$RELEASE" remote add origin "$REPO_URL"
if git -C "$RELEASE" fetch --depth 1 origin "$REF" 2>/dev/null; then
    git -C "$RELEASE" checkout -q FETCH_HEAD
else
    # Commit SHAs cannot always be fetched shallowly by ref; deepen and checkout.
    warn "shallow fetch of '$REF' failed; retrying with full history"
    git -C "$RELEASE" fetch origin 2>/dev/null || die "git fetch failed for ref '$REF'"
    git -C "$RELEASE" checkout -q "$REF" || die "git checkout failed for ref '$REF'"
fi
CHECKED_OUT="$(git -C "$RELEASE" rev-parse --short HEAD 2>/dev/null || echo '?')"
ok "checked out $REF @ $CHECKED_OUT"

# Drop the .git dir — a release is an immutable artifact, not a working tree.
rm -rf "$RELEASE/.git"

# --- wire shared entries BEFORE building --------------------------------
# The build (composer/npm/artisan) may need .env and a writable storage/, so
# link them in first.
link_shared_into "$RELEASE"

# --- build ---------------------------------------------------------------
log "building: $BUILD_CMD"
(
    cd "$RELEASE"
    # Run the hook in a subshell so a `cd` inside it can't leak out.
    eval "$BUILD_CMD"
) || die "BUILD_CMD failed for release $ID — leaving it in place for inspection, current NOT touched"
ok "build complete"

apply_ownership "$RELEASE"

# --- prune ---------------------------------------------------------------
prune_releases

echo
ok "release built: $ID"
log "not activated. To go live:  activate.sh $ID   (or: activate.sh latest)"
# Machine-readable last line: the bare release id.
echo "$ID"
