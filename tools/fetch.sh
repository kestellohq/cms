#!/bin/sh
# Fetch the pinned dev tools listed in tools/tools.lock into tools/bin/, verifying each
# SHA-256. Re-running is cheap: a tool already present with the right checksum is kept.
# Usage: sh tools/fetch.sh
set -eu

here=$(cd "$(dirname "$0")" && pwd)
bin="$here/bin"
mkdir -p "$bin"

sha256() {
    if command -v sha256sum >/dev/null 2>&1; then sha256sum "$1" | cut -d' ' -f1
    else shasum -a 256 "$1" | cut -d' ' -f1; fi
}

grep -v '^#' "$here/tools.lock" | while read -r name version sum url; do
    [ -n "$name" ] || continue
    target="$bin/$name.phar"
    if [ -f "$target" ] && [ "$(sha256 "$target")" = "$sum" ]; then
        continue
    fi
    echo "Fetching $name $version"
    tmp="$target.download"
    curl -fsSL --retry 3 -o "$tmp" "$url"
    actual=$(sha256 "$tmp")
    if [ "$actual" != "$sum" ]; then
        rm -f "$tmp"
        echo "error: $name $version checksum mismatch (expected $sum, got $actual). Not installed." >&2
        exit 1
    fi
    mv "$tmp" "$target"
done
