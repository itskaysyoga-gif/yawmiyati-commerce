#!/usr/bin/env bash
# Restore drill (local): back up the Egypt store, restore it into a fresh scratch store, compare what matters.
set -euo pipefail
DEV="$(cd "$(dirname "$0")" && pwd)"; ROOT="$(dirname "$DEV")"; source "$DEV/versions.env"
WPP="php -d memory_limit=512M $DEV/.cache/wp-cli.phar --allow-root"
BK="$DEV/.cache/backups"; SCR="$DEV/sites/restore-drill"
rm -rf "$SCR" "$BK"; mkdir -p "$BK"
PREFIX="$(WP="$WPP" "$ROOT/deploy/backup.sh" "$DEV/sites/eg" "$BK" | tail -1)"
mysql -h "$DB_HOST" -P "$DB_PORT" -u root -e "DROP DATABASE IF EXISTS ym_restore; CREATE DATABASE ym_restore CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci; GRANT ALL ON ym_restore.* TO '$DB_USER'@'%';" 2>/dev/null || mysql -h "$DB_HOST" -P "$DB_PORT" -u root -e "DROP DATABASE IF EXISTS ym_restore; CREATE DATABASE ym_restore CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci;"
cp -a "$DEV/sites/eg" "$SCR"; rm -rf "$SCR/wp-content/uploads"
sed -i "s/'ym_eg'/'ym_restore'/" "$SCR/wp-config.php"
# The scratch store gets its own empty shared ledger, so the drill proves the ledger comes back from the backup.
mysql -h "$DB_HOST" -P "$DB_PORT" -u root -e "DROP DATABASE IF EXISTS ym_shared_restore; CREATE DATABASE ym_shared_restore;"
sed -i "s/'ym_shared'/'ym_shared_restore'/" "$SCR/wp-config.php"
WP="$WPP" "$ROOT/deploy/restore.sh" "$PREFIX" "$SCR" >/dev/null
q() { ( cd "$1" && $WPP eval "$2" 2>/dev/null ); }
CHECK='$l = YM_Consent::export(); echo md5(wp_json_encode($l ? $l["ledger"] : null)), "|", count($l ? $l["ledger"] : array()), "|", count(wc_get_orders(array("limit"=>-1,"return"=>"ids","status"=>array_keys(wc_get_order_statuses())))), "|", count(wc_get_products(array("limit"=>-1,"return"=>"ids","status"=>"any"))), "|", get_option("ym_country"), "|", md5(wp_json_encode(get_option("ym_ledger"))), "|", count(get_posts(array("post_type"=>"attachment","numberposts"=>-1,"fields"=>"ids")));'
A="$(q "$DEV/sites/eg" "$CHECK")"; B="$(q "$SCR" "$CHECK")"
echo "original: $A"; echo "restored: $B"
ls "$SCR/wp-content/uploads" >/dev/null
rm -rf "$SCR"; mysql -h "$DB_HOST" -P "$DB_PORT" -u root -e "DROP DATABASE ym_restore; DROP DATABASE ym_shared_restore;"
[ "$A" = "$B" ] && echo "RESTORE DRILL PASSED" || { echo "RESTORE DRILL FAILED"; exit 1; }
