---
name: add-field-type
description: Adding a custom field type (server validation, normalization, indexing, and the admin input component) in core or a plugin. Load it when a content type or block needs a kind of value the built-in fields don't cover.
---

# Adding a field type

A field type says how one kind of value is validated, normalized, indexed and edited. Content types, blocks and forms all use the same types, so a new type works everywhere fields do. Built-in types are in `core/src/Cms/Content/Fields/` (`NumberField`, `ChoiceField`, `TableField`, …).

Before writing one, check whether a built-in type with options (`Field::number()->min()`, `Field::select()`, `Field::group()`, `Field::repeater()`) already does the job.

## 1. The class

Extend `Kestello\Cms\Content\FieldType` (`core/src/Cms/Content/FieldType.php`):

```php
namespace Plugins\AcmeMaps;

use Kestello\Cms\Api\Schema;
use Kestello\Cms\Content\Field;
use Kestello\Cms\Content\FieldType;
use Kestello\Cms\Content\FieldTypes;

final class PointField extends FieldType
{
    public function name(): string
    {
        return 'acme-maps.point';                  // plugins: "<slug>.<name>"
    }

    public function component(): string
    {
        return '@acme-maps/fields/Point';          // resources/js/pages/fields/Point.vue
    }

    public function schema(Field $field, FieldTypes $types): Schema
    {
        return Schema::object([
            'lat' => Schema::number()->min(-90)->max(90),
            'lng' => Schema::number()->min(-180)->max(180),
        ]);
    }
}
```

Register it in the provider's `boot()`:

```php
$cms->content->fields->register(new PointField());
```

`FieldTypes::register()` refuses a name outside the owner's `<slug>.` prefix and a name already taken.

### Optional hooks

| Method | Override when |
| --- | --- |
| `check($value, $field, $types): list<string>` | A rule the schema can't express (a referenced record exists). Return error messages, written for the editor and wrapped in `__()`. |
| `normalize($value, $field, $types)` | The stored value differs from the input (trim, sanitize HTML, canonical dates). |
| `index($value, $field)`, `indexKind($field)`, `singleValued($field)` | The field can be `->indexed()` for filtering and sorting: return `[['string' => …]]`, `[['number' => …]]` or `[['date' => 'Y-m-d H:i:s']]`. |
| `media($value, $field, $types)` | The value refers to media library ids. |
| `text($value, $field, $types)` | Revision comparisons should show something readable instead of ids or markup. |
| `describe($field, $types)` | The admin input needs options other than the field's own (never send secrets). |

Read options set with `->option('key', $value)` through `$field->getOption('key', $default)`. Field options and choice labels are translated in the owner's domain when sent to the admin (`FieldTypes::describe()`).

## 2. The admin input

The component receives the value through `v-model` and these props (see `core/resources/js/pages/fields/Number.vue`):

```vue
<script setup>
const model = defineModel({ default: null });
defineProps({
    field: { type: Object, required: true }, // name, label, help, options, …
    id: { type: String, default: '' },
    invalid: Boolean,
    disabled: Boolean,
    errors: { type: Object, default: () => ({}) },
    path: { type: String, default: '' },
});
</script>
```

- Put `:id="id"` on the main control so the field's label points at it, and `:aria-invalid="invalid ? 'true' : undefined"`.
- An empty input means no value (`null`), never `0` or `''`.
- Text in the component uses `__()` (or `useI18n('<slug>')` in a plugin); no placeholder text (see `../admin-ui-design/SKILL.md`).
- Rebuild with `node core/build/build.mjs <package dir>` (core: `npm run build:core`) and commit `assets/build/`.

## 3. Tests

There is no core test of a custom type yet; write one with the registry directly:

```php
$types = new FieldTypes();
$types->register(new PointField());
$field = Field::make('where', 'acme-maps.point');

[$clean, $errors] = $types->schemaFor($field, draft: false)->validate(['lat' => 91, 'lng' => 0]);
$this->assertSame(['lat'], array_keys($errors));   // paths are relative to the value

$errors = [];
$out = $types->finish([$field], ['where' => ['lat' => 1, 'lng' => 2]], '', $errors);
```

Cover: valid and invalid values, `check()` messages, `normalize()` output, `index()` values for indexed fields, and that a plugin can't register outside its prefix (`ExecutionContext::run('plugin:other', …)` throws). Pure JS helpers of the input get a `tests/js/*.test.mjs` test.

```sh
npm run test:php
npm test
npm run lint
```

## Done when

- [ ] The name is `<slug>.<name>` (or a plain name in core) and the component path exists.
- [ ] Schema, `check()` and `normalize()` are tested.
- [ ] The input is labelled, keyboard usable and translated.
- [ ] Assets rebuilt, lint and tests pass, changelog entry added for core.

Related: `../add-block/SKILL.md`, `../write-plugin/SKILL.md`, `../testing/SKILL.md`, `../security-review/SKILL.md` (anything holding HTML must be sanitized in `normalize()`).
