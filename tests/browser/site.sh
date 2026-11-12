#!/bin/sh
# A fresh, uninstalled site for the browser tests, made from the zip people download
# (core:install-zip), and served with PHP's built-in server. Playwright starts it
# (playwright.config.mjs) and installs it through the web installer.
#
#   sh tests/browser/site.sh        needs the built assets: npm run build
set -eu

root=$(cd "$(dirname "$0")/../.." && pwd)
work="$root/tests/browser/.site"
port=${KESTELLO_BROWSER_PORT:-8123}

[ -d "$root/core/resources/assets/build" ] || { echo "error: no built assets. Run npm run build first." >&2; exit 1; }
rm -rf "$work"
mkdir -p "$work"
php "$root/bin/cms" core:install-zip --out="$work/dist" > /dev/null
unzip -q "$work"/dist/kestello-*.zip -d "$work"

cd "$work/kestello"
# several workers: a page loads its scripts and API calls at once
PHP_CLI_SERVER_WORKERS=4 exec php -S "127.0.0.1:$port" -t public core/server.php
