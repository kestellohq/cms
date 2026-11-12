#!/bin/sh
# Install each of the last published releases from the zip people downloaded, add content,
# update it to this checkout with core:update, then open every admin screen. What a site
# on an earlier release goes through when it takes this one.
#
#   sh tests/browser/update.sh                  the last two releases
#   sh tests/browser/update.sh 0.1.0-rc.4 ...   these releases
#
# This checkout is built as a core update zip with a version just above its own, signed with a
# key made for the run; the test site trusts that key in place of the release key.
# Needs the built assets (npm run build), curl, unzip and Playwright's Chromium.
set -eu

root=$(cd "$(dirname "$0")/../.." && pwd)
work="$root/tests/browser/.update"
repo=${KESTELLO_REPOSITORY:-kestellohq/cms}
# just above this checkout's version, so plugins and themes that support it still do:
# 0.1.0-rc.5 becomes 0.1.0-rc.5.update-test, 0.1.0 becomes 0.1.1-update-test
version=$(php -r '$v = trim(file_get_contents($argv[1])); if (str_contains($v, "-")) { echo "$v.update-test"; } else { [$a, $b, $c] = explode(".", $v); echo "$a.$b." . ($c + 1) . "-update-test"; }' "$root/core/VERSION")

[ -d "$root/core/resources/assets/build" ] || { echo "error: no built assets. Run npm run build first." >&2; exit 1; }
rm -rf "$work"
mkdir -p "$work"

if [ $# -eq 0 ]; then
    # GITHUB_TOKEN, when set (CI), only avoids the API's rate limit for anonymous calls
    set -- $(curl -fsSL ${GITHUB_TOKEN:+-H "Authorization: Bearer $GITHUB_TOKEN"} \
        "https://api.github.com/repos/$repo/releases?per_page=2" \
        | php -r 'foreach (json_decode(stream_get_contents(STDIN), true) as $r) echo ltrim($r["tag_name"], "v"), "\n";')
fi
[ $# -gt 0 ] || { echo "error: no releases found on $repo." >&2; exit 1; }

# this checkout as an update
build() { "$@" >> "$work/build.log" 2>&1 || { cat "$work/build.log" >&2; exit 1; }; }
build php "$root/bin/cms" package:keygen --out="$work/update.key" --name="Update test"
key=$(grep -o "'public_key' => '[^']*'" "$work/build.log" | cut -d"'" -f4)
cp -R "$root/core" "$work/core"
printf '%s\n' "$version" > "$work/core/VERSION"
build php "$root/bin/cms" core:release "$work/core" --out="$work" --key="$work/update.key"
zip="$work/kestello-core-$version.zip"
[ -f "$zip" ] || { echo "error: core:release made no $zip." >&2; exit 1; }

for from in "$@"; do
    echo "== from $from to this checkout"
    curl -fsSL -o "$work/kestello-$from.zip" "https://github.com/$repo/releases/download/v$from/kestello-$from.zip"
    KESTELLO_BROWSER_ZIP="$work/kestello-$from.zip" KESTELLO_UPDATE_ZIP="$zip" KESTELLO_UPDATE_KEY="$key" \
        KESTELLO_UPDATE_VERSION="$version" npx playwright test -c "$root/tests/browser/playwright.config.mjs"
done
