#!/bin/sh
# A fresh, uninstalled site for the browser tests, made from the zip people download
# (core:install-zip), and served with PHP's built-in server. Playwright starts it
# (playwright.config.mjs) and installs it through the web installer.
#
#   sh tests/browser/site.sh        needs the built assets: npm run build
#
# KESTELLO_BROWSER_ZIP=<install zip> serves that zip instead, for instance a published
# release that update.sh then updates to this checkout.
set -eu

root=$(cd "$(dirname "$0")/../.." && pwd)
work="$root/tests/browser/.site"
port=${KESTELLO_BROWSER_PORT:-8123}

rm -rf "$work"
mkdir -p "$work"
if [ -n "${KESTELLO_BROWSER_ZIP:-}" ]; then
    unzip -q "$KESTELLO_BROWSER_ZIP" -d "$work"
else
    [ -d "$root/core/resources/assets/build" ] || { echo "error: no built assets. Run npm run build first." >&2; exit 1; }
    php "$root/bin/cms" core:install-zip --out="$work/dist" > /dev/null
    unzip -q "$work"/dist/kestello-*.zip -d "$work"
fi

cd "$work/kestello"
# several workers: a page loads its scripts and API calls at once
PHP_CLI_SERVER_WORKERS=4 exec php -S "127.0.0.1:$port" -t public core/server.php
