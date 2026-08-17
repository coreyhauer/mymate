#!/usr/bin/env bash
#
# MyMate atomic-release deploy — configuration.
#
# This file is SOURCED by every script in deploy/release/. It only sets
# variables (never DOES anything), so it is safe to source repeatedly.
#
# Every setting is overridable from the environment: `export APP_ROOT=...`
# before invoking a script wins over the default here. That is deliberate —
# the same scripts drive a real production deploy AND a throwaway scratch
# validation run just by pointing APP_ROOT at a different tree.
#
#   Layout this tooling manages (Capistrano/Deployer/Envoyer style):
#
#     $APP_ROOT/
#       releases/
#         20260817T140501Z/     <- one immutable build per deploy
#         20260817T151233Z/
#       shared/
#         .env                  <- the ONE real secret file (symlinked in)
#         storage/              <- user uploads, logs, caches (symlinked in)
#       current -> releases/20260817T151233Z   <- atomic symlink = "live"
#
# ---------------------------------------------------------------------------

# The root the whole release layout lives under. PROD default is /srv/mymate.
# Read from the environment on every run so validation can point it at a
# scratch dir (e.g. /root/mymate-release-staging) with zero code changes.
#
# NB: this is intentionally NOT /opt/mymate. /opt/mymate is the LEGACY in-place
# install; require_not_live (lib.sh) hard-refuses if APP_ROOT resolves there.
: "${APP_ROOT:=/srv/mymate}"

# Git source. The fork we deploy from.
: "${REPO_URL:=https://github.com/coreyhauer/mymate.git}"

# systemd units restarted after an activate/rollback. Space-separated.
# Empty string = restart NOTHING (used by plumbing validation).
: "${SERVICES:=mymate-horizon mymate-loop mymate-scheduler mymate-reverb mymate-agent-hub}"

# How many timestamped releases to keep under releases/ (older are pruned).
: "${KEEP_RELEASES:=5}"

# Shared entries symlinked from $APP_ROOT/shared/<name> INTO each release.
# SHARED_FILES: individual files.   SHARED_DIRS: directories.
# These persist across releases and are never part of a build.
: "${SHARED_FILES:=.env}"
: "${SHARED_DIRS:=storage}"

# Optional post-activate health probe. Empty = skip the health check entirely.
# When set, a non-2xx/3xx response triggers auto-rollback in activate.sh.
: "${HEALTH_URL:=}"
# Seconds to keep retrying the health URL before declaring failure.
: "${HEALTH_TIMEOUT:=60}"

# Toolchain. Absolute paths match the prod box; override for other hosts.
: "${PHP_BIN:=php}"
: "${COMPOSER_BIN:=composer}"
: "${NPM_BIN:=npm}"

# The build command run INSIDE a freshly-checked-out release dir. This is the
# one hook plumbing tests override (e.g. BUILD_CMD='touch .BUILD_OK') so the
# release mechanics can be validated without a full PHP/Node build.
#
# The real default mirrors the legacy /root/deploy.sh build steps:
#   - composer runtime deps (no dev, optimized autoloader)
#   - npm ci for a reproducible node_modules
#   - the guarded vite build (deploy/build/vite-build.sh bakes the Reverb key)
: "${BUILD_CMD:=${COMPOSER_BIN} install --no-dev --optimize-autoloader --no-interaction --no-progress && ${NPM_BIN} ci --no-audit --no-fund && ${NPM_BIN} run build}"

# Unix user/group that owns release files and runs the services. Ownership is
# only applied when we are root AND the user exists (skipped in scratch runs).
: "${APP_USER:=mymate}"
: "${APP_GROUP:=${APP_USER}}"

# The legacy in-place install path we must NEVER manage as a release root.
: "${LEGACY_LIVE_PATH:=/opt/mymate}"
