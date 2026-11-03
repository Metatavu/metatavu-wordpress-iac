#!/usr/bin/env bash
#
# Runs a command in the plugin's development image (dev/Dockerfile: PHP 8.4
# and Composer), with the repository mounted, so nothing PHP is needed locally.
#
# Usage: scripts/dev.sh composer install
#        scripts/dev.sh composer test
#        scripts/dev.sh composer lint

set -euo pipefail

root=$(cd "$(dirname "$0")/.." && pwd)
image=metatavu-wordpress-iac-dev
docker build -q -t "$image" "$root/dev" >/dev/null
exec docker run --rm -t -u "$(id -u):$(id -g)" -e HOME=/tmp -e COMPOSER_HOME=/tmp/composer \
	-v "$root:/app" -w /app "$image" "$@"
