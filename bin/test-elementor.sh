#!/usr/bin/env bash
# Adds Elementor to a test site made by bin/test-site.sh, so the Elementor
# tools are tested against the real plugin. Without it, the Elementor
# tests run on fixture data only.
#
#   bash bin/test-elementor.sh <dir> [tag]
#
# Fetches only Elementor's PHP from GitHub (a sparse checkout of a release
# tag, about 25 MB): wordpress.org downloads are not always reachable from
# CI, and the PHP is all the tests need. If the download fails, the site
# is left without Elementor and the script still succeeds.

set -uo pipefail

DIR="${1:?Usage: bash bin/test-elementor.sh <dir> [tag]}"
TAG="${2:-v3.35.9}"
DIR="$(cd "$DIR" && pwd)"
SRC="$DIR/elementor-$TAG"
SITE="$DIR/wordpress"
wp() { php "$DIR/wp-cli.phar" --path="$SITE" --allow-root "$@"; }

if [ ! -f "$SRC/elementor.php" ]; then
	rm -rf "$SRC"
	if ! git clone -q --depth 1 --filter=blob:none --sparse --branch "$TAG" https://github.com/elementor/elementor.git "$SRC" 2>/dev/null \
		|| ! git -C "$SRC" sparse-checkout set --no-cone '/*.php' '/includes/' '/core/' '/modules/' '/app/' '/data/' '/migrations/' '/vendor_prefixed/' '/assets/lib/font-awesome/json/' '/assets/images/'; then
		echo "Could not download Elementor $TAG; the Elementor tests will run on fixtures only." >&2
		rm -rf "$SRC"
		exit 0
	fi
fi

ln -sfn "$SRC" "$SITE/wp-content/plugins/elementor"
wp plugin activate elementor
wp eval 'echo "Elementor " . ELEMENTOR_VERSION . "\n";'
