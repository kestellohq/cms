# Contributing to Kestello

Thank you for helping. This file is the rulebook for everyone who changes Kestello, people and AI agents alike. [AGENTS.md](AGENTS.md) adds what agents need on top, and [.agents/skills/](.agents/skills/) holds step-by-step guides for common tasks.

- [Ground rules](#ground-rules)
- [Setting up](#setting-up)
- [Branches](#branches)
- [Commits](#commits)
- [Pull requests](#pull-requests)
- [Issues](#issues)
- [Versions and releases](#versions-and-releases)
- [The changelog](#the-changelog)
- [Code style and checks](#code-style-and-checks)
- [Translations](#translations)
- [Documentation](#documentation)
- [Security](#security)

## Ground rules

- **Zero PHP dependencies.** Everything Kestello runs is in this repository, and `composer.json` stays without requirements. Development tools are fine: they are pinned PHARs in `tools/` (see [Code style and checks](#code-style-and-checks)) or npm dev dependencies, and none of them ship.
- **Security and extensibility come first.** Core is built on the same public API plugins use. New features are extension points first and screens second.
- **Every fix has a test.** A bug fix starts with a test that fails without it. A feature comes with tests for what it promises.
- **Small, pure, debuggable code.** Prefer small modules and pure functions. Errors say what went wrong and what to do about it.
- **Text people see is translatable.** Use `__()` in PHP and JavaScript (see [Translations](#translations)).
- **Be kind.** The [Code of Conduct](CODE_OF_CONDUCT.md) applies everywhere the project lives.

## Setting up

You need Docker, Composer, Node.js 22.15 or newer and Git.

```sh
git clone https://github.com/kestellohq/cms.git kestello && cd kestello
cp .env.example .env
docker compose up -d
npm ci
npm run build        # the Vue pages: built, not committed
composer install     # PHP CS Fixer and PHPStan, the only (dev) requirements
```

Open http://localhost:8080 and finish the installer. `npm run check` runs every check CI runs, apart from the database matrix.

## Branches

Kestello uses Git Flow.

| Branch | Comes from | Merges into | Holds |
| --- | --- | --- | --- |
| `main` | | | Released code only. Every commit is a tagged release. |
| `develop` | `main` | `main` (through a release) | The next release, always green. |
| `feature/<issue>-<slug>` | `develop` | `develop` | A new capability. |
| `fix/<issue>-<slug>` | `develop` | `develop` | A bug fix for the next release. |
| `docs/…`, `chore/…`, `refactor/…`, `test/…`, `perf/…`, `ci/…`, `build/…` | `develop` | `develop` | Work of that kind. |
| `release/X.Y.Z` | `develop` | `main`, then back into `develop` | Release preparation: version, changelog, last fixes. |
| `hotfix/X.Y.Z` | `main` | `main`, then back into `develop` | An urgent fix to the released version. |

- Names are lowercase: `feature/412-scheduled-publishing`, `fix/388-media-alt-text`. Include the issue number when there is one.
- `main` and `develop` are protected: changes arrive through pull requests with green checks and one approving review. Nobody pushes to them directly, and nobody force-pushes shared branches.
- Feature and fix pull requests are **squash-merged** into `develop`, so the pull request title becomes the commit. Release and hotfix branches are merged into `main` with a **merge commit**, so `main` keeps the history.

## Commits

Commits follow [Conventional Commits 1.0](https://www.conventionalcommits.org/en/v1.0.0/):

```
<type>(<optional scope>)<optional !>: <summary>

<body: what changed and why, wrapped at 100 columns>

<footers>
```

**Types.** `feat` (a new capability), `fix` (a bug fix), `docs`, `style` (formatting only), `refactor` (no change in behaviour), `perf`, `test`, `build` (build system, dependencies), `ci`, `chore` (anything else), `revert`.

**Scopes** name the area: `admin`, `editor`, `api`, `auth`, `content`, `blocks`, `media`, `forms`, `menus`, `themes`, `plugins`, `i18n`, `ide`, `update`, `release`, `backup`, `queue`, `search`, `cli`, `deps`. Leave the scope out when a change spans many.

**The summary** says what changes for the person using Kestello, in the imperative or as a plain statement, at most 72 characters, no full stop: `fix(media): keep the alt text when an image is replaced`.

**The body** explains why, and anything a reviewer needs: trade-offs, what was ruled out, how it was tested.

**Footers.**
- `Fixes #123` or `Refs #123` link the issue.
- `BREAKING CHANGE: <what breaks and how to migrate>` (also mark the type with `!`, as in `feat(api)!: …`) for anything that breaks the [public API](#what-counts-as-the-public-api).
- `Co-Authored-By: <name> <email>` credits a person who co-wrote the change. AI assistants are not credited as authors or co-authors.

Each commit builds and passes the tests on its own. Formatting changes go in their own `style:` commit and are listed in [.git-blame-ignore-revs](.git-blame-ignore-revs).

## Pull requests

1. Open or find an issue first for anything bigger than a small fix, so the approach can be agreed before the work.
2. Branch from `develop` (from `main` for a hotfix) with a [branch name](#branches) CI accepts.
3. Keep the change focused: one feature or fix per pull request.
4. The **title** is a Conventional Commit summary (it becomes the squashed commit). CI checks it.
5. Fill in the template: what changes and why, how you tested it, screenshots for anything visible, and the checklist.
6. Add an entry under `## [Unreleased]` in [CHANGELOG.md](CHANGELOG.md) for anything a site owner, editor or developer would notice. CI asks for one when core changes; the `no changelog` label skips the check for internal changes.
7. CI must be green. A reviewer approves; the author answers every comment.

Draft pull requests are welcome for early feedback.

## Issues

- **Bugs:** use the bug report form. Include the Kestello and PHP versions, the database, the steps, what you expected and what happened. Logs from `storage/logs/` help.
- **Ideas:** use the feature request form. Start from the problem: who has it and what they do today.
- **Questions:** ask in [Discussions](https://github.com/kestellohq/cms/discussions), not issues.
- **Security problems:** never in public. See [SECURITY.md](SECURITY.md).

### Labels

| Group | Labels |
| --- | --- |
| Type | `type: bug`, `type: feature`, `type: docs`, `type: chore`, `type: security` |
| Area | `area: admin`, `area: editor`, `area: api`, `area: content`, `area: themes`, `area: plugins`, `area: i18n`, `area: update`, … |
| Priority | `priority: critical`, `priority: high`, `priority: normal`, `priority: low` |
| Status | `status: needs triage`, `status: confirmed`, `status: blocked`, `status: needs info` |
| Help | `good first issue`, `help wanted` |
| Process | `no changelog`, `breaking change` |

New issues get `status: needs triage`. A maintainer confirms them, adds type, area and priority, and assigns a milestone (the release it is planned for). [tools/github/labels.tsv](tools/github/labels.tsv) is the full list; `sh tools/github/labels.sh` creates or updates them on GitHub.

## Versions and releases

Kestello follows [Semantic Versioning 2.0](https://semver.org/). `core/VERSION` holds the version; tags are `vX.Y.Z` and must match it.

- **Patch** (`1.4.2`): fixes only. Safe to install without reading anything.
- **Minor** (`1.5.0`): new capabilities, backwards compatible. Deprecations are announced here.
- **Major** (`2.0.0`): breaking changes, each with migration notes in the changelog.
- **Pre-releases**: `1.5.0-beta.1`, `1.5.0-rc.1`. Sites are offered a pre-release when `release.prereleases` is on, or when they already run a pre-release of the same version: a site on `0.1.0-rc.1` gets `0.1.0-rc.2`, then `0.1.0`, and only releases from there. While a pre-release of Kestello runs, plugins and themes made for its release run on it: `0.1.0-rc.1` meets `"core": "^0.1"`. Before each minor release, release candidates (`-rc.1`, `-rc.2`, …) try the release workflow and the update from one candidate to the next on real hosting.
- **Before 1.0**, a minor release may break the public API. The changelog says how to migrate.

Deprecated public API keeps working for at least one minor release and logs a deprecation notice before it is removed in the next major.

### What counts as the public API

Everything plugins, themes and sites build on:

- PHP classes, interfaces and functions not marked `@internal` (those marked `@api` are the most stable),
- the hooks and filters core fires,
- the REST API under `/api/v1`,
- the JavaScript modules `@kestello/app`, `@kestello/sdk` and `@kestello/ui`,
- `bin/cms` commands and their options,
- configuration keys, the plugin and theme manifests,
- block and field data as stored, and the database schema (changed only through migrations).

### How a release happens

Releases are cut by maintainers, through GitHub. [.agents/skills/release/SKILL.md](.agents/skills/release/SKILL.md) is the step-by-step version.

1. `git switch develop && git pull`, then `git switch -c release/X.Y.Z`.
2. `npm run release:prepare -- X.Y.Z` sets `core/VERSION`, turns `## [Unreleased]` into `## [X.Y.Z] - <date>` with a fresh empty Unreleased section, and refreshes the translation template and editor helpers.
3. Run `npm run check`, commit `chore(release): X.Y.Z`, and open a pull request into `main`.
4. Only fixes land on the release branch. When it is green and approved, merge it into `main` with a merge commit.
5. Tag the merge commit: `git tag -s vX.Y.Z -m "Kestello X.Y.Z" && git push origin vX.Y.Z`.
6. The release workflow runs every check, checks the tag against `core/VERSION`, builds `kestello-core-X.Y.Z.zip`, signs it with the release key, verifies the signature against the keys in `core/config/release.php` (`bin/cms core:verify`), builds `kestello-X.Y.Z.zip` for new installs (`bin/cms core:install-zip`), adds the SHA-256 checksums and the API stubs, and publishes a GitHub release with that version's changelog section as notes. A pre-release version becomes a GitHub pre-release.
7. The back-merge workflow opens a pull request from `main` into `develop`. Merge it with a merge commit.

Sites see the new version on **Updates** in the admin: Kestello checks the GitHub releases of `kestellohq/cms` twice a day (`config/release.php`), downloads a release only when its SHA-256 matches the published checksum, and installs it only when one of the release keys in `core/config/release.php` signed it.

A hotfix works the same way from `hotfix/X.Y.Z`, branched from `main`.

A pre-release goes the same way, from `release/X.Y.Z-rc.N`. `release:prepare` then sets `core/VERSION` only and leaves the changelog alone: the GitHub release notes are the Unreleased section as it stands, under a line saying it is a pre-release. The release it leads to turns Unreleased into one `[X.Y.Z]` section with every change since the last release. Merge the back-merge pull request as usual.

## The changelog

[CHANGELOG.md](CHANGELOG.md) follows [Keep a Changelog 1.1](https://keepachangelog.com/en/1.1.0/). Entries go under `## [Unreleased]`, in these groups: **Added**, **Changed**, **Deprecated**, **Removed**, **Fixed**, **Security**. Write each entry for the person affected, one line, with the issue or pull request number: `- Images keep their alt text when replaced (#388).` Breaking changes start with **Breaking:** and say how to migrate.

## Code style and checks

| Command | What it does |
| --- | --- |
| `npm run format` | Formats PHP (PHP CS Fixer, PER Coding Style 2.0) and JavaScript, Vue and CSS (Prettier). |
| `npm run lint` | Checks formatting and runs ESLint and PHPStan, without changing files. |
| `npm run analyse` | PHPStan at level 6. Old findings are in `phpstan-baseline.neon`; new code may not add any. |
| `npm test` | JavaScript unit tests. |
| `npm run test:browser` | Builds a site from the install zip, installs it through the web installer in Chromium, and opens every admin screen a link leads to. Fails on any browser error or server error, naming the screen. Needs `npm run build` first, PHP 8.4 locally, and once `npx playwright install chromium`. |
| `npm run test:update` | Installs each of the last two releases from its zip, adds content, updates it to this checkout with `core:update`, and runs the browser test again. This checkout is built as a signed update with a key made for the run. Pass versions to test others: `sh tests/browser/update.sh 0.1.0-rc.3`. Same needs as `test:browser`, plus `curl`. |
| `npm run test:php` | PHP tests on SQLite (in the app container when PHP 8.4 isn't installed locally). |
| `npm run test:php:matrix` | PHP tests on MySQL, PostgreSQL and Redis as well, as CI runs them. |
| `npm run i18n` | Rewrites the translation template, `core/resources/lang/kestello.pot`. |
| `npm run ide` | Rewrites `.phpstorm.meta.php`. |
| `npm run build` | Builds the Vue pages. Built files are not committed: CI builds them for its checks and the release workflow for the zips. |
| `npm run check` | Everything above that checks, in one go. |

PHP CS Fixer and PHPStan are the only entries in `require-dev` (`php-cs-fixer/shim` and `phpstan/phpstan`, both single PHARs with no dependencies of their own), locked in `composer.lock`. They never ship: the install and update zips leave `vendor/` out. To upgrade one, run `composer update <package>` in a `build(deps):` commit. Moving to PER Coding Style 3.0 is a one-line change in `.php-cs-fixer.dist.php` (`@PER-CS3x0`), followed by `npm run format` in its own commit.

## Translations

- PHP: `__('Save changes')`, `trans_choice('{count, plural, one {# file} other {# files}}', $n)`. Plugins and themes pass their slug: `__('Greeting', domain: 'acme-shop')`.
- JavaScript and Vue: `__()` from `@kestello/sdk`; in a plugin, `const { __ } = useI18n('acme-shop')`. Plural messages go in a computed property, not straight in a template: their `}}` would end the `{{ }}`.
- Messages use ICU MessageFormat. Write whole sentences with named placeholders, never glue pieces together.
- Labels given to `->label()`, `->help()`, `->description()` and the admin registration methods are translated for you.
- After changing text, run `npm run i18n` and commit the template. CI checks it is current.

Translators: see [Translating Kestello](https://docs.kestello.com/guide/translating).

## Documentation

User and developer documentation lives in [kestellohq/docs](https://github.com/kestellohq/docs). A change people will notice comes with a docs pull request there, written to its `STYLE.md`.

## Security

Report vulnerabilities privately, as [SECURITY.md](SECURITY.md) describes. Never open a public issue or pull request for one.

## License

By contributing, you agree that your contributions are licensed under the [GNU General Public License v2.0 or later](LICENSE).
