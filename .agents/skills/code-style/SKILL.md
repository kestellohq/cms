---
name: code-style
description: Kestello's code style and static analysis (PHP CS Fixer with PER Coding Style 2.0, PHPStan level 6, ESLint, Prettier) and how the tools are installed and upgraded. Load it before committing, when a lint or analysis check fails, or when changing tool versions or rules.
---

# Code style and static analysis

Formatting is never argued about: the tools decide. Run them before every commit; CI runs the same commands.

## The commands

| Command | What it does |
| --- | --- |
| `npm run tools` | Installs PHPStan and PHP CS Fixer into `vendor/` (`composer install`). |
| `npm run format` | `format:php` (PHP CS Fixer, fixes files) then `format:js` (Prettier, writes files). |
| `npm run lint` | `lint:php` (PHP CS Fixer dry run with diff), `lint:js` (ESLint and `prettier --check`), then `analyse`. |
| `npm run analyse` | PHPStan with `phpstan.dist.neon`. |
| `npm run check` | `lint`, `npm test` and `test:php`. |

The PHP tools run through `tools/php`: the local `php` when it is 8.4 or newer, otherwise `docker compose exec -T app php`.

## PHP: PER Coding Style 2.0

`.php-cs-fixer.dist.php` applies `@PER-CS2x0` plus `no_unused_imports`, `ordered_imports` (alphabetical; classes, then functions, then constants) and `single_quote`. It scans the whole repository except `node_modules`, `storage`, `tools`, `vendor`, `core/releases`, `dist`, `core/resources/assets`, `config/installed.php` and anything git ignores (a site's own plugins and themes in `content/`), and adds `bin/cms`.

Conventions the formatter doesn't enforce but reviews do:

- `declare(strict_types=1);` in every file; classes `final` unless designed for extension.
- Public API is marked `@api`; implementation details `@internal` (the IDE stubs leave those out).
- Doc comments say why, and give array shapes PHPStan can check: `@return array<string, list<string>>`.
- Errors say what went wrong and what to do: `"There is no folder {$path}."`, not `"Invalid path"`.

**PER-CS 3.0** exists. Switching is one line in `.php-cs-fixer.dist.php` (`'@PER-CS3x0' => true`), followed by `npm run format` in its own `style:` commit.

## PHP: PHPStan

`phpstan.dist.neon`: level 6, `phpVersion: 80400`, bootstrap `core/bootstrap.php`, paths `core/src`, `core/config`, `core/database`, `bin/cms`, the base theme (`content/themes/base`) and the test fixtures (`tests/fixtures`); local plugins and themes in `content/` are left out so CI and a developer's machine see the same files; `tests/` is scanned for classes only. `return.unusedType` is ignored on purpose (endpoints override nullable contract methods).

`phpstan-baseline.neon` lists findings from before PHPStan was introduced. Rules:

- **Never add new code to the baseline.** Fix the finding: a real type, an array shape, a guard.
- When you fix a baselined finding, regenerate so it stays fixed: `npm run analyse -- --generate-baseline phpstan-baseline.neon`, and check the diff only removes entries.
- Anonymous classes are named by line, so moving one can shift its baseline entry; regenerate and confirm the count did not grow.
- Don't silence findings with `@phpstan-ignore` or inline `@var` overrides unless the reason is written next to them.

## JavaScript, Vue and CSS

- **ESLint** (`eslint.config.js`): skips local plugins and themes in `content/` except the base theme; correctness only (`@eslint/js` recommended, `eslint-plugin-vue` essential). Unused variables are errors unless prefixed `_`. Two documented exceptions: `vue/no-reserved-component-names` for the app's own `Link` and `Head` in `core/resources/js/app/index.js`, and `vue/no-mutating-props` for the content type editors that edit one shared draft.
- **Prettier** (`.prettierrc.json`): 4 spaces, single quotes (double in CSS), trailing commas, 160 columns, LF. `.prettierignore` skips built and vendored files, Markdown, PHP, `.neon`, and the icon data files `core/resources/js/shared/icons.js` and `social-icons.js` (a test reads them line by line).
- `.editorconfig` sets UTF-8, LF, final newline, 4-space indent (2 for JSON, YAML and NEON).

## Pinned tools

PHP CS Fixer and PHPStan are the only `require-dev` packages in `composer.json`: `php-cs-fixer/shim` and `phpstan/phpstan`, each a single PHAR with no dependencies of its own. `composer.lock` pins their versions and checksums. `vendor/` is ignored by git and never ships.

To upgrade PHP CS Fixer or PHPStan:

1. `composer update php-cs-fixer/shim` (or `phpstan/phpstan`).
2. `npm run lint`. If the new version reports new findings, fix them (or reformat in a separate `style:` commit).
3. Commit `composer.json` and `composer.lock` as `build(deps): php-cs-fixer 3.x.y` (or `phpstan`).

JavaScript tools are exact-pinned dev dependencies in `package.json`; upgrade with `npm install --save-dev --save-exact <pkg>@<version>`.

## Formatting commits

A change that only reformats goes in its own `style:` commit, and its hash is added to `.git-blame-ignore-revs` in a following `chore:` commit, so `git blame` looks past it (`git config blame.ignoreRevsFile .git-blame-ignore-revs`; GitHub reads the file by itself). Never mix formatting with behaviour changes.

## Done when

- [ ] `npm run format` leaves no diff and `npm run lint` passes.
- [ ] PHPStan passes without new baseline entries.
- [ ] Built assets are rebuilt (`npm run build`) when Vue or JS sources changed.
- [ ] Reformatting, if any, is a separate `style:` commit listed in `.git-blame-ignore-revs`.

See also [testing](../testing/SKILL.md) and [git-workflow](../git-workflow/SKILL.md).
