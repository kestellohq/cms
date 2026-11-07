---
name: write-plugin
description: Building a Kestello plugin from manifest to signed zip (provider, routes, API, admin screens, settings, blocks, assets, translations, editor stubs, packaging). Load it when creating a plugin or changing one of the example plugins.
---

# Writing a plugin

A plugin is a folder with a `plugin.json` manifest and a provider class. It extends Kestello only through the public API on `$cms`, the same calls core modules use. In [kestellohq/examples](https://github.com/kestellohq/examples), [`hello`](https://github.com/kestellohq/examples/tree/main/plugins/hello) shows every extension point in a small space and [`catalog`](https://github.com/kestellohq/examples/tree/main/plugins/catalog) is a full plugin with blocks, templates, jobs and a lifecycle test. A plugin is usually developed in its own repository, with the API stubs for editor support ([editor setup](https://docs.kestello.com/developers/editor-setup)).

## 1. Scaffold

```sh
php bin/cms make:plugin acme-shop        # creates content/plugins/acme-shop/
php bin/cms plugin:activate acme-shop    # runs its migrations and activate()
```

Slugs are lowercase letters, digits and dashes. The folder name must equal the slug.

## 2. The manifest

`plugin.json` (from the `hello` example):

```json
{
  "slug": "hello",
  "name": "Hello",
  "version": "1.2.0",
  "description": "What it does, in one sentence.",
  "requires": { "core": "^0.1", "php": ">=8.4" },
  "namespace": "Plugins\\Hello\\",
  "provider": "Plugins\\Hello\\HelloProvider",
  "permissions": {}
}
```

Other keys `Manifest::load()` reads: `requires.extensions` (PHP extensions), `dependencies` (slug => constraint), `conflicts`, `replaces`. `permissions` declares what the plugin may reach, such as `network` hosts for the HTTP client; anything not declared is refused. Validation is strict: a broken manifest means the plugin is never loaded.

## 3. The provider

Extend `Kestello\Cms\Extension\PluginProvider`. Hooks (all optional): `register(Container)`, `boot(Cms)`, `activate(Cms)`, `upgrade(Cms, $from, $to)`, `deactivate(Cms)`, `uninstall(Cms)`. Helpers: `$this->path()`, `$this->setting($key)`, `$this->secret($key)`.

```php
final class HelloProvider extends PluginProvider
{
    public function register(Container $container): void
    {
        $container->singleton(Greetings::class, fn() => new Greetings());
    }

    public function boot(Cms $cms): void
    {
        $cms->routes->get('/hello/{name}', [GreetingController::class, 'show'])->name('hello.show');
        $cms->auth->roles->capability('hello.manage', 'Manage greetings', grantTo: ['admin']);
        $cms->api->scope('hello:read', 'Read greetings');
        $cms->api->endpoint(Api\ListGreetings::class);
        $cms->admin->page('', '@hello/admin/Greetings', 'Greetings', 'hello.manage', ['label' => 'Greetings', 'icon' => 'smiley', 'group' => 'plugins']);
        $cms->admin->widget('dashboard', '@hello/admin/GreetingsWidget', title: 'Greetings', size: 'quarter', capability: 'hello.manage');
        $cms->admin->settings('greeting', 'Hello', Schema::object([
            'salutation' => Schema::string()->min(1)->max(30)->default('Hello')->description('The first word of every greeting.'),
        ]), labels: ['salutation' => 'Salutation'], capability: 'hello.manage');
    }
}
```

What else `boot()` can register (all shown in the two examples): `$cms->pages->share()`, `$cms->filters->add()`, `$cms->events->on()`, `$cms->queue->job()`, `$cms->schedule->job()`, `$cms->console->command()`, `$cms->api->extend('users')->field()`, `$cms->admin->column()` and `->slot()`, `$cms->content->type()`, `$cms->blocks->register()`, `$cms->content->fields->register()`, `$cms->notifications->type()`, `$cms->webhooks->event()`, `$cms->hooks->event()`.

Names are scoped to the slug and checked at registration: API paths `/<slug>/…`, scopes `<slug>:…`, admin components `@<slug>/…` (served at `/admin/p/<slug>/…`), blocks `<slug>/…`, field types `<slug>.…`, tables `p_<slug>_…` (dashes become underscores). Settings are stored as `plugin.<slug>.<tab>.<field>`.

Task guides: `../rest-endpoint/SKILL.md`, `../admin-page/SKILL.md`, `../add-block/SKILL.md`, `../add-field-type/SKILL.md`, `../database-migration/SKILL.md`.

## 4. Folders

```
acme-shop/
  plugin.json
  src/                    PSR-4 under "namespace"
  database/migrations/    YYYY_MM_DD_HHMMSS_name.php (php bin/cms make:migration <name> --plugin=acme-shop)
  resources/js/pages/     Vue pages: Greeting.vue is "@acme-shop/Greeting", admin/X.vue is "@acme-shop/admin/X", blocks/, fields/
  resources/lang/         acme-shop.pot and <locale>.po|json|php
  templates/ parts/ patterns/   block trees (keys start with the slug)
  assets/build/           built Vue pages, shipped with the plugin
  vite.config.js          optional; built on core/build/preset.mjs
  tests/                  *Test.php
```

Build the Vue pages with `node core/build/build.mjs content/plugins/acme-shop` and ship `assets/build/`.

## 5. Translations

- PHP: `__('Greeting', domain: 'acme-shop')`. Labels passed to `label()`, `->label()`, `->help()`, `->description()` and the admin registration methods are translated in the plugin's domain for you.
- Vue: `const { __ } = useI18n('acme-shop');` from `@kestello/sdk`. ICU plural messages go in a computed property, not a template interpolation (their `}}` would close `{{ }}`).
- Template: `php bin/cms i18n:extract acme-shop` writes `resources/lang/acme-shop.pot`. For a plugin in its own repository: `php /path/to/kestello/bin/cms i18n:extract --path=. --domain=acme-shop`.

See `../i18n/SKILL.md`.

## 6. A plugin in its own repository

Generate editor and analysis helpers from a Kestello checkout:

```sh
php /path/to/kestello/bin/cms ide:helpers --out=. --stubs   # .phpstorm.meta.php and kestello.stub.php
```

Then let PHPStan read the stub (do not include it at runtime):

```neon
parameters:
  level: 6
  paths: [src]
  scanFiles: [kestello.stub.php]
```

## 7. Package and sign

```sh
php bin/cms package:keygen --out=acme.key --name="Acme Plugins"   # once; keep acme.key secret
php bin/cms package:build content/plugins/acme-shop --out=dist --key=acme.key
php bin/cms package:verify dist/acme-shop-1.0.0.zip
php bin/cms plugin:check acme-shop                               # static safety check
```

`package:sign <zip> --key=<file>` signs a zip built earlier. A site installs signed packages from the admin when the publisher's public key is in `app.packages.trusted_keys`.

## 8. Tests

`tests/Package/PluginLifecycleTest.php` (with the fixture in `tests/fixtures/plugins/notebook`) installs a plugin from a zip, activates, uses, deactivates and uninstalls it on SQLite and every database in `KESTELLO_TEST_DATABASES`; the `catalog` example's `tests/CatalogTest.php` does the same for a larger plugin. Copy that shape for anything with tables. Run with `php bin/cms test <plugin>/tests`. Every fix comes with a test (`../testing/SKILL.md`).

## Done when

- [ ] The manifest validates and declares every permission used.
- [ ] Capabilities, scopes and endpoints are registered; nothing sensitive is public.
- [ ] Text goes through `__()` / `useI18n()`, and the `.pot` is current.
- [ ] `assets/build/` is rebuilt; migrations have a working `down()`.
- [ ] Tests cover the lifecycle and every fix; `npm run lint` passes on changed files.

Related: `../security-review/SKILL.md`, `../write-theme/SKILL.md`, `../architecture/SKILL.md`.
