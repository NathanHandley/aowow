#!/usr/bin/env bash
# EQWOW: refresh the aowow database viewer after deploying a new EQWOW worldserver + client patch build.
# Linux (Ubuntu 24.04) equivalent of eqwow-viewer-update.ps1.
#
#   ./eqwow-viewer-update.sh            full refresh: client data overlay + database regeneration + images
#   ./eqwow-viewer-update.sh --quick    database only (skip icon/map image regeneration - much faster)
#
# Adjust EXPORTS to wherever the converter output (WOWExports) is synced on this machine.
# NOTE: paths inside mpqdata are looked up case-insensitively by aowow itself, but on Linux the
#       overlay below must match the casing that already exists in setup/mpqdata/enUS.

set -euo pipefail

PHP="${PHP:-php}"
AOWOW="$(cd "$(dirname "$0")" && pwd)"
EXPORTS="${EXPORTS:-$HOME/EQWOW/WOWExports}"
MPQDATA="$AOWOW/setup/mpqdata/enUS"

QUICK=0
[ "${1:-}" = "--quick" ] && QUICK=1

echo "== EQWOW viewer update =="

if [ ! -d "$EXPORTS/MPQReady/DBFilesClient" ]; then
    echo "ERROR: converter export folder not found: $EXPORTS (set EXPORTS=...)" >&2
    exit 1
fi

# locate existing dirs regardless of casing (Icons vs ICONS, WorldMap vs WORLDMAP)
find_dir() { # parent, name
    find "$1" -maxdepth 1 -type d -iname "$2" | head -1
}
ICONS_DIR="$(find_dir "$MPQDATA/Interface" "Icons")"
WMAP_DIR="$(find_dir "$MPQDATA/Interface" "WorldMap")"

echo "[1/4] Overlaying converter output onto $MPQDATA"
cp -f "$EXPORTS/ExportedDBCFiles/"*.dbc       "$MPQDATA/DBFilesClient/"
cp -f "$EXPORTS/MPQReady/DBFilesClient/"*.dbc "$MPQDATA/DBFilesClient/"
cp -rf "$EXPORTS/MPQReady/Interface/ICONS/."    "$ICONS_DIR/"
cp -rf "$EXPORTS/MPQReady/Interface/WorldMap/." "$WMAP_DIR/"

echo "[2/4] Regenerating aowow database (php aowow --sql) - this takes a while"
cd "$AOWOW"
"$PHP" aowow --sql

if [ "$QUICK" = "1" ]; then
    echo "[3/4] Skipping image regeneration (--quick)"
else
    echo "[3/4] Regenerating icons and zone maps (php aowow --build=simpleimg,img-maps)"
    "$PHP" aowow --build=simpleimg,img-maps --force || echo "WARNING: --build reported errors (continuing)"
fi

echo "[4/4] Clearing page cache"
rm -rf "$AOWOW/cache/template/"* 2>/dev/null || true

echo "Done. Viewer data is up to date."
