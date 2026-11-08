---
name: release
description: How a Kestello version is released through GitHub (release branches, tags, the signed core zip, the release and back-merge workflows), including hotfixes, pre-releases and the release signing key. Load it when preparing, cutting or repairing a release, or when deciding whether a change is a patch, minor or major.
---

# Releasing Kestello

Releases are cut by maintainers. An agent prepares a release only when a maintainer asks, and never pushes branches or tags itself (see [git-workflow](../git-workflow/SKILL.md)).

## Versions

[Semantic Versioning 2.0](https://semver.org/). `core/VERSION` is the single source of truth; the tag is `v` plus its content.

| Kind | Example | Contains |
| --- | --- | --- |
| Patch | `1.4.2` | Fixes only. |
| Minor | `1.5.0` | New capabilities, backwards compatible; deprecations are announced. |
| Major | `2.0.0` | Breaking changes, each with migration notes. |
| Pre-release | `1.5.0-beta.1`, `1.5.0-rc.1` | Published as a GitHub pre-release; sites get it only when they opt in. |

Before 1.0, a minor release may break the public API; the changelog says how to migrate. Deprecated API keeps working for at least one minor release and logs a deprecation notice before removal in the next major.

**The public API** is: PHP classes, interfaces and functions not marked `@internal` (`@api` ones are the most stable), the hooks and filters core fires, the REST API under `/api/v1`, the modules `@kestello/app`, `@kestello/sdk` and `@kestello/ui`, `bin/cms` commands and options, configuration keys, the plugin and theme manifests, stored block and field data, and the database schema (changed only through migrations). A breaking change to any of these needs a major (or, before 1.0, a minor with migration notes).

## Pre-flight checklist

- [ ] `develop` is green in CI and `git status` is clean.
- [ ] Every change since the last release has a `CHANGELOG.md` line under `## [Unreleased]`, in the right group (Added, Changed, Deprecated, Removed, Fixed, Security); breaking ones start with **Breaking:**.
- [ ] The version number follows from the changes (any **Breaking:** line means a major, or a minor before 1.0).
- [ ] Docs for the release's visible changes are merged in kestellohq/docs.
- [ ] Migrations added since the last release run forward on MySQL, MariaDB, PostgreSQL and SQLite (the CI matrix).

## Cutting a release

1. Branch: `git switch develop && git pull && git switch -c release/X.Y.Z`.
2. Prepare: `npm run release:prepare -- X.Y.Z` (`tools/release/prepare.mjs`). It writes `X.Y.Z` to `core/VERSION`, turns `## [Unreleased]` into `## [X.Y.Z] - YYYY-MM-DD` under a fresh empty Unreleased section, updates the compare links at the bottom of the changelog, and runs `i18n:extract` and `ide:helpers`.
3. Check: `npm run build` (so the PHP tests see current pages; nothing built is committed), then `npm run check` and the PHP database matrix ([testing](../testing/SKILL.md)).
4. Commit `chore(release): X.Y.Z` and open a pull request from `release/X.Y.Z` into `main`.
5. Only fixes land on the release branch from here, each with its changelog line.
6. When green and approved, merge into `main` with a **merge commit** (not squash).
7. Tag the merge commit and push the tag: `git tag -s vX.Y.Z -m "Kestello X.Y.Z" && git push origin vX.Y.Z`.
8. `.github/workflows/release.yml` runs on the `v*` tag:
   - runs every check (`ci.yml`);
   - checks the tag equals `v` + `core/VERSION` and is on `main`, and fails otherwise;
   - writes the release key from the `KESTELLO_RELEASE_KEY` secret to a file and runs `php bin/cms core:release --out=dist --key=<file>`, producing `dist/kestello-core-X.Y.Z.zip` (signed) and its `.sha256`;
   - runs `php bin/cms core:verify` on the zip, which fails unless a key in `core/config/release.php` signed it and the `.sha256` matches;
   - writes the API stubs with `php bin/cms ide:helpers --out=dist --stubs` (`kestello.stub.php`);
   - takes the notes from `node tools/release/notes.mjs X.Y.Z` and runs `gh release create`, as a pre-release when the version contains a hyphen.
9. `.github/workflows/back-merge.yml` opens a pull request from `main` into `develop` (branch `chore/back-merge-vX.Y.Z`). Merge it with a merge commit. CI runs on that pull request only when the `KESTELLO_BOT_TOKEN` secret is set; otherwise close and reopen it to start CI.

## How sites get the release

`config/release.php` names the repository (`kestellohq/cms`), how often to check (`check_every`, hours) and whether pre-releases count. The scheduled task `updates.check` and **Check now** on **Updates** ask the GitHub releases API (`UpdateChecker`, `ReleaseFeed`). A release counts only when it is published, tagged `vX.Y.Z`, and carries both `kestello-core-X.Y.Z.zip` and its `.sha256` from that repository.

**Review the update** downloads the zip again (asking GitHub where it is, nothing remembered is trusted), checks the SHA-256, then shows the same review as an upload. The admin applies it only when a key in `release.keys` signed it as a core release (`core:release --key`). Release keys are trusted for core only, never for plugins, and package keys (`app.packages.trusted_keys`) never for core. `bin/cms core:check` does the check from the command line; `core:update <zip>` installs a zip by hand (with a backup, a health check through `update:probe` and automatic rollback).

## Hotfixes

Same flow from `main`: `git switch main && git pull && git switch -c hotfix/X.Y.Z` (a patch number), fix with a test, `npm run release:prepare -- X.Y.Z`, pull request into `main`, merge commit, tag, and let the back-merge bring it into `develop`.

## The release signing key

Generated once, by a maintainer, offline:

```bash
php bin/cms package:keygen --out=kestello-release.key --name="Kestello releases"
```

`package:keygen` writes a JSON file with `id`, `name` and `secret_key` (mode 0600) and prints the public key. Then:

- paste the file's contents, as they are, into the repository secret `KESTELLO_RELEASE_KEY` (`gh secret set KESTELLO_RELEASE_KEY < kestello-release.key`), and keep the file itself in the maintainers' password manager, never in git;
- commit the public half in `core/config/release.php` under `keys` (`['name' => 'Kestello releases', 'public_key' => '<base64>']`) before the first signed release: `core:verify` refuses a release no site would trust;
- to rotate, add the new public key next to the old one, release with the new key, and remove the old one a release later.

`core:release` without `--key` produces an unsigned zip, which sites refuse to install from the admin. To try a new key, build a signed zip locally with `--key` and install it on a test site whose config trusts the public key (`core:update <zip>`).

## When the release workflow fails

- **Tag does not match `core/VERSION`:** delete the tag locally and on GitHub (`git tag -d vX.Y.Z && git push origin :refs/tags/vX.Y.Z`, a maintainer action), fix the version on a release branch, merge, tag again.
- **A check fails:** nothing was published. Fix on a `hotfix/` (or the still-open release) branch, merge into `main`, and tag the new merge commit. Never re-point a tag that produced a published release; publish the next patch instead.
- **Signing fails:** the `KESTELLO_RELEASE_KEY` secret is missing or is not the contents of a `package:keygen` file (`SignKey::read()` says "is not a key file from package:keygen"). Fix the secret and re-run the workflow.
- **`core:verify` fails:** the zip was signed with a key whose public half is not in `core/config/release.php`. Add it on a hotfix branch and release the next patch; never publish a release sites would refuse.
- **`gh release create` fails after the zip was built:** re-run the failed job; if a draft or partial release exists, delete it in the GitHub UI first.
- **A bad release is out:** don't delete it (sites may have installed it). Publish a fixed patch release and say in its notes what it fixes.

## Done when

- [ ] `core/VERSION`, the tag and the changelog heading agree.
- [ ] The GitHub release has the signed zip, its `.sha256`, `kestello.stub.php` and the changelog section as notes.
- [ ] The back-merge pull request into `develop` is merged.
- [ ] The docs mention anything that changed for site owners or developers.
