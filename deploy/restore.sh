#!/usr/bin/env bash
# Restore a backup made by deploy/backup.sh into a store (checksums verified first).
#   deploy/restore.sh <backup-prefix> <store-path>   e.g. deploy/restore.sh ~/backups/eg-20261103T020000Z ~/www/.../eg
# Restoring production: put the store in maintenance first (wp maintenance-mode activate), restore, check
# `wp ym launch-check` and `wp ym ops`, then deactivate. Orders placed after the backup are lost: export them first.
set -euo pipefail
PREFIX="$1"; STORE="$2"; WP="${WP:-wp}"
( cd "$(dirname "$PREFIX")" && sha256sum -c --quiet "$(basename "$PREFIX").sha256" )
gunzip -c "$PREFIX.sql.gz" | ( cd "$STORE" && $WP db import - --quiet )
rm -rf "$STORE/wp-content/uploads.restore-old" && [ -d "$STORE/wp-content/uploads" ] && mv "$STORE/wp-content/uploads" "$STORE/wp-content/uploads.restore-old" || true
tar -xzf "$PREFIX.uploads.tgz" -C "$STORE/wp-content"
( cd "$STORE" && $WP cache flush --quiet && $WP rewrite flush --quiet )
echo "restored $PREFIX into $STORE"
