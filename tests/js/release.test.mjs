import test from 'node:test';
import assert from 'node:assert/strict';
import { compareVersions, isPrerelease, parseVersion, releaseChangelog, releaseNotes, unreleasedHasEntries } from '../../tools/release/changelog.mjs';
import { checkBranch, checkTarget, checkTitle } from '../../tools/release/conventions.mjs';

const CHANGELOG = `# Changelog

Intro text.

## [Unreleased]

### Added

- Scheduled publishing (#412).

### Fixed

- Images keep their alt text (#388).

## [1.1.0] - 2026-10-01

### Added

- Forms.

## [1.0.0] - 2026-09-01

- First release.

[Unreleased]: https://github.com/kestellohq/cms/compare/v1.1.0...develop
[1.1.0]: https://github.com/kestellohq/cms/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/kestellohq/cms/releases/tag/v1.0.0
`;

test('versions: SemVer, pre-releases and their order', () => {
    assert.deepEqual(parseVersion('1.2.3-rc.1'), { major: 1, minor: 2, patch: 3, pre: 'rc.1' });
    assert.throws(() => parseVersion('v1.2.3'), /not a version/);
    assert.throws(() => parseVersion('1.02.3'), /not a version/);
    assert.equal(isPrerelease('2.0.0-beta.2'), true);
    assert.equal(isPrerelease('2.0.0'), false);
    const sorted = ['1.0.0', '1.0.0-rc.1', '1.0.0-beta.11', '1.0.0-beta.2', '0.9.9', '1.0.0-alpha', '1.0.1'].sort(compareVersions);
    assert.deepEqual(sorted, ['0.9.9', '1.0.0-alpha', '1.0.0-beta.2', '1.0.0-beta.11', '1.0.0-rc.1', '1.0.0', '1.0.1']);
});

test('releasing the changelog moves Unreleased under the version and updates the links', () => {
    const out = releaseChangelog(CHANGELOG, '1.2.0', '2026-11-06');
    assert.match(
        out,
        /## \[Unreleased\]\n\n## \[1\.2\.0\] - 2026-11-06\n\n### Added\n\n- Scheduled publishing \(#412\)\.\n\n### Fixed\n\n- Images keep their alt text \(#388\)\.\n\n## \[1\.1\.0\] - 2026-10-01/,
    );
    assert.ok(
        out.endsWith(
            '[Unreleased]: https://github.com/kestellohq/cms/compare/v1.2.0...develop\n' +
                '[1.2.0]: https://github.com/kestellohq/cms/compare/v1.1.0...v1.2.0\n' +
                '[1.1.0]: https://github.com/kestellohq/cms/compare/v1.0.0...v1.1.0\n' +
                '[1.0.0]: https://github.com/kestellohq/cms/releases/tag/v1.0.0\n',
        ),
        out,
    );
    assert.equal(unreleasedHasEntries(out), false, 'a fresh, empty Unreleased');
    assert.equal(releaseNotes(out, '1.2.0'), '### Added\n\n- Scheduled publishing (#412).\n\n### Fixed\n\n- Images keep their alt text (#388).\n');
    assert.equal(releaseNotes(out, '1.0.0'), '- First release.\n', 'the last section ends before the links');
});

test('the first release links to its tag', () => {
    const out = releaseChangelog(
        '# Changelog\n\n## [Unreleased]\n\n- Everything.\n\n[Unreleased]: https://github.com/kestellohq/cms/commits/develop\n',
        '0.1.0',
        '2026-11-06',
    );
    assert.ok(
        out.endsWith(
            '[Unreleased]: https://github.com/kestellohq/cms/compare/v0.1.0...develop\n[0.1.0]: https://github.com/kestellohq/cms/releases/tag/v0.1.0\n',
        ),
        out,
    );
});

test('releasing refuses what would make a bad release', () => {
    assert.throws(() => releaseChangelog(CHANGELOG, '1.1.0', '2026-11-06'), /already has a section for 1\.1\.0/);
    assert.throws(() => releaseChangelog(CHANGELOG, '1.2', '2026-11-06'), /not a version/);
    assert.throws(() => releaseChangelog(CHANGELOG, '1.2.0', '6.11.2026'), /not a date/);
    assert.throws(
        () => releaseChangelog('# Changelog\n\n## [Unreleased]\n\n### Added\n\n## [1.0.0] - 2026-01-01\n\n- x\n', '1.1.0', '2026-11-06'),
        /nothing under/,
    );
    assert.throws(() => releaseChangelog('# Changelog\n', '1.0.0', '2026-11-06'), /no "## \[Unreleased\]"/);
    assert.throws(() => releaseNotes(CHANGELOG, '9.9.9'), /no section for 9\.9\.9/);
});

test('pull request titles are Conventional Commits', () => {
    assert.equal(checkTitle('fix(media): keep the alt text when an image is replaced'), null);
    assert.equal(checkTitle('feat(api)!: drop the v0 endpoints'), null);
    assert.equal(checkTitle('docs: explain the release process'), null);
    assert.match(checkTitle('Fix the thing'), /not a Conventional Commit title/);
    assert.match(checkTitle('feature: add x'), /not a Conventional Commit title/);
    assert.match(checkTitle('fix: keep it.'), /without a full stop/);
    assert.match(checkTitle(`fix: ${'x'.repeat(70)}`), /keep it to 72/);
});

test('branch names follow Git Flow', () => {
    for (const ok of [
        'feature/412-scheduled-publishing',
        'fix/388-alt-text',
        'docs/release-process',
        'release/1.2.0',
        'release/2.0.0-rc.1',
        'hotfix/1.2.1',
        'dependabot/npm_and_yarn/vite-8.4.0',
    ]) {
        assert.equal(checkBranch(ok), null, ok);
    }
    for (const bad of ['Feature/Thing', 'my-branch', 'release/1.2', 'hotfix/1.2.1-x/y', 'feature/', 'main']) {
        assert.match(checkBranch(bad) ?? '', /not a branch name/, bad);
    }
});

test('pull requests go into the right branch', () => {
    assert.equal(checkTarget('feature/1-x', 'develop'), null);
    assert.equal(checkTarget('release/1.2.0', 'main'), null);
    assert.equal(checkTarget('hotfix/1.2.1', 'main'), null);
    assert.equal(checkTarget('fix/2-y', 'release/1.2.0'), null, 'a fix for the release in progress');
    assert.match(checkTarget('feature/1-x', 'main'), /Only release\/X\.Y\.Z and hotfix\/X\.Y\.Z/);
    assert.match(checkTarget('release/1.2.0', 'develop'), /merges into main/);
    assert.match(checkTarget('feature/1-x', 'gh-pages'), /not "gh-pages"/);
});
