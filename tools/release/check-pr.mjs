#!/usr/bin/env node
/**
 * node tools/release/check-pr.mjs title <title>
 * node tools/release/check-pr.mjs branch <branch> [<base branch>]
 * The pull request checks (.github/workflows/pr.yml): a Conventional Commit title, and a
 * branch named as CONTRIBUTING.md says. Prints why when the value doesn't pass.
 */
import { checkBranch, checkTarget, checkTitle } from './conventions.mjs';

const [what, value = '', base = null] = process.argv.slice(2);
const problem =
    what === 'title'
        ? checkTitle(value)
        : what === 'branch'
          ? (checkBranch(value) ?? (base ? checkTarget(value, base) : null))
          : 'usage: check-pr.mjs title <title> | branch <branch> [<base>]';
if (problem) {
    console.error(problem);
    process.exit(1);
}
console.log(`ok: ${value}`);
