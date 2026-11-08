# Kestello: instructions for AI agents

Kestello is a self-hosted PHP CMS for client sites: a non-technical owner runs and updates it alone, and developers extend it with plugins and themes without touching core. This file is what an AI agent needs before changing it. People follow the same rules, written out in [CONTRIBUTING.md](CONTRIBUTING.md).

## Before you start

1. Read [CONTRIBUTING.md](CONTRIBUTING.md). Its rules on branches, commits, tests, the changelog and the public API apply to you.
2. Load the skill for the task from [.agents/skills/](.agents/skills/). Each `SKILL.md` says when it applies.
3. For admin screens, load [admin-ui-design](.agents/skills/admin-ui-design/SKILL.md): who uses the admin, the tokens, components and rules.

| Task | Skill |
| --- | --- |
| Find your way around | [architecture](.agents/skills/architecture/SKILL.md) |
| Add a block | [add-block](.agents/skills/add-block/SKILL.md) |
| Add a field type | [add-field-type](.agents/skills/add-field-type/SKILL.md) |
| Write a plugin | [write-plugin](.agents/skills/write-plugin/SKILL.md) |
| Write a theme | [write-theme](.agents/skills/write-theme/SKILL.md) |
| Add an admin screen | [admin-page](.agents/skills/admin-page/SKILL.md), [admin-ui-design](.agents/skills/admin-ui-design/SKILL.md) |
| Add a REST endpoint | [rest-endpoint](.agents/skills/rest-endpoint/SKILL.md) |
| Change the database | [database-migration](.agents/skills/database-migration/SKILL.md) |
| Write tests | [testing](.agents/skills/testing/SKILL.md) |
| Text people see | [i18n](.agents/skills/i18n/SKILL.md) |
| Formatting and analysis | [code-style](.agents/skills/code-style/SKILL.md) |
| Branches, commits, pull requests | [git-workflow](.agents/skills/git-workflow/SKILL.md) |
| Cut a release | [release](.agents/skills/release/SKILL.md) |
| Review for security | [security-review](.agents/skills/security-review/SKILL.md) |
| Write documentation | [docs-writing](.agents/skills/docs-writing/SKILL.md) |

## Hard rules

- **Zero PHP dependencies.** Never add one; `composer.json` has no `require` beyond PHP and its extensions. Development tools are `require-dev` packages (PHPStan and PHP CS Fixer, locked in `composer.lock`) or npm dev dependencies; none of them ship.
- **Security first.** Every endpoint and admin page checks a capability on the server (`useCan()` in the browser is cosmetic). Validate input with `Schema`, escape output, bind SQL values, never send secrets to the browser. Run the [security review](.agents/skills/security-review/SKILL.md) on anything that touches auth, input, files, HTTP or packages.
- **Extensibility first.** Core uses the same public API plugins use. Prefer adding an extension point over a special case.
- **Every fix starts with a failing test**, and every feature comes with tests. Keep logic in small pure modules so it can be tested (in JavaScript, the SDK can't be imported in Node: put logic in a pure module beside it).
- **Errors say what went wrong and what to do.** No silent failures, no swallowed exceptions without a reason in a comment.
- **Text people see goes through `__()`**, in whole sentences with named ICU placeholders. Then run `npm run i18n`.
- **Respect the public API** (listed in CONTRIBUTING.md). Breaking it needs a `!` commit, a `BREAKING CHANGE:` footer and a changelog entry with migration notes. Mark new internals `@internal`.
- **Admin design rules:** no CDN fonts, no eyebrow labels above headings, no emoji, no coloured left border wider than 1px, no placeholder text standing in for a label, soft rules and spacing instead of heavy separators, light and dark themes both.
- **Docs style** (for the docs repository and any prose here): sentence-case headings, the exact admin label in bold, no em or en dashes, none of "simply", "just", "easy", "obviously".

## Working rules for agents

- **Never push, force-push, tag or publish** unless the person you work for asks for that action in so many words. Commit locally.
- Work on a branch named as CONTRIBUTING.md says, never on `main` or `develop` directly.
- One logical change per commit with a Conventional Commit message. Commits carry no AI assistant trailers or attribution lines.
- Never skip hooks or checks (`--no-verify`), never edit `phpstan-baseline.neon` to hide a new finding: fix the code.
- Never commit secrets, `.env`, `config/*.php` site config, or anything in `storage/`.
- Don't reformat code you aren't changing. Formatting-only changes go in their own `style:` commit.
- Built files under `*/assets/build/` are committed: run `npm run build` after changing Vue or JavaScript sources and commit the result with the change.
- Ask before deleting data, dropping Docker volumes, or anything you can't undo.

## Commands

```sh
docker compose up -d          # the site at http://localhost:8080 (PHP 8.5, MySQL, PostgreSQL, Redis)
npm ci && npm run tools       # JavaScript tools, and the pinned PHP CS Fixer and PHPStan PHARs

npm run format                # fix formatting (PHP and JS/Vue/CSS)
npm run lint                  # check formatting, ESLint, PHPStan
npm test                      # JavaScript tests
npm run test:php              # PHP tests on SQLite (one folder: sh tools/php bin/cms test tests/I18n)
npm run test:php:matrix       # PHP tests on MySQL, PostgreSQL and Redis too, like CI
npm run build                 # rebuild the Vue pages
npm run i18n                  # refresh core/resources/lang/kestello.pot
npm run ide                   # refresh .phpstorm.meta.php
npm run check                 # all of the checks
```

The test databases and the matrix are described in [testing](.agents/skills/testing/SKILL.md).

## Where things are

| Path | What |
| --- | --- |
| `core/src/Framework` | The in-house framework: container, router, database, console, testing. |
| `core/src/Cms` | The CMS: content, blocks, admin, API, auth, media, themes, plugins, updates. |
| `core/src/Contracts` | Interfaces plugins implement or depend on. |
| `core/src/Support` | Helpers: strings, dates, `Translator`, `__()`. |
| `core/resources/js` | The admin and site runtime: `app`, `sdk`, `ui`, pages and the visual editor. |
| `core/resources/lang` | Core's translation template and language packs. |
| `core/database` | Core migrations. |
| `content/themes/base` | The base theme that ships with core. |
| `tests/` | PHP tests by area; `tests/js` holds the JavaScript tests; `tests/fixtures` the test plugin. |
| `tools/` | Pinned development tools, release scripts, GitHub label list. |

## Definition of done

- [ ] Tests cover the change and pass: `npm test` and `npm run test:php`.
- [ ] `npm run lint` passes; no new PHPStan baseline entries.
- [ ] Text people see uses `__()`; `npm run i18n` was run.
- [ ] Vue or JavaScript changes are rebuilt (`npm run build`) and the build is committed.
- [ ] `CHANGELOG.md` has an entry under Unreleased when people would notice the change.
- [ ] A docs change in kestellohq/docs is ready when a guide or developer page needs one.
- [ ] Commits follow Conventional Commits, with no AI assistant trailers.
