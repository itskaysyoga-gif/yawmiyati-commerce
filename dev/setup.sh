#!/usr/bin/env bash
# Local development stores: one WordPress + WooCommerce install per country, SQLite database,
# PHP's built-in server. For building and testing only; production runs on SiteGround.
#
#   dev/setup.sh            install (idempotent)
#   dev/serve.sh            start eg on :8081 and iq on :8082
#
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
DEV="$ROOT/dev"
CACHE="$DEV/.cache"
mkdir -p "$CACHE" "$DEV/sites"

fetch() { [ -f "$CACHE/$2" ] || curl -fsSL --retry 3 -o "$CACHE/$2" "$1"; }

fetch https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar wp-cli.phar
fetch https://wordpress.org/latest.zip wordpress.zip
fetch https://downloads.wordpress.org/plugin/woocommerce.latest-stable.zip woocommerce.zip
fetch https://downloads.wordpress.org/plugin/sqlite-database-integration.latest-stable.zip sqlite.zip
WP="php -d memory_limit=512M $CACHE/wp-cli.phar --allow-root"

install_site() {
  local cc="$1" port="$2" currency="$3" country="$4" title="$5" decimals="$6"
  local dir="$DEV/sites/$cc"
  if [ -f "$dir/wp-config.php" ]; then echo "$cc: already installed"; return; fi
  mkdir -p "$dir"
  unzip -q -o "$CACHE/wordpress.zip" -d "$DEV/sites/tmp-$cc" && cp -r "$DEV/sites/tmp-$cc/wordpress/." "$dir" && rm -rf "$DEV/sites/tmp-$cc"
  unzip -q -o "$CACHE/woocommerce.zip" -d "$dir/wp-content/plugins"
  unzip -q -o "$CACHE/sqlite.zip" -d "$dir/wp-content/plugins"
  cp "$dir/wp-content/plugins/sqlite-database-integration/db.copy" "$dir/wp-content/db.php"
  sed -i "s#{SQLITE_IMPLEMENTATION_FOLDER_PATH}#$dir/wp-content/plugins/sqlite-database-integration#; s#{SQLITE_PLUGIN}#sqlite-database-integration/load.php#" "$dir/wp-content/db.php"
  ( cd "$dir"
    $WP config create --dbname=wp --dbuser=wp --dbpass=wp --skip-check --quiet
    $WP config set WP_DEBUG true --raw --quiet
    $WP config set WP_ENVIRONMENT_TYPE local --quiet
    $WP core install --url="http://localhost:$port" --title="$title" --admin_user=admin \
        --admin_password="$(openssl rand -hex 12)" --admin_email=dev@example.test --skip-email --quiet
    $WP language core install ar --activate --quiet || true
    $WP plugin activate woocommerce --quiet
    $WP language plugin install woocommerce ar --quiet || true
    $WP option update woocommerce_currency "$currency" --quiet
    $WP option update woocommerce_price_num_decimals "$decimals" --quiet
    $WP option update woocommerce_default_country "$country" --quiet
    $WP option update woocommerce_allowed_countries specific --quiet
    $WP option update woocommerce_specific_allowed_countries "[\"$country\"]" --format=json --quiet
    $WP option update woocommerce_ship_to_countries specific --quiet
    $WP option update woocommerce_specific_ship_to_countries "[\"$country\"]" --format=json --quiet
    $WP option update woocommerce_onboarding_profile '{"skipped":true}' --format=json --quiet
    ln -sfn "$ROOT/theme/yawmiyati" wp-content/themes/yawmiyati 2>/dev/null || true
    ln -sfn "$ROOT/plugin/yawmiyati-commerce" wp-content/plugins/yawmiyati-commerce 2>/dev/null || true
  )
  echo "$cc: installed at http://localhost:$port (admin password reset with: dev/wp.sh $cc user update admin --user_pass=...)"
}

install_site eg 8081 EGP EG "يومياتي مصر" 0
install_site iq 8082 IQD IQ "يومياتي العراق" 0
