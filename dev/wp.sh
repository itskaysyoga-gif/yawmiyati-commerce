#!/usr/bin/env bash
# WP-CLI for a local store:  dev/wp.sh eg plugin list
DEV="$(cd "$(dirname "$0")" && pwd)"; cc="$1"; shift
cd "$DEV/sites/$cc" && php -d memory_limit=512M "$DEV/.cache/wp-cli.phar" --allow-root "$@"
