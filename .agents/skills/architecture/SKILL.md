---
name: architecture
description: How the Kestello codebase is laid out, how a request boots, and the rules every change must respect. Load it before your first change in this repository, or whenever you are unsure where something lives.
---

# Kestello architecture

Kestello is a self-hosted PHP CMS with zero PHP dependencies. Core is built on the same public extension API that plugins and themes use, and the admin is a set of Vue pages that talk only to the REST API under `/api/v1`.

## Repository map

| Path | What lives there |
| --- | --- |
| `core/src/Framework/` | The in-house framework: `Core` (Container, Router, Request, Response, Config, Paths), `Database` (Connection, QueryBuilder, Schema, Blueprint, MigrationRunner, Orm), `Console`, `Cache`, `Queue`, `Session`, `Settings`, `Storage`, `Validation`, `Testing` (TestCase, TestCommand). Knows nothing about the CMS. |
| `core/src/Cms/` | The CMS, one folder per area: `Admin`, `Api`, `Auth`, `Blocks`, `Content`, `Design`, `Extension`, `Forms`, `I18n`, `Media`, `Menus`, `Package`, `Webhooks`, … Each area has a `*Module.php` provider. `Cms.php` is the facade providers receive. |
| `core/src/Contracts/` | Interfaces drivers implement (cache, queue, storage, mail, search, …). |
| `core/src/Support/` | Pure helpers: `Str`, `Arr`, `Date`, `Translator`, `Markdown`, `Crypt`, and the functions in `functions.php` (`Kestello\__()`, `trans_choice()`, `collect()`, …). |
| `core/src/Kernel.php` | Boots the app for HTTP (`handleHttp()`) and the CLI (`handleConsole()`). |
| `core/database/migrations/` | Core migrations. |
| `core/resources/js/` | The browser side: `app/` (`@kestello/app`), `sdk/` (`@kestello/sdk`), `ui/` (`@kestello/ui`), `pages/` (admin pages, blocks, field inputs), `components/`. |
| `core/resources/assets/build/` | Built Vue pages. Committed: production runs no Node. |
| `core/resources/lang/kestello.pot` | Core's translation template. |
| `core/build/` | Vite build (`build.mjs`, `preset.mjs`). Dev only. |
| `content/themes/base/` | The theme every site ships with. Other folders under `content/` are site data and ignored by Git. |
| `tests/fixtures/plugins/notebook/` | The smallest plugin with a migration, content type, endpoint and settings; `tests/Package/PluginLifecycleTest.php` takes it through install to uninstall. |
| `tests/` | PHP tests (`*Test.php`) and `tests/js/*.test.mjs`. |
| `bin/cms` | The CLI. `php bin/cms list` shows every command. |

Outside this repository: example plugins and themes in [kestellohq/examples](https://github.com/kestellohq/examples) (`hello`, `catalog`, `mail-log`, `base-child`), first-party plugins such as Meilisearch, Typesense and Social Login in [kestellohq/plugins](https://github.com/kestellohq/plugins), and the documentation in [kestellohq/docs](https://github.com/kestellohq/docs).

## How a request runs

1. `public/index.php` or `bin/cms` loads `core/bootstrap.php` and calls `Kestello\Kernel::create($root)`.
2. The Kernel builds the container and the `Cms` facade, then the `ExtensionManager`.
3. Core modules are added as providers (`AuthModule`, `AdminModule`, `ApiModule`, `ContentModule`, `BlocksModule`, `DesignModule`, …, see `Kernel.php`), then active plugins and the theme chain. Every provider gets `register(Container)` and then `boot(Cms)`.
4. `ContentModule::finalize()` checks the content types, stored roles are applied, translations are loaded (`bootTranslations()`), and the router dispatches.

Plugins and core register through the same calls, for example:

```php
$cms->routes->get('/hello/{name}', [GreetingController::class, 'show']);
$cms->api->endpoint(Api\ListGreetings::class);
$cms->admin->page('', '@hello/admin/Greetings', 'Greetings', 'hello.manage', ['label' => 'Greetings', 'group' => 'plugins']);
$cms->blocks->register(BlockDefinition::make('catalog/product-grid')->label('Product grid'));
```

The facade's properties are listed in the doc comment of `core/src/Cms/Cms.php` (`events`, `filters`, `routes`, `pages`, `auth`, `api`, `queue`, `schedule`, `admin`, `content`, `blocks`, `menus`, `hooks`, `webhooks`, …).

## Pages and the admin

- A controller returns `Page::render('<component>', $props)` (`core/src/Cms/View/Page.php`). The component is a Vue SFC: `Home` resolves in the active theme's `resources/js/pages/`, `@hello/Greeting` in the hello plugin's, `@core/admin/Dashboard` in core.
- The first visit gets an HTML shell with the page JSON; later navigation fetches JSON only. Shared props come from `$cms->pages->share()`.
- Admin pages are registered with `$cms->admin->page()` and never render server forms. Every screen reads and writes through `/api/v1`, so anything the admin does an integration can do.
- Vue files are prebuilt by `npm run build` (or `node core/build/build.mjs <target>`). Rebuild and commit `assets/build/` whenever you change a `.vue` or `.js` file under `resources/js`.

## Owners and naming

Every registration is recorded with its owner: `core`, `plugin:<slug>` or `theme:<slug>` (`ExecutionContext::current()`). The owner decides what names are allowed:

- blocks: `<slug>/<name>`; field types: `<slug>.<name>`;
- API paths: `/<slug>/…`; admin components: `@<slug>/…`, served under `/admin/p/<slug>`;
- tables: `p_<slug>_…` for plugins and `t_<slug>_…` for themes (dashes become underscores);
- translations: the domain is the slug.

## Invariants

- **Zero PHP dependencies** (`composer.json` has no requirements). Dev tools are pinned PHARs in `tools/` or npm dev dependencies.
- **Core dogfoods the extension API.** A new feature is an extension point first. If core needs a private shortcut, the public API is missing something: add it.
- **`@api` and `@internal`.** Classes marked `@api` are the stable public API. Anything marked `@internal` may change at any time and must not be used by plugins. Everything else not marked `@internal` is public too (see "What counts as the public API" in `CONTRIBUTING.md`).
- **Deny by default.** Endpoints need a capability and a scope or must say they are public; plugin network access and sign-in methods are limited to what the manifest declares.
- **Text people see is translatable** with `__()`. See `../i18n/SKILL.md`.
- **Every fix has a test.** See `../testing/SKILL.md`.

## Where to look

| Question | Start at |
| --- | --- |
| What a plugin can register | `core/src/Cms/Cms.php`, and the `hello` plugin in [kestellohq/examples](https://github.com/kestellohq/examples/tree/main/plugins/hello) |
| API pipeline and errors | `core/src/Cms/Api/ApiRegistry.php`, `Endpoint.php`, `ApiProblem.php` |
| Content types and fields | `core/src/Cms/Content/ContentRegistry.php`, `Field.php`, `FieldTypes.php` |
| Blocks | `core/src/Cms/Blocks/BlockDefinition.php`, `BlockRegistry.php`, `CoreBlocks.php` |
| Templates, parts, patterns, styles | `core/src/Cms/Design/ThemeTemplates.php`, `ThemeStyles.php` |
| Package lifecycle | `core/src/Cms/Extension/ExtensionManager.php`, `Manifest.php` |
| Admin design rules | `../admin-ui-design/SKILL.md`, `core/resources/assets/css/admin-ui.css` |

## Related skills

`../write-plugin/SKILL.md`, `../write-theme/SKILL.md`, `../rest-endpoint/SKILL.md`, `../admin-page/SKILL.md`, `../add-block/SKILL.md`, `../add-field-type/SKILL.md`, `../database-migration/SKILL.md`, `../code-style/SKILL.md`, `../git-workflow/SKILL.md`, `../security-review/SKILL.md`.
