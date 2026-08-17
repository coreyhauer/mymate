# MyMate Release Tooling — atomic releases + `current` symlink

**This is the canonical, standard way to build, deploy, and activate MyMate.**
It replaces the hand-run `/root/deploy.sh` rsync-overlay. Every deploy produces
an immutable, timestamped release; going live is an atomic symlink flip; rolling
back is the same flip in reverse.

```
$APP_ROOT/                          # default /srv/mymate (env-overridable)
├── releases/
│   ├── 20260817T140501Z/           # one immutable build per deploy
│   └── 20260817T151233Z/
├── shared/
│   ├── .env                        # the ONE real secret file (symlinked in)
│   └── storage/                    # uploads, logs, caches (symlinked in)
└── current -> releases/20260817T151233Z   # atomic symlink = "what's live"
```

nginx serves `$APP_ROOT/current/public`; every systemd unit runs
`php $APP_ROOT/current/artisan …`. Because those paths go through `current`,
switching releases is a single `rename()` and **opcache invalidates naturally**
— each release has a distinct realpath, so PHP never serves stale bytecode from
the previous release.

---

## Files

| File | Role |
|------|------|
| `config.sh` | Sourced settings; every value is env-overridable. |
| `lib.sh` | Helpers: timestamp id, atomic symlink swap, prune, restart, health, `require_not_live`. |
| `deploy.sh <ref>` | Build a new `releases/<ts>/` from a git ref. Does **not** go live. |
| `activate.sh [<ts>\|latest]` | Atomic flip of `current` + ordered post-steps; auto-rollback on health failure. |
| `rollback.sh [<ts>]` | Flip `current` back to the prior (or a named) release. |
| `bootstrap-cutover.sh <ref>` | One-time migration from `/opt/mymate` to this layout. |

---

## Normal workflow (day-to-day)

```bash
cd /path/to/mymate/deploy/release

# 1. Build a release from whatever branch/tag/commit you want to ship.
#    "which branch ships" is now an ARGUMENT, not baked into the script.
./deploy.sh main                 # or: ./deploy.sh v1.4.0   or a commit SHA

# 2. Go live (atomic). 'latest' = the newest built release.
./activate.sh latest

#    With a schema change that IS backward-compatible (see the rule below):
./activate.sh latest --migrate
```

`deploy.sh` prints the new release id as its last line, so you can capture it:

```bash
REL=$(./deploy.sh main | tail -1)
./activate.sh "$REL"
```

### Rollback

```bash
./rollback.sh            # -> the immediately-previous release
./rollback.sh 20260817T140501Z   # -> a specific release
```

Code rollback is instant. **Migrations do not roll back** (see below).

---

## `activate.sh` post-steps

Run in order after the atomic flip; each is individually skippable:

| Step | Default | Skip flag |
|------|---------|-----------|
| `php artisan config:cache` | on | `--no-config-cache` |
| `php artisan migrate --force` | **OFF** | opt-in: `--migrate` |
| `php artisan storage:link` | on | `--no-storage-link` |
| restart `SERVICES` | on | `--no-restart` |
| health check (`HEALTH_URL`) | on if set | skipped when `HEALTH_URL` empty |

If the health check fails, `current` is **automatically rolled back** to the
release that was live before the activation, and services are restarted.

---

## ⚠ Expand / contract — the backward-compatible migration rule

A symlink flip rolls **code** back instantly. It does **NOT** roll a database
migration back. `migrate --force` is therefore **off by default** and gated
behind `--migrate`.

**The rule: never ship a destructive/incompatible schema change in the same
release as the code that needs it.** Split every schema change into
backward-compatible steps so the *old* code keeps working against the *new*
schema (and so you can roll code back without the DB fighting you):

- **Expand** (safe, deploy first): add nullable columns, add new tables, add
  indexes, backfill data, start writing to both old + new shapes. Old code
  ignores the additions; new code can use them. Fully rollback-safe.
- **Contract** (destructive, a LATER release): drop/rename columns, add NOT
  NULL, remove old code paths — only after every running release already
  tolerates the new shape and you're confident you won't roll back past it.

Concretely: rename `foo` → `bar` as **add `bar` (expand)** → deploy code that
writes both / reads `bar` → backfill → **drop `foo` (contract)** in a much later
release. Each release stays rollback-safe on its own.

If you ever DO need an emergency schema rollback, that is a manual DB operation
(restore from the nightly dump under `/root/mymate-deploy-backups/`), not a
`rollback.sh` flip.

---

## Prune behavior

`deploy.sh` keeps the newest `KEEP_RELEASES` (default **5**) release dirs and
deletes older ones. The currently-live release (whatever `current` points at) is
**never** pruned, even if it falls outside the window. `shared/` is never
touched by pruning.

---

## Configuration (`config.sh`, all env-overridable)

| Var | Default | Meaning |
|-----|---------|---------|
| `APP_ROOT` | `/srv/mymate` | Root of the release layout. **Never `/opt/mymate`.** |
| `REPO_URL` | `https://github.com/coreyhauer/mymate.git` | Source fork. |
| `SERVICES` | `mymate-horizon mymate-loop mymate-scheduler mymate-reverb mymate-agent-hub` | Units restarted on activate/rollback. Empty = restart nothing. |
| `KEEP_RELEASES` | `5` | Release dirs to retain. |
| `SHARED_FILES` | `.env` | Files symlinked from `shared/` into each release. |
| `SHARED_DIRS` | `storage` | Dirs symlinked from `shared/` into each release. |
| `HEALTH_URL` | *(empty)* | Post-activate probe; empty = skip. |
| `PHP_BIN` / `COMPOSER_BIN` / `NPM_BIN` | `php` / `composer` / `npm` | Toolchain. |
| `BUILD_CMD` | composer + npm ci + npm run build | Build hook (overridable for plumbing tests). |
| `APP_USER` / `APP_GROUP` | `mymate` | Ownership applied when run as root. |

`require_not_live` (in `lib.sh`) hard-refuses if `APP_ROOT` resolves to
`/opt/mymate` or anything inside it — the safety backstop behind every script.

---

## One-time cutover from the legacy `/opt/mymate`

`/opt/mymate` is the old in-place install. Migrate it **once** with:

```bash
export APP_ROOT=/srv/mymate
./bootstrap-cutover.sh main
```

This **reads** `/opt/mymate` (copies its live `.env` + `storage/` into
`$APP_ROOT/shared/`), builds the first release, and points `current` at it. It
then **prints — but does not execute —** the remaining human steps that touch
live system config:

- **systemd** — for each of the five units, change `WorkingDirectory` and
  `ExecStart` from `/opt/mymate/…` to `$APP_ROOT/current/…`. Recommended as a
  drop-in override (`systemctl edit mymate-<unit>`) so package updates don't
  clobber it. The five ExecStart commands are printed by the script.
- **nginx** — change `root /opt/mymate/public;` to `root $APP_ROOT/current/public;`
  in `/etc/nginx/sites-available/mymate`, then `nginx -t`.
- **apply** — `systemctl daemon-reload`, restart the five units, `systemctl reload nginx`.

`bootstrap-cutover.sh` never writes to `/opt/mymate`, never edits a unit, never
touches nginx, and never restarts a live service. It refuses if
`APP_ROOT == /opt/mymate`.

---

## What this replaces (and why)

The old path was `/root/deploy.sh`: a fresh `git clone` of a **hardcoded
branch** rsync'd **in place** over `/opt/mymate`, then `composer install` +
`npm run build` against the live tree.

Problems it had, and how this fixes them:

| Old `/root/deploy.sh` | This tooling |
|-----------------------|--------------|
| rsync overlays the **live** directory — a half-built state is briefly serving | build fully into `releases/<ts>/`, flip only when done |
| branch hardcoded (`feat/…`) in the script — a copy-paste footgun | `deploy.sh <ref>` — branch/tag/commit is an **argument** |
| no rollback — you re-deploy the old branch and hope | `rollback.sh` — instant symlink flip to the prior release |
| build errors can leave the site broken | a failed `BUILD_CMD` leaves `current` untouched |
| lives in `/root`, unversioned, per-feature copies (`bhdeploy.sh`, …) | one repo-versioned tool under `deploy/release/` |

Migrations remain the one thing a symlink can't undo — hence the expand/contract
rule above.
