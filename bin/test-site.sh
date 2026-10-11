#!/usr/bin/env bash
# Creates a throwaway WordPress site on SQLite for running WPRoadie's tests,
# with no Docker or MySQL needed. Used by CI; handy locally too.
#
#   bash bin/test-site.sh <dir> [wordpress-version]
#
# Then:
#   WP="<dir>/wp-cli.phar --path=<dir>/wordpress"
#   php $WP eval-file tests/integration/run.php --user=admin
#   WP="php $WP" bash tests/smoke.sh

set -euo pipefail

DIR="${1:?Usage: bash bin/test-site.sh <dir> [wordpress-version]}"
WP_VERSION="${2:-6.9}"
ADAPTER_ZIP="https://github.com/WordPress/mcp-adapter/releases/download/v0.7.0/mcp-adapter.zip"
SQLITE_TAG="v3.1.0"
PLUGIN_DIR="$(cd "$(dirname "$0")/.." && pwd)"

mkdir -p "$DIR"
DIR="$(cd "$DIR" && pwd)"
WPCLI="$DIR/wp-cli.phar"
SITE="$DIR/wordpress"

if [ ! -f "$WPCLI" ]; then
	curl -sSL -o "$WPCLI" https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar
fi
wp() { php "$WPCLI" --path="$SITE" --allow-root "$@"; }

if [ ! -f "$SITE/wp-includes/version.php" ]; then
	git clone -q --depth 1 --branch "$WP_VERSION" https://github.com/WordPress/WordPress.git "$SITE"
fi

PLUGINS="$SITE/wp-content/plugins"
if [ ! -d "$DIR/sqlite" ]; then
	git clone -q --depth 1 --branch "$SQLITE_TAG" https://github.com/WordPress/sqlite-database-integration.git "$DIR/sqlite"
fi
ln -sfn "$DIR/sqlite/packages/plugin-sqlite-database-integration" "$PLUGINS/sqlite-database-integration"
sed "s#{SQLITE_IMPLEMENTATION_FOLDER_PATH}#$PLUGINS/sqlite-database-integration#; s#{SQLITE_PLUGIN}#sqlite-database-integration/load.php#" \
	"$PLUGINS/sqlite-database-integration/db.copy" > "$SITE/wp-content/db.php"

if [ ! -d "$PLUGINS/mcp-adapter" ]; then
	curl -sSL -o "$DIR/mcp-adapter.zip" "$ADAPTER_ZIP"
	unzip -q -o "$DIR/mcp-adapter.zip" -d "$PLUGINS"
fi
ln -sfn "$PLUGIN_DIR" "$PLUGINS/wproadie"

if [ ! -f "$SITE/wp-config.php" ]; then
	wp config create --dbname=wp --dbuser=wp --dbpass=wp --skip-check --extra-php <<'PHP'
define( 'WP_ENVIRONMENT_TYPE', 'staging' );
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );
define( 'WP_DEBUG_DISPLAY', false );
PHP
fi

if ! wp core is-installed 2>/dev/null; then
	wp core install --url=http://127.0.0.1:8899 --title="WPRoadie Test" --admin_user=admin \
		--admin_password=admin --admin_email=admin@example.com --skip-email
fi

wp plugin activate mcp-adapter wproadie
wp wproadie grant admin
wp wproadie status
