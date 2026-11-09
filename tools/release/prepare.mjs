#!/usr/bin/env node
/**
 * npm run release:prepare -- X.Y.Z   (on a release/X.Y.Z or hotfix/X.Y.Z branch)
 *
 * Sets core/VERSION, releases the changelog's Unreleased section as X.Y.Z dated today,
 * and refreshes the translation template and editor helpers. A pre-release (X.Y.Z-rc.1)
 * leaves the changelog as it is: its notes are the Unreleased section. Then: npm run build,
 * npm run check, and commit "chore(release): X.Y.Z". See CONTRIBUTING.md.
 */
import { execFileSync } from 'node:child_process';
import { readFileSync, writeFileSync } from 'node:fs';
import path from 'node:path';
import { compareVersions, isPrerelease, parseVersion, releaseChangelog, releaseNotes } from './changelog.mjs';

const root = path.resolve(import.meta.dirname, '../..');
const fail = (message) => {
    console.error(`release:prepare: ${message}`);
    process.exit(1);
};

const version = process.argv[2];
if (!version) fail('which version? npm run release:prepare -- 1.2.0');
try {
    parseVersion(version);
} catch (e) {
    fail(e.message);
}

const current = readFileSync(path.join(root, 'core/VERSION'), 'utf8').trim();
if (compareVersions(version, current) <= 0 && version !== current) fail(`${version} is not newer than the current ${current}`);

const branch = execFileSync('git', ['rev-parse', '--abbrev-ref', 'HEAD'], { cwd: root, encoding: 'utf8' }).trim();
if (branch !== `release/${version}` && branch !== `hotfix/${version}`) fail(`switch to release/${version} (or hotfix/${version}) first; this is ${branch}`);

const date = new Date().toISOString().slice(0, 10);
const changelogPath = path.join(root, 'CHANGELOG.md');
const pre = isPrerelease(version);
let changelog;
try {
    const text = readFileSync(changelogPath, 'utf8');
    if (pre)
        releaseNotes(text, version); // refuses an empty Unreleased section
    else changelog = releaseChangelog(text, version, date);
} catch (e) {
    fail(e.message);
}

writeFileSync(path.join(root, 'core/VERSION'), `${version}\n`);
console.log(`core/VERSION   ${current} -> ${version}`);
if (pre) {
    console.log("CHANGELOG.md   unchanged: a pre-release's notes are the Unreleased section");
} else {
    writeFileSync(changelogPath, changelog);
    console.log(`CHANGELOG.md   Unreleased -> [${version}] - ${date}`);
}

for (const args of [
    ['bin/cms', 'i18n:extract'],
    ['bin/cms', 'ide:helpers'],
]) {
    execFileSync('sh', ['tools/php', ...args], { cwd: root, stdio: 'inherit' });
}
console.log(`\nNext: npm run build && npm run check, then commit "chore(release): ${version}" and open a pull request into main.`);
