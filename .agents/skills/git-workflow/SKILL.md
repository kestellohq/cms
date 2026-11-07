---
name: git-workflow
description: Branches, commits and pull requests in Kestello (Git Flow and Conventional Commits), with the extra rules AI agents follow. Load it before creating a branch, writing a commit message or opening a pull request.
---

# Git workflow

[CONTRIBUTING.md](../../../CONTRIBUTING.md) is the rulebook; this is the working version.

## Branches (Git Flow)

| Branch | From | Into | For |
| --- | --- | --- | --- |
| `main` | | | Released code; every commit is a tagged release. |
| `develop` | `main` | `main` via a release | The next release, always green. |
| `feature/<issue>-<slug>` | `develop` | `develop` | New capabilities. |
| `fix/<issue>-<slug>` | `develop` | `develop` | Bug fixes. |
| `docs/`, `chore/`, `refactor/`, `test/`, `perf/`, `ci/`, `build/`, `style/` | `develop` | `develop` | Work of that kind. |
| `release/X.Y.Z` | `develop` | `main`, then back into `develop` | Release preparation. |
| `hotfix/X.Y.Z` | `main` | `main`, then back into `develop` | Urgent fixes to a release. |

Names CI accepts:

```
^(feature|fix|docs|chore|refactor|test|perf|ci|build|style)/[a-z0-9][a-z0-9._-]*$
^release/[0-9]+\.[0-9]+\.[0-9]+(-[0-9A-Za-z.-]+)?$
^hotfix/[0-9]+\.[0-9]+\.[0-9]+$
```

Example: `git switch develop && git switch -c fix/388-media-alt-text`.

## Commits (Conventional Commits 1.0)

```
<type>(<scope>)<!>: <summary>

<body>

<footers>
```

- **Types:** `feat`, `fix`, `docs`, `style`, `refactor`, `perf`, `test`, `build`, `ci`, `chore`, `revert`.
- **Scopes:** `admin`, `editor`, `api`, `auth`, `content`, `blocks`, `media`, `forms`, `menus`, `themes`, `plugins`, `i18n`, `ide`, `update`, `release`, `backup`, `queue`, `search`, `cli`, `deps`. Leave it out when a change spans many areas.
- **Summary:** what changes for the person using Kestello, at most 72 characters, no full stop. Good: `fix(media): keep the alt text when an image is replaced`. Bad: `fix stuff`, `Update Media.php`.
- **Body:** why, trade-offs, what was ruled out, how it was tested. Wrap at 100 columns.
- **Footers:** `Fixes #123` / `Refs #123`; `BREAKING CHANGE: <what and how to migrate>` with `!` after the type; `Co-Authored-By: <name> <email>` for people who co-wrote the change. Never add an AI assistant as author, co-author or in a "generated with" line.

Write multi-line messages with a heredoc so formatting survives:

```bash
git commit -F - <<'EOF'
fix(media): keep the alt text when an image is replaced

Replacing the file created a new media row and dropped the old one's alt text.
The replacement now updates the row in place.

Fixes #388
EOF
```

Each commit builds and passes the tests on its own. One logical change per commit: a refactor, the fix that needs it and a formatting pass are three commits.

## Pull requests

- Title: a Conventional Commit summary (CI checks it). Feature and fix pull requests are **squash-merged** into `develop`, so the title becomes the commit.
- Release and hotfix branches merge into `main` with a **merge commit**; the back-merge into `develop` is a merge commit too.
- Fill in the template: what and why, how it was tested, screenshots for visible changes, the checklist.
- Add a line under `## [Unreleased]` in `CHANGELOG.md` for anything a site owner, editor or developer would notice (Keep a Changelog groups: Added, Changed, Deprecated, Removed, Fixed, Security). Internal-only changes get the `no changelog` label instead.
- A visible change comes with a docs pull request in kestellohq/docs (see [docs-writing](../docs-writing/SKILL.md)).

## Rules for AI agents

- **Never push, force-push or create remotes, tags or GitHub releases unless the human explicitly asks in this session.** Commit locally and report the hashes.
- Never commit straight to `main` or `develop`. If the working copy is on one of them, create a correctly named branch first.
- Never rewrite published history (`rebase`, `commit --amend`, `reset --hard` on shared branches). Amend only your own unpushed commit, and only when asked.
- Never skip hooks or checks (`--no-verify`, disabling CI steps). If a hook fails, fix the cause.
- Run `npm run format`, `npm run lint` and the tests before committing (see [code-style](../code-style/SKILL.md) and [testing](../testing/SKILL.md)).
- Stage files deliberately (`git add <paths>`); check `git status` for stray files (`.env`, caches, scratch files) before committing.
- Never commit secrets, `.env`, `config/installed.php` or generated site config.
- Don't use interactive commands (`git rebase -i`, `git add -p`); they need a terminal you don't have.

## Done when

- [ ] The branch name matches the pattern for its kind.
- [ ] Every commit is a Conventional Commit with a clear summary and a body that explains why.
- [ ] Tests and lint pass at every commit.
- [ ] `CHANGELOG.md` has an entry, or the change is labelled `no changelog`.
- [ ] Nothing was pushed unless the human asked for it.
