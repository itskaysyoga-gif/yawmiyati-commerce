#!/usr/bin/env bash
# Back up one store: database (gzipped SQL) + uploads + the shared consent ledger. Secrets are not in the database or uploads; wp-config.php and
# environment variables are NOT copied (they live in the host's panel and the password manager).
#   deploy/backup.sh <store-path> <backup-dir>      e.g. deploy/backup.sh ~/www/yawmiyati.shop/public_html/eg ~/backups
# Keep 14 daily copies on the host and 1 weekly copy off the host (SiteGround daily backups are a second layer).
set -euo pipefail
STORE="$1"; OUT="$2"; WP="${WP:-wp}"
STAMP="$(date -u +%Y%m%dT%H%M%SZ)"; NAME="$(basename "$STORE")-$STAMP"
mkdir -p "$OUT"
( cd "$STORE" && $WP db export - --single-transaction --quiet ) | gzip > "$OUT/$NAME.sql.gz"
tar -czf "$OUT/$NAME.uploads.tgz" -C "$STORE/wp-content" uploads
# Shared consent ledger (one database for all stores): exported with every store's backup. A restore merges it and can
# never remove or shorten a withdrawal, so restoring any store's backup is safe for the others.
( cd "$STORE" && $WP ym consent export "$OUT/$NAME.consent.json" --quiet )
( cd "$OUT" && sha256sum "$NAME.sql.gz" "$NAME.uploads.tgz" "$NAME.consent.json" > "$NAME.sha256" )
ls -1t "$OUT"/"$(basename "$STORE")"-*.sql.gz 2>/dev/null | tail -n +15 | while read -r f; do rm -f "$f" "${f%.sql.gz}.uploads.tgz" "${f%.sql.gz}.consent.json" "${f%.sql.gz}.sha256"; done
echo "$OUT/$NAME"
