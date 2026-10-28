#!/usr/bin/env bash
# Start both local stores in the background: Egypt :8081, Iraq :8082
DEV="$(cd "$(dirname "$0")" && pwd)"
for pair in eg:8081 iq:8082; do
  cc=${pair%%:*}; port=${pair##*:}
  (cd "$DEV/sites/$cc" && nohup php -S 0.0.0.0:$port -t . > "$DEV/.cache/serve-$cc.log" 2>&1 &)
  echo "$cc -> http://localhost:$port"
done
