#!/usr/bin/env bash
# Local MariaDB for the dev stores, data under dev/.cache/mariadb, listening on 127.0.0.1:$DB_PORT.
#   dev/db.sh start | stop
set -euo pipefail
DEV="$(cd "$(dirname "$0")" && pwd)"
# shellcheck disable=SC1091
source "$DEV/versions.env"
DATA="$DEV/.cache/mariadb"
SOCK="$DATA/mysqld.sock"

running() { mysqladmin -h "$DB_HOST" -P "$DB_PORT" -u root ping >/dev/null 2>&1; }

case "${1:-start}" in
  start)
    if running; then exit 0; fi
    if [ ! -d "$DATA/mysql" ]; then
      mkdir -p "$DATA"
      mariadb-install-db --user="$(whoami)" --datadir="$DATA" --auth-root-authentication-method=normal >/dev/null
    fi
    nohup mariadbd --user="$(whoami)" --datadir="$DATA" --socket="$SOCK" --pid-file="$DATA/mysqld.pid" --innodb-use-native-aio=0 --port="$DB_PORT" --bind-address=127.0.0.1 \
      --character-set-server=utf8mb4 --collation-server=utf8mb4_unicode_520_ci --sql-mode=STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION \
      > "$DATA/server.log" 2>&1 &
    for _ in $(seq 1 30); do running && break; sleep 1; done
    running || { echo "MariaDB did not start; see $DATA/server.log"; exit 1; }
    for h in localhost 127.0.0.1 %; do
      mysql -h "$DB_HOST" -P "$DB_PORT" -u root -e "CREATE USER IF NOT EXISTS '$DB_USER'@'$h' IDENTIFIED BY '$DB_PASS'; GRANT ALL ON \`ym\\_%\`.* TO '$DB_USER'@'$h';"
    done
    mysql -h "$DB_HOST" -P "$DB_PORT" -u root -e "DROP USER IF EXISTS ''@'localhost'; FLUSH PRIVILEGES;"
    ;;
  stop)
    running && mysqladmin -h "$DB_HOST" -P "$DB_PORT" -u root shutdown || true
    ;;
esac
