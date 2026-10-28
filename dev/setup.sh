#!/usr/bin/env bash
# Local development stores: one WordPress + WooCommerce install per country on MariaDB,
# served by PHP's built-in server. Production runs on SiteGround; this is for building and testing.
#
#   dev/setup.sh          install everything (idempotent); pinned versions in dev/versions.env
#   dev/serve.sh          start MariaDB and both stores (eg :8081, iq :8082)
#   dev/wp.sh eg ...      WP-CLI against a store
#   dev/reset.sh          drop both stores and reinstall
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
DEV="$ROOT/dev"
CACHE="$DEV/.cache"
# shellcheck disable=SC1091
source "$DEV/versions.env"
mkdir -p "$CACHE" "$DEV/sites"

fetch() { # url file sha256
  if [ ! -f "$CACHE/$2" ]; then curl -fsSL --retry 3 -o "$CACHE/$2" "$1"; fi
  if [ -n "${3:-}" ]; then echo "$3  $CACHE/$2" | sha256sum -c --quiet - || { echo "checksum mismatch: $2"; exit 1; }; fi
}
fetch "$WPCLI_URL" wp-cli.phar "$WPCLI_SHA256"
fetch "$WP_URL" wordpress.zip "$WP_SHA256"
fetch "$WC_URL" woocommerce.zip "$WC_SHA256"
fetch "$WP_AR_URL" ar-core.zip
fetch "$WC_AR_URL" ar-wc.zip

"$DEV/db.sh" start
WP="php -d memory_limit=512M $CACHE/wp-cli.phar --allow-root"

install_site() {
  local cc="$1" port="$2"
  local dir="$DEV/sites/$cc" db="ym_$cc"
  if [ -f "$dir/wp-config.php" ]; then echo "$cc: already installed"; return; fi
  mysql -h "$DB_HOST" -P "$DB_PORT" -u root -e "CREATE DATABASE IF NOT EXISTS $db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci;"
  mkdir -p "$dir"
  unzip -q -o "$CACHE/wordpress.zip" -d "$DEV/sites/tmp-$cc" && cp -r "$DEV/sites/tmp-$cc/wordpress/." "$dir" && rm -rf "$DEV/sites/tmp-$cc"
  unzip -q -o "$CACHE/woocommerce.zip" -d "$dir/wp-content/plugins"
  mkdir -p "$dir/wp-content/languages/plugins"
  unzip -q -o "$CACHE/ar-core.zip" -d "$dir/wp-content/languages"
  unzip -q -o "$CACHE/ar-wc.zip" -d "$dir/wp-content/languages/plugins"
  ln -sfn "$ROOT/theme/yawmiyati" "$dir/wp-content/themes/yawmiyati"
  ln -sfn "$ROOT/plugin/yawmiyati-commerce" "$dir/wp-content/plugins/yawmiyati-commerce"
  ( cd "$dir"
    $WP config create --dbname="$db" --dbuser="$DB_USER" --dbpass="$DB_PASS" --dbhost="$DB_HOST:$DB_PORT" --dbcharset=utf8mb4 --skip-check --quiet
    $WP config set WP_DEBUG true --raw --quiet
    $WP config set WP_DEBUG_LOG true --raw --quiet
    $WP config set WP_DEBUG_DISPLAY false --raw --quiet
    $WP config set WP_ENVIRONMENT_TYPE local --quiet
    $WP config set AUTOMATIC_UPDATER_DISABLED true --raw --quiet
    $WP config set WP_HTTP_BLOCK_EXTERNAL true --raw --quiet
    $WP core install --url="http://localhost:$port" --title="يومياتي" --admin_user=admin \
        --admin_password="$(openssl rand -hex 12)" --admin_email=dev@example.test --skip-email --quiet
    $WP option update WPLANG ar --quiet
    $WP plugin activate woocommerce --quiet
    $WP theme activate yawmiyati --quiet
    $WP plugin activate yawmiyati-commerce --quiet
    $WP ym setup --country="${cc^^}" --quiet
    $WP ym seed --quiet
  )
  echo "$cc: installed at http://localhost:$port"
}

install_site eg 8081
install_site iq 8082
