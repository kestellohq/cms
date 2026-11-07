---
name: i18n
description: How text is translated in Kestello, in PHP, in the admin's JavaScript and Vue, and in plugins and themes. Load it whenever you add or change text people see, register labels, or touch language packs, the site language or the translation template.
---

# Translations (i18n)

All text people see goes through a translation function. Messages use ICU MessageFormat, the same syntax in PHP (intl) and in the browser, so one catalogue serves both.

## Domains and packs

- A **domain** groups messages: `core` for core, a plugin's or theme's slug for its own text.
- **Packs** live in `<package>/resources/lang/<locale>.po`, `.json` or `.php` (core: `core/resources/lang/`). `.po` files may use `msgctxt` and gettext plural forms.
- Lookup order (`Kestello\Support\Translator`): the locale (`sr_Latn_RS`), then its language (`sr`), then the fallback (`app.fallback_locale`, default `en`), then the English source text itself.
- `Kernel::bootTranslations()` registers `core/resources/lang` and every active plugin's and theme's `resources/lang`.

## The site language

`Kestello\Cms\I18n\SiteLanguage` resolves the locale: the **Language** field in **Settings → General** (setting key `language`), else `app.locale`, else `en`. Its choices are English plus every locale core has a pack for, each named in its own language. Pages render `<html lang dir>` from it (`PageRenderer`).

## PHP

```php
use function Kestello\{__, trans_choice};

__('Save changes');
__('Hello, {name}!', ['name' => $user->name]);
trans_choice('{count, plural, one {# file} other {# files}}', $count);
__('Greeting', domain: 'acme-shop');                     // a plugin's own text
Translator::get('Post', domain: 'acme-shop', context: 'verb');
Translator::withLocale('de', fn() => $mailer->send(...)); // an email in the recipient's language
```

## Labels you register are translated for you

`Kestello\Cms\I18n\Labels` translates registered text in the owner's domain when it is sent to the admin (`core`, or the slug of `plugin:<slug>` / `theme:<slug>`), falling back to core's translation:

- block label and description (`BlockRegistry::describe()`),
- field label, help and choice labels (`FieldTypes::describe()` with a domain),
- content type and taxonomy names (`ListContentTypes`),
- menu entries and groups, page titles, widget titles, slot and column labels (`AdminRegistry`, `AdminPageController`),
- settings tab labels, descriptions, field labels and schema descriptions (`SiteSettings::describe()`).

So write `->label('Book', 'Books')`, `->help('Thirteen digits.')`, `$admin->page('books', '@acme/Books', 'Books on sale')` with plain English literals. Do not wrap them in `__()` yourself.

## JavaScript and Vue

```js
import { __, useI18n } from '@kestello/sdk';

__('Save draft');                                         // core text
__('{n} blocks selected', { n: count });

// in a plugin: bind your domain once; lookups fall back to core's translation
const { __ } = useI18n('acme-shop');
```

- `core/resources/js/sdk/messageformat.js` implements the ICU subset: `{arg}`, `plural` (with `=N`, `offset:` and `#`), `selectordinal`, `select`, `number` (`integer`, `percent`), `date` and `time` (`short`, `medium`, `long`, `full`), and apostrophe quoting (`'{'` is a literal brace, `''` an apostrophe). A message that doesn't parse falls back to `{name}` replacement and warns once in the console.
- `core/resources/js/sdk/catalogue.js` holds messages per domain.
- The admin downloads its catalogue before the first render: the boot data carries `admin.translations`, a URL like `/admin/translations/sr?v=<hash>` served by `BrowserCatalogue::respond()`, cached for a year at the current version. English downloads nothing.

### The Vue `}}` trap

An ICU plural ends in `}}`, which also ends a `{{ }}` interpolation. Put plural and select messages in a computed property:

```js
const greeted = computed(() => __('{name} has been greeted {n, plural, one {# time} other {# times}}.', { name, n: count.value }));
```

```vue
<p>{{ greeted }}</p>
```

## Writing messages

- Whole sentences with named placeholders: `__('{who} commented on {title}', { who, title })`, never `__('Commented on') + ' ' + title`.
- Plurals with ICU, never `time(s)` or `count === 1 ? ... : ...`.
- Only literal strings are extracted: `__($text)` cannot be found by the extractor.
- An apostrophe before `{` or `}` starts ICU quoting. Use typographic quotes (`“{name}”`) or rephrase.

## The translation template

`bin/cms i18n:extract` (class `Kestello\Cms\I18n\Extractor`) finds `__()`, `trans_choice()`, `Translator::get()` / `choice()`, `->label()`, `->help()`, `->description()`, the text arguments of `page`, `group`, `link`, `widget` and `column`, named `label:` / `title:` / `description:` / `labels:` arguments, `'label' => …` style array keys and `__()` in `.js`, `.mjs` and `.vue` files. It skips `node_modules`, `vendor`, `build`, `storage`, `tests` and `releases`.

```bash
docker exec cms_app php bin/cms i18n:extract                          # core/resources/lang/kestello.pot
docker exec cms_app php bin/cms i18n:extract hello                    # an installed plugin or theme
docker exec -w /var/www/html/content/plugins/acme cms_app php /var/www/html/bin/cms i18n:extract --path=. --domain=acme   # a plugin by its folder
docker exec cms_app php bin/cms i18n:extract --check                  # CI: fails when stale
```

`npm run i18n` and `npm run i18n:check` run the core ones. The template has no creation date, so it only changes when the text does. Commit it with the change that altered the text.

## Done when

- [ ] Every new string people see goes through `__()` / `trans_choice()` (or a registered label).
- [ ] Plugin and theme JavaScript uses `useI18n('<slug>')`; PHP passes `domain: '<slug>'`.
- [ ] Plurals use ICU and live in computed properties in Vue.
- [ ] `npm run i18n` was run and the `.pot` is committed; `i18n:extract --check` passes.
- [ ] Tests cover new translation logic (see [testing](../testing/SKILL.md)); `tests/I18n/` has examples.
