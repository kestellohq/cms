#!/usr/bin/env node
/**
 * node tools/release/notes.mjs X.Y.Z [CHANGELOG.md]
 * Prints that version's changelog section: the release workflow uses it as the GitHub release notes.
 */
import { readFileSync } from 'node:fs';
import { releaseNotes } from './changelog.mjs';

const [version, file = 'CHANGELOG.md'] = process.argv.slice(2);
try {
    if (!version) throw new Error('usage: node tools/release/notes.mjs X.Y.Z [CHANGELOG.md]');
    process.stdout.write(releaseNotes(readFileSync(file, 'utf8'), version));
} catch (e) {
    console.error(`notes: ${e.message}`);
    process.exit(1);
}
