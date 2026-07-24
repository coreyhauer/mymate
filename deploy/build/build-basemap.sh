#!/usr/bin/env bash
#
# Build a self-hosted vector basemap for the MapLibre GL geo view.
#
# Produces, under the app's public/map (all same-origin, so the CSP stays clean and the map
# works with no third-party host):
#   basemap.pmtiles                    a Protomaps planet extract for your region
#   style.json                         a Protomaps flavour style pointing at the above
#   fonts/<fontstack>/<range>.pbf      glyphs
#   sprites/<flavour>.{json,png}       sprite
#
# Then set MYMATE_MAP_STYLE_URL=/map/style.json in .env and the geo view switches from the
# Leaflet raster fallback to the WebGL vector renderer (clustered devices + backhaul lines).
#
# Requires: pmtiles CLI (github.com/protomaps/go-pmtiles), node/npm, git, curl.
#
# Usage:
#   BBOX=-99,35.5,-87,47 THEME=dark deploy/build/build-basemap.sh
#
# Env knobs:
#   APP_DIR    app root                (default: /opt/mymate)
#   BBOX       minLon,minLat,maxLon,maxLat  REQUIRED - your footprint
#   MAXZOOM    extract detail          (default: 14)
#   THEME      protomaps flavour       (default: dark)  [light|dark|white|grayscale|black]
#   PLANET     source planet pmtiles   (default: latest build.protomaps.com)
set -euo pipefail

APP_DIR="${APP_DIR:-/opt/mymate}"
MAXZOOM="${MAXZOOM:-14}"
THEME="${THEME:-dark}"
MAP_DIR="$APP_DIR/public/map"
: "${BBOX:?set BBOX=minLon,minLat,maxLon,maxLat for your footprint}"

say() { printf '\033[36m==>\033[0m %s\n' "$*"; }

# --- 1. pick the source planet build -------------------------------------
if [ -z "${PLANET:-}" ]; then
    for d in $(seq 0 6); do
        day=$(date -u -d "-$d day" +%Y%m%d 2>/dev/null || date -u -v-"${d}"d +%Y%m%d)
        if curl -fsI "https://build.protomaps.com/$day.pmtiles" >/dev/null 2>&1; then
            PLANET="https://build.protomaps.com/$day.pmtiles"; break
        fi
    done
fi
[ -n "${PLANET:-}" ] || { echo "no recent Protomaps build found; set PLANET=" >&2; exit 1; }
say "source planet: $PLANET"

mkdir -p "$MAP_DIR"

# --- 2. extract the region (range reads only the covering tiles) ---------
say "extracting region $BBOX @z$MAXZOOM -> basemap.pmtiles"
pmtiles extract "$PLANET" "$MAP_DIR/basemap.pmtiles" --bbox="$BBOX" --maxzoom="$MAXZOOM"

# --- 3. glyphs + sprite + style ------------------------------------------
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT
say "fetching Protomaps assets (fonts + sprites)"
git clone -q --depth 1 https://github.com/protomaps/basemaps-assets.git "$WORK/assets"
rm -rf "$MAP_DIR/fonts" "$MAP_DIR/sprites"
cp -r "$WORK/assets/fonts" "$MAP_DIR/fonts"
mkdir -p "$MAP_DIR/sprites"
cp "$WORK/assets/sprites/v4/$THEME".* "$MAP_DIR/sprites/" 2>/dev/null || cp "$WORK/assets/sprites/$THEME".* "$MAP_DIR/sprites/"

say "generating style.json ($THEME)"
( cd "$WORK" && npm init -y >/dev/null 2>&1 && npm install @protomaps/basemaps --no-audit --no-fund >/dev/null 2>&1 )
cat > "$WORK/gen.mjs" <<JS
import { layers, namedFlavor } from '@protomaps/basemaps';
import { writeFileSync } from 'node:fs';
const theme = process.env.THEME || 'dark';
writeFileSync(process.argv[1], JSON.stringify({
  version: 8,
  glyphs: '/map/fonts/{fontstack}/{range}.pbf',
  sprite: '/map/sprites/' + theme,
  sources: { protomaps: { type: 'vector', url: 'pmtiles:///map/basemap.pmtiles',
    attribution: '<a href="https://protomaps.com">Protomaps</a> © <a href="https://openstreetmap.org">OpenStreetMap</a>' } },
  layers: layers('protomaps', namedFlavor(theme), { lang: 'en' }),
}));
JS
THEME="$THEME" node "$WORK/gen.mjs" "$MAP_DIR/style.json"

# --- 4. maplibre CSP worker (served same-origin, loaded via setWorkerUrl) -----------------
# The CSP worker sidesteps a bundler issue where the inlined-blob worker breaks (see
# GeoMapLibre.tsx). Copied from the installed package to public/vendor.
CSP_WORKER="$APP_DIR/node_modules/maplibre-gl/dist/maplibre-gl-csp-worker.js"
if [ -f "$CSP_WORKER" ]; then
    say "installing maplibre CSP worker -> public/vendor"
    mkdir -p "$APP_DIR/public/vendor"
    cp "$CSP_WORKER" "$APP_DIR/public/vendor/maplibre-gl-csp-worker.js"
fi

say "done. Set MYMATE_MAP_STYLE_URL=/map/style.json in .env, clear config cache, and reload."
