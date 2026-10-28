#!/usr/bin/env bash
# Start MariaDB and both local stores in the background: Egypt :8081, Iraq :8082.
# Eight PHP workers per store, so concurrent requests really run in parallel (the concurrency tests rely on it).
DEV="$(cd "$(dirname "$0")" && pwd)"
"$DEV/db.sh" start
for pair in eg:8081 iq:8082; do
  cc=${pair%%:*}; port=${pair##*:}
  if ! curl -s -o /dev/null "http://localhost:$port/"; then
    (cd "$DEV/sites/$cc" && PHP_CLI_SERVER_WORKERS=8 nohup php -d memory_limit=512M -S 127.0.0.1:$port -t . "$DEV/router.php" > "$DEV/.cache/serve-$cc.log" 2>&1 &)
  fi
  echo "$cc -> http://localhost:$port"
done
