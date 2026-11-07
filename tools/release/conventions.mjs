/**
 * Names CONTRIBUTING.md asks for, as checks that explain themselves (pure; tested in
 * tests/js/release.test.mjs). Each returns null when the value passes, else the reason.
 */

export const TYPES = ['feat', 'fix', 'docs', 'style', 'refactor', 'perf', 'test', 'build', 'ci', 'chore', 'revert'];

const TITLE = new RegExp(`^(${TYPES.join('|')})(\\([a-z0-9][a-z0-9-]*\\))?(!)?: (\\S.*)$`);

/** A Conventional Commit summary: "fix(media): keep the alt text when an image is replaced". */
export function checkTitle(title) {
    const m = TITLE.exec(title);
    if (!m) {
        return `"${title}" is not a Conventional Commit title. Write "<type>(<scope>): <summary>", where type is one of ${TYPES.join(', ')}. Example: "fix(media): keep the alt text when an image is replaced".`;
    }
    const summary = m[4];
    if (title.length > 72) return `The title is ${title.length} characters long; keep it to 72.`;
    if (/\.$/.test(summary)) return 'End the title without a full stop.';
    return null;
}

const BRANCH =
    /^(?:(?:feature|fix|docs|chore|refactor|test|perf|ci|build|style|revert)\/[a-z0-9][a-z0-9._-]*|(?:release|hotfix)\/(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)(?:-[0-9a-z.-]+)?|dependabot\/.+)$/;

/** A branch name as CONTRIBUTING.md lists them: feature/412-scheduled-publishing, release/1.2.0. */
export function checkBranch(branch) {
    if (BRANCH.test(branch)) return null;
    return `"${branch}" is not a branch name Kestello uses. Use feature/<issue>-<slug>, fix/<issue>-<slug>, docs/, chore/, refactor/, test/, perf/, ci/, build/ or style/<slug> (lowercase), or release/X.Y.Z or hotfix/X.Y.Z.`;
}

/** Release and hotfix branches go into main; everything else into develop. */
export function checkTarget(branch, base) {
    const toMain = /^(release|hotfix)\//.test(branch);
    if (base === 'main' && !toMain) return `Only release/X.Y.Z and hotfix/X.Y.Z branches merge into main. Point this pull request at develop.`;
    if (base !== 'main' && base !== 'develop' && !base.startsWith('release/') && !base.startsWith('hotfix/')) {
        return `Pull requests go into develop (or main for a release or hotfix), not "${base}".`;
    }
    if (toMain && base !== 'main') return `${branch} merges into main; develop gets it from the back-merge afterwards.`;
    return null;
}
