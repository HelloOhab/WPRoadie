#!/usr/bin/env bash
# Builds dist/wproadie.zip: the installable plugin, with the admin app
# compiled and without development files.
#
#   bash bin/build-zip.sh

set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

if [ ! -d node_modules ]; then
	npm ci
fi
npm run build

STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT
DEST="$STAGE/wproadie"
mkdir -p "$DEST"

cp wproadie.php uninstall.php README.md LICENSE "$DEST/"
cp -R src "$DEST/src"
mkdir -p "$DEST/admin" "$DEST/languages"
cp -R admin/build "$DEST/admin/build"
cp -R languages/. "$DEST/languages/" 2>/dev/null || true
rm -f "$DEST/languages/.gitkeep"

VERSION="$(sed -n "s/^ \* Version: *//p" wproadie.php | tr -d '[:space:]')"

mkdir -p dist
rm -f dist/wproadie.zip
(cd "$STAGE" && zip -qr "$ROOT/dist/wproadie.zip" wproadie)

echo "Built dist/wproadie.zip (WPRoadie $VERSION)"
