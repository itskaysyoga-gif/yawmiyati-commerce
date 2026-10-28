#!/usr/bin/env bash
# Drop both local stores and reinstall from scratch.
set -euo pipefail
DEV="$(cd "$(dirname "$0")" && pwd)"
source "$DEV/versions.env"
pkill -f "php -d memory_limit=512M -S 127.0.0.1:808" || true
"$DEV/db.sh" start
for cc in eg iq; do mysql -h "$DB_HOST" -P "$DB_PORT" -u root -e "DROP DATABASE IF EXISTS ym_$cc;"; rm -rf "$DEV/sites/$cc"; done
"$DEV/setup.sh"
