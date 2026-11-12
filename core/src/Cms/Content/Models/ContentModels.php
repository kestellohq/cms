<?php

declare(strict_types=1);

namespace Kestello\Cms\Content\Models;

use InvalidArgumentException;
use Kestello\Cms\Cms;
use Kestello\Cms\Content\Checklist;
use Kestello\Cms\Content\ContentException;
use Kestello\Cms\Content\Entries;
use Kestello\Cms\Content\Field;
use Kestello\Cms\Content\Permalinks;
use Kestello\Framework\Core\ExecutionContext;
use Kestello\Framework\Database\QueryBuilder;
use Kestello\Framework\Log\Log;
use Kestello\Support\Html;
use Kestello\Support\Json;
use Kestello\Support\Translator;
use OutOfBoundsException;

/**
 * Content types and taxonomies built in the admin, stored in
 * content_models and registered at boot through the same registry as
 * code, so the API, editor, search, blocks and feeds treat them the same.
 * Code wins: a model whose name a package already declares is skipped.
 *
 * Every field keeps a stable id, so renaming a field moves its values
 * instead of losing them. Changes that can lose data (removing or
 * retyping a field that has values) need confirmation; preview() lists
 * them with the number of entries affected.
 */
final class ContentModels
{
    public const array KINDS = ['type', 'taxonomy'];

    /** Field types the builder offers, and the options each may set (name => value kind). */
    public const array OPTIONS = [
        'text' => ['placeholder' => 'string', 'min' => 'int', 'max' => 'int'],
        'textarea' => ['placeholder' => 'string', 'min' => 'int', 'max' => 'int', 'rows' => 'int'],
        'rich_text' => ['max' => 'int'],
        'number' => ['min' => 'number', 'max' => 'number', 'step' => 'number', 'integer' => 'bool'],
        'boolean' => [],
        'date' => [],
        'datetime' => [],
        'email' => ['placeholder' => 'string'],
        'url' => ['placeholder' => 'string'],
        'link' => ['placeholder' => 'string'],
        'color' => [],
        'select' => ['choices' => 'choices'],
        'multi_select' => ['choices' => 'choices'],
        'media' => ['kinds' => 'kinds', 'multiple' => 'bool', 'max' => 'int'],
        'entry' => ['types' => 'types', 'multiple' => 'bool', 'max' => 'int'],
        'term' => ['taxonomy' => 'taxonomy', 'multiple' => 'bool', 'max' => 'int'],
        'user' => ['multiple' => 'bool', 'max' => 'int'],
        'blocks' => [],
        'group' => [],
        'repeater' => ['min' => 'int', 'max' => 'int'],
    ];

    /** Field types that can be copied into entry_index (one plain value). */
    private const array INDEXABLE = ['text', 'email', 'url', 'color', 'number', 'boolean', 'date', 'datetime', 'select', 'entry', 'term', 'user'];

    /** Names the platform uses for its own fields. */
    private const array RESERVED_FIELDS = ['seo'];

    private const int MAX_FIELDS = 150;
    private const int MAX_DEPTH = 3;

    /** @var array<string, string> "type:event" => why it was skipped at boot */
    private array $skipped = [];

    public function __construct(private readonly Cms $cms) {}

    // ── boot ─────────────────────────────────────────────────────────────

    /** @internal ContentModule::finalize: register stored models (after packages, so code wins). */
    public function register(): void
    {
        try {
            $rows = QueryBuilder::table('content_models')->orderBy('id')->get();
        } catch (\Throwable) {
            return;       // not migrated yet
        }
        // types first: taxonomies point at them
        usort($rows, static fn(array $a, array $b) => [$a['kind'] === 'type' ? 0 : 1, (int) $a['id']] <=> [$b['kind'] === 'type' ? 0 : 1, (int) $b['id']]);
        foreach ($rows as $row) {
            $key = $row['kind'] . ':' . $row['name'];
            $existing = $row['kind'] === 'type' ? $this->cms->content->get((string) $row['name']) : $this->cms->content->getTaxonomy((string) $row['name']);
            if ($existing !== null) {
                $this->skipped[$key] = "Also defined by {$existing->owner}, which takes precedence.";
                continue;
            }
            try {
                [$def, $errors] = $this->normalize((string) $row['kind'], (string) $row['name'], Json::decode((string) $row['definition']), null);
                if ($errors !== []) {
                    $this->skipped[$key] = 'Not valid any more: ' . implode(' ', array_map(static fn($e) => implode(' ', (array) $e), $errors));
                    continue;
                }
                ExecutionContext::run('site', fn() => $this->build((string) $row['kind'], (string) $row['name'], $def));
            } catch (\Throwable $e) {
                $this->skipped[$key] = $e->getMessage();
                Log::warning("content model {$key} skipped: " . $e->getMessage());
            }
        }
    }

    /** @return array<string, string> */
    public function skipped(): array
    {
        return $this->skipped;
    }

    private function build(string $kind, string $name, array $def): void
    {
        if ($kind === 'taxonomy') {
            $this->cms->content->taxonomy($name)->label($def['singular'], $def['plural'])->description($def['description'])
                ->hierarchical($def['hierarchical'])->for(...$def['types'])->path($def['path']);

            return;
        }
        $type = $this->cms->content->type($name)->label($def['singular'], $def['plural'])->icon($def['icon'])->description($def['description'])
            ->hierarchical($def['hierarchical'])->excerpt($def['excerpt'])->revisions($def['revisions'])->path($def['path'])
            ->archive($def['archive'])->searchable($def['searchable'])->menuOrder($def['menu_order'])->permalink($def['permalink'])
            ->fields(array_map($this->field(...), $def['fields']))->checklist($def['checklist'] ?? []);
        unset($type);
    }

    private function field(array $f): Field
    {
        $field = Field::make($f['name'], $f['type'])->label($f['label'])->help($f['help'])->required($f['required'])
            ->indexed($f['indexed'])->internal($f['internal'])->width($f['width']);
        if ($f['default'] !== null) {
            $field->default($f['default']);
        }
        if (($f['translatable'] ?? null) !== null) {
            $field->translatable((bool) $f['translatable']);
        }
        if ($f['searchable'] !== null) {
            $field->searchable($f['searchable']);
        }
        foreach ($f['options'] as $key => $value) {
            $field->option($key, $value);
        }
        if (($f['conditions'] ?? null) !== null) {
            $field->option('conditions', $f['conditions']);
        }
        if ($f['children'] !== []) {
            $field->children(array_map($this->field(...), $f['children']));
        }

        return $field;
    }

    // ── reading ──────────────────────────────────────────────────────────

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        return array_map(self::present(...), QueryBuilder::table('content_models')->orderBy('kind')->orderBy('name')->get());
    }

    public function find(int $id): array
    {
        $row = QueryBuilder::table('content_models')->where('id', $id)->first();

        return $row === null ? throw new OutOfBoundsException("No content model {$id}.") : self::present($row);
    }

    private static function present(array $row): array
    {
        return ['id' => (int) $row['id'], 'kind' => (string) $row['kind'], 'name' => (string) $row['name'],
            'definition' => Json::decode((string) $row['definition']), 'updated_at' => $row['updated_at'] ?? null];
    }

    // ── writing ──────────────────────────────────────────────────────────

    /** @throws ContentException invalid input */
    public function create(string $kind, string $name, array $input, ?int $userId = null): array
    {
        $errors = [];
        if (!in_array($kind, self::KINDS, true)) {
            throw ContentException::invalid(['kind' => ['Choose a content type or a taxonomy.']]);
        }
        if (preg_match('/^[a-z][a-z0-9-]{0,31}$/', $name) !== 1) {
            $errors['name'] = ['Lowercase letters, digits and dashes, starting with a letter (up to 32).'];
        } elseif ($this->taken($kind, $name)) {
            $errors['name'] = ['There is already a ' . ($kind === 'type' ? 'content type' : 'taxonomy') . " called {$name}."];
        }
        [$def, $more] = $this->normalize($kind, $name, $input, null);
        $errors += $more;
        if ($errors !== []) {
            throw ContentException::invalid($errors);
        }
        $now = gmdate('Y-m-d H:i:s');
        $id = QueryBuilder::table('content_models')->insert(['kind' => $kind, 'name' => $name, 'definition' => Json::encode($def),
            'created_by' => $userId, 'created_at' => $now, 'updated_at' => $now]);

        return $this->find((int) $id);
    }

    /**
     * Save a new definition. Renamed fields carry their values along; with
     * $confirm, removed fields' values are deleted when $purge is set
     * (otherwise they stay stored and come back if the field returns).
     *
     * @return array{model: array<string, mixed>, changes: list<array<string, mixed>>}
     * @throws ContentException invalid input, or "conflict" listing changes that need $confirm
     */
    public function update(int $id, array $input, bool $confirm = false, bool $purge = false): array
    {
        $model = $this->find($id);
        [$def, $errors] = $this->normalize($model['kind'], $model['name'], $input, $model['definition']);
        if ($errors !== []) {
            throw ContentException::invalid($errors);
        }
        $changes = $this->diff($model['kind'], $model['name'], $model['definition'], $def);
        if (!$confirm && array_filter($changes, static fn($c) => $c['destructive']) !== []) {
            throw ContentException::conflict('Some changes affect stored values. Review them and confirm.');
        }
        if ($model['kind'] === 'type') {
            $this->migrate($model['name'], $changes, $purge);
            foreach ($changes as $c) {
                if ($c['change'] === 'path' && $c['from'] !== null && $c['to'] !== null && $this->cms->container->has(\Kestello\Cms\Redirects\Redirects::class)) {
                    // every address under the old prefix keeps working
                    $this->cms->container->make(\Kestello\Cms\Redirects\Redirects::class)->recordMove($c['from'], $c['to'], null, true);
                }
            }
        }
        QueryBuilder::table('content_models')->where('id', $id)->update(['definition' => Json::encode($def), 'updated_at' => gmdate('Y-m-d H:i:s')]);

        return ['model' => $this->find($id), 'changes' => $changes];
    }

    /** What saving $input would change. @return list<array<string, mixed>> */
    public function preview(int $id, array $input): array
    {
        $model = $this->find($id);
        [$def, $errors] = $this->normalize($model['kind'], $model['name'], $input, $model['definition']);
        if ($errors !== []) {
            throw ContentException::invalid($errors);
        }

        return $this->diff($model['kind'], $model['name'], $model['definition'], $def);
    }

    /** @throws ContentException conflict while entries or terms still use it */
    public function delete(int $id): void
    {
        $model = $this->find($id);
        if ($model['kind'] === 'type') {
            $count = QueryBuilder::table('entries')->where('type', $model['name'])->count();
            if ($count > 0) {
                throw ContentException::conflict("{$count} " . ($count === 1 ? 'entry uses' : 'entries use') . ' this type (the trash counts too). Delete them first.');
            }
            foreach ($this->all() as $other) {
                if ($other['kind'] === 'taxonomy' && in_array($model['name'], (array) ($other['definition']['types'] ?? []), true)) {
                    throw ContentException::conflict("The {$other['definition']['plural']} taxonomy is for this type. Change it first.");
                }
            }
        } else {
            $count = QueryBuilder::table('terms')->where('taxonomy', $model['name'])->count();
            if ($count > 0) {
                throw ContentException::conflict("{$count} " . ($count === 1 ? 'term belongs' : 'terms belong') . ' to this taxonomy. Delete them first.');
            }
        }
        QueryBuilder::table('content_models')->where('id', $id)->delete();
    }

    private function taken(string $kind, string $name): bool
    {
        $declared = $kind === 'type' ? $this->cms->content->get($name) : $this->cms->content->getTaxonomy($name);

        return $declared !== null || QueryBuilder::table('content_models')->where('kind', $kind)->where('name', $name)->count() > 0;
    }

    // ── validation ───────────────────────────────────────────────────────

    /**
     * Clean a definition: unknown keys dropped, values checked, field ids
     * kept from $previous (by id) or created.
     *
     * @return array{0: array<string, mixed>, 1: array<string, list<string>>}
     */
    public function normalize(string $kind, string $name, array $in, ?array $previous): array
    {
        $errors = [];
        $text = static function (string $key, int $max, bool $required) use ($in, &$errors): string {
            $v = trim((string) (is_scalar($in[$key] ?? null) ? $in[$key] : ''));
            if ($required && $v === '') {
                $errors[$key][] = Translator::get('Required.');
            } elseif (mb_strlen($v) > $max) {
                $errors[$key][] = Translator::get('At most {max} characters.', ['max' => $max]);
            }
            return $v;
        };
        $def = [
            'singular' => $text('singular', 60, true),
            'plural' => $text('plural', 60, true),
            'description' => $text('description', 300, false),
            'hierarchical' => (bool) ($in['hierarchical'] ?? false),
        ];
        $path = $in['path'] ?? null;
        $path = is_string($path) && trim($path) !== '' ? '/' . trim(strtolower(trim($path)), '/') : null;
        if ($path !== null && preg_match('#^(/[a-z0-9][a-z0-9-]*){1,3}$#', $path) !== 1) {
            $errors['path'][] = Translator::get('An address like /events: lowercase letters, digits and dashes.');
        } elseif ($path !== null && ($why = $this->pathTaken($kind, $name, $path)) !== null) {
            $errors['path'][] = Translator::get('{path} is already used by {why}.', ['path' => $path, 'why' => $why]);
        }
        $def['path'] = $path;

        if ($kind === 'taxonomy') {
            $types = array_values(array_unique(array_filter((array) ($in['types'] ?? []), 'is_string')));
            foreach ($types as $t) {
                if ($this->cms->content->get($t) === null) {
                    $errors['types'][] = Translator::get('Unknown content type {t}.', ['t' => $t]);
                }
            }
            if ($types === []) {
                $errors['types'][] = Translator::get('Choose at least one content type.');
            }
            $def['types'] = $types;

            return [$def, $errors];
        }

        $icon = (string) ($in['icon'] ?? 'file-text');
        $def['icon'] = preg_match('/^[a-z0-9-]{1,40}$/', $icon) === 1 ? $icon : 'file-text';
        $def['excerpt'] = (bool) ($in['excerpt'] ?? false);
        $def['archive'] = (bool) ($in['archive'] ?? false) && $path !== null;
        $def['searchable'] = (bool) ($in['searchable'] ?? true);
        $def['revisions'] = max(0, min(500, (int) ($in['revisions'] ?? 25)));
        $def['menu_order'] = max(0, min(1000, (int) ($in['menu_order'] ?? 50)));
        $permalink = (string) ($in['permalink'] ?? '{slug}');
        $def['permalink'] = isset(Permalinks::PATTERNS[$permalink]) ? $permalink : '{slug}';

        $known = [];
        foreach ($previous['fields'] ?? [] as $f) {
            $this->collectIds($f, $known);
        }
        $seen = [];
        $count = 0;
        $def['fields'] = $this->fields(is_array($in['fields'] ?? null) ? array_values($in['fields']) : [], 'fields', 1, $known, $seen, $count, $errors);
        if ($count > self::MAX_FIELDS) {
            $errors['fields'][] = Translator::get('At most {max} fields.', ['max' => self::MAX_FIELDS]);
        }
        $def['checklist'] = $this->checklist($in['checklist'] ?? [], $errors);

        return [$def, $errors];
    }

    /**
     * A type's checklist from the builder: each item a known check, at most Checklist::MAX_ITEMS,
     * each check once (terms: once per taxonomy).
     *
     * @param array<string, list<string>> $errors
     * @return list<array{check: string, level: string, taxonomy?: string, min?: int}>
     */
    private function checklist(mixed $in, array &$errors): array
    {
        if (!is_array($in) || ($in !== [] && !array_is_list($in))) {
            $errors['checklist'][] = Translator::get('Must be a list.');
            return [];
        }
        if (count($in) > Checklist::MAX_ITEMS) {
            $errors['checklist'][] = Translator::get('At most {max} items.', ['max' => Checklist::MAX_ITEMS]);
            return [];
        }
        $out = [];
        $seen = [];
        foreach ($in as $i => $raw) {
            try {
                $item = Checklist::item(is_array($raw) ? $raw : []);
            } catch (InvalidArgumentException $e) {
                $errors["checklist.{$i}"][] = $e->getMessage();
                continue;
            }
            if (!in_array($item['check'], $this->cms->content->checks->names(), true)) {
                $errors["checklist.{$i}"][] = Translator::get('{check} is not available: is its plugin active?', ['check' => $item['check']]);
                continue;
            }
            if (isset($item['taxonomy']) && $this->cms->content->getTaxonomy($item['taxonomy']) === null) {
                $errors["checklist.{$i}"][] = Translator::get('Unknown taxonomy {t}.', ['t' => $item['taxonomy']]);
                continue;
            }
            $key = $item['check'] . ':' . ($item['taxonomy'] ?? '');
            if (isset($seen[$key])) {
                $errors["checklist.{$i}"][] = Translator::get('This check is already on the list.');
                continue;
            }
            $seen[$key] = true;
            $out[] = $item;
        }

        return $out;
    }

    /** @param array<string, true> $known @param array<string, true> $seen */
    private function fields(array $list, string $at, int $depth, array $known, array &$seen, int &$count, array &$errors): array
    {
        $out = [];
        $names = [];
        foreach ($list as $i => $in) {
            $count++;
            $p = "{$at}.{$i}";
            if (!is_array($in)) {
                $errors[$p][] = Translator::get('Not a field.');
                continue;
            }
            $name = (string) ($in['name'] ?? '');
            $type = (string) ($in['type'] ?? '');
            if (preg_match('/^[a-z][a-z0-9_]{0,63}$/', $name) !== 1) {
                $errors["{$p}.name"][] = Translator::get('Lowercase letters, digits and underscores, starting with a letter.');
            } elseif (isset($names[$name])) {
                $errors["{$p}.name"][] = Translator::get('Another field here is already called {name}.', ['name' => $name]);
            } elseif ($depth === 1 && in_array($name, self::RESERVED_FIELDS, true)) {
                $errors["{$p}.name"][] = Translator::get('{name} is used by the platform. Choose another name.', ['name' => $name]);
            }
            $earlier = array_map('strval', array_keys($names));
            $names[$name] = true;
            $plugin = !isset(self::OPTIONS[$type]) && $this->cms->content->fields->has($type) && str_contains($type, '.');
            if (!isset(self::OPTIONS[$type]) && !$plugin) {
                $errors["{$p}.type"][] = Translator::get('Choose a field type.');
                continue;
            }
            if (in_array($type, ['group', 'repeater', 'blocks'], true) && $depth >= self::MAX_DEPTH) {
                $errors["{$p}.type"][] = Translator::get('Too deeply nested.');
                continue;
            }
            $id = (string) ($in['id'] ?? '');
            // ids are opaque; a client may send its own (new fields) as long as it's well-formed and unique
            if (preg_match('/^[a-z0-9]{8}$/', $id) !== 1 || isset($seen[$id])) {
                $id = self::newId();
            }
            $seen[$id] = true;
            $label = trim((string) ($in['label'] ?? ''));
            $width = (string) ($in['width'] ?? 'full');
            $allowedWidths = $depth === 1 ? ['full', 'half', 'sidebar'] : ['full', 'half'];
            $default = $in['default'] ?? null;
            if (!is_scalar($default) || (is_string($default) && ($default === '' || mb_strlen($default) > 1000))) {
                $default = null;
            }
            $field = [
                'id' => $id,
                'name' => $name,
                'type' => $type,
                'label' => $label !== '' ? mb_substr($label, 0, 100) : ucfirst(str_replace('_', ' ', $name)),
                'help' => mb_substr(trim((string) ($in['help'] ?? '')), 0, 300),
                'required' => (bool) ($in['required'] ?? false),
                'indexed' => (bool) ($in['indexed'] ?? false),
                'internal' => (bool) ($in['internal'] ?? false),
                'searchable' => isset($in['searchable']) && $in['searchable'] !== null ? (bool) $in['searchable'] : null,
                'translatable' => isset($in['translatable']) && $depth === 1 ? (bool) $in['translatable'] : null,     // null: the type decides; nested fields follow their parent
                'width' => in_array($width, $allowedWidths, true) ? $width : 'full',
                'default' => in_array($type, ['group', 'repeater', 'blocks', 'media', 'entry', 'term', 'user', 'multi_select'], true) ? null : $default,
                'options' => $this->options($type, is_array($in['options'] ?? null) ? $in['options'] : [], "{$p}.options", $errors),
                'children' => [],
                'conditions' => null,
            ];
            if ($depth === 1) {
                [$field['conditions'], $conditionErrors] = \Kestello\Cms\Content\Conditions::normalize($in['conditions'] ?? null, $earlier);
                foreach ($conditionErrors as $message) {
                    $errors["{$p}.conditions"][] = $message;
                }
            }
            if ($field['indexed'] && ($depth > 1 || !in_array($type, self::INDEXABLE, true) || !empty($field['options']['multiple']))) {
                $errors["{$p}.indexed"][] = Translator::get('Only single values at the top level can be used for sorting and filtering.');
            }
            if ($type === 'group' || $type === 'repeater') {
                $field['children'] = $this->fields(is_array($in['children'] ?? null) ? array_values($in['children']) : [], "{$p}.children", $depth + 1, $known, $seen, $count, $errors);
                if ($field['children'] === [] && !isset($errors["{$p}.children"])) {
                    $errors["{$p}.children"][] = Translator::get('Add at least one field inside.');
                }
            }
            // the field type itself has the final say (e.g. a select without choices)
            if (!array_filter(array_keys($errors), static fn($k) => str_starts_with($k, $p . '.'))) {
                try {
                    $this->cms->content->fields->get($type)->schema($this->field($field), $this->cms->content->fields);
                } catch (\Throwable $e) {
                    $errors[$p][] = $e->getMessage();
                }
            }
            $out[] = $field;
        }

        return $out;
    }

    private function options(string $type, array $in, string $at, array &$errors): array
    {
        $out = [];
        foreach (self::OPTIONS[$type] ?? [] as $key => $kind) {
            if (!array_key_exists($key, $in) || $in[$key] === null || $in[$key] === '') {
                continue;
            }
            $v = $in[$key];
            switch ($kind) {
                case 'string':
                    $out[$key] = mb_substr(trim((string) (is_scalar($v) ? $v : '')), 0, 200);
                    break;
                case 'int':
                    is_numeric($v) && (int) $v >= 0 && (int) $v <= 1_000_000 ? $out[$key] = (int) $v : $errors["{$at}.{$key}"][] = Translator::get('A whole number.');
                    break;
                case 'number':
                    is_numeric($v) ? $out[$key] = $v + 0 : $errors["{$at}.{$key}"][] = Translator::get('A number.');
                    break;
                case 'bool':
                    $out[$key] = (bool) $v;
                    break;
                case 'choices':
                    $choices = [];
                    foreach ((array) $v as $value => $label) {
                        if (is_array($label)) {            // [{value, label}] from the form
                            [$value, $label] = [(string) ($label['value'] ?? ''), (string) ($label['label'] ?? '')];
                        }
                        $value = trim((string) $value);
                        if ($value === '' || mb_strlen($value) > 100 || isset($choices[$value])) {
                            $errors["{$at}.{$key}"][] = Translator::get('Each choice needs its own value (up to 100 characters).');
                            break;
                        }
                        $choices[$value] = mb_substr(trim((string) $label) !== '' ? trim((string) $label) : $value, 0, 200);
                    }
                    if ($choices === [] || count($choices) > 500) {
                        $errors["{$at}.{$key}"][] = Translator::get('Between 1 and 500 choices.');
                    }
                    $out[$key] = $choices;
                    break;
                case 'kinds':
                    $out[$key] = array_values(array_intersect(\Kestello\Cms\Media\MediaTypes::KINDS, (array) $v));
                    break;
                case 'types':
                    $out[$key] = array_values(array_filter((array) $v, fn($t) => is_string($t) && ($this->cms->content->get($t) !== null || $this->storedName('type', $t))));
                    break;
                case 'taxonomy':
                    $this->cms->content->getTaxonomy((string) $v) !== null || $this->storedName('taxonomy', (string) $v)
                        ? $out[$key] = (string) $v : $errors["{$at}.{$key}"][] = Translator::get('Choose a taxonomy.');
                    break;
            }
        }
        if ($type === 'term' && !isset($out['taxonomy']) && !isset($errors["{$at}.taxonomy"])) {
            $errors["{$at}.taxonomy"][] = Translator::get('Choose a taxonomy.');
        }

        return $out;
    }

    private function storedName(string $kind, string $name): bool
    {
        return QueryBuilder::table('content_models')->where('kind', $kind)->where('name', $name)->count() > 0;
    }

    /** What already answers at $path, or null. */
    private function pathTaken(string $kind, string $name, string $path): ?string
    {
        $first = explode('/', trim($path, '/'))[0];
        if (in_array($first, Entries::RESERVED_PATHS, true)) {
            return 'the site itself';
        }
        foreach ($this->cms->content->types() as $t) {
            if (!($kind === 'type' && $t->name === $name) && $t->getPath() === $path) {
                return 'the ' . mb_strtolower($t->plural());
            }
        }
        foreach ($this->cms->content->taxonomies() as $t) {
            if (!($kind === 'taxonomy' && $t->name === $name) && $t->getPath() === $path) {
                return 'the ' . mb_strtolower($t->plural());
            }
        }
        // a page at the top of the site ("/events") would quietly stop answering
        foreach ($this->cms->content->types() as $t) {
            if ($t->getPath() !== '') {
                continue;
            }
            $entry = \Kestello\Cms\Content\Entry::query()->ofType($t->name)->whereNull('parent_id')->where('slug', $first)->first();
            if ($entry !== null) {
                return 'the ' . mb_strtolower($t->singular()) . ' "' . $entry->title . '"';
            }
        }

        return null;
    }

    private function collectIds(array $field, array &$ids): void
    {
        $ids[(string) ($field['id'] ?? '')] = true;
        foreach ($field['children'] ?? [] as $child) {
            $this->collectIds($child, $ids);
        }
    }

    private static function newId(): string
    {
        return substr(bin2hex(random_bytes(4)), 0, 8);
    }

    // ── changes ──────────────────────────────────────────────────────────

    /**
     * Field changes by id: renamed (values move along), removed and retyped
     * (destructive when entries have values), and an address change.
     *
     * @return list<array{change: string, destructive: bool, field?: string, from?: mixed, to?: mixed, entries?: int}>
     */
    private function diff(string $kind, string $name, array $old, array $new): array
    {
        $changes = [];
        if (($old['path'] ?? null) !== ($new['path'] ?? null)) {
            $changes[] = ['change' => 'path', 'destructive' => false, 'from' => $old['path'] ?? null, 'to' => $new['path'] ?? null];
        }
        if ($kind !== 'type') {
            return $changes;
        }
        $before = self::flatten($old['fields'] ?? []);
        $after = self::flatten($new['fields'] ?? []);
        // Where each old field's values sit once its parents' renames are applied (parents come first).
        $current = [];
        foreach ($before as $id => $f) {
            $parent = $f['parent'];
            $base = $parent === null ? [] : [...($current[$parent] ?? $before[$parent]['path']), ...($before[$parent]['type'] === 'repeater' ? ['*'] : [])];
            $current[$id] = [...$base, $f['name']];
            if ($parent !== null && !isset($after[$parent]) && !isset($after[$id])) {
                continue;                       // its parent's removal covers it
            }
            $now = $after[$id] ?? null;
            $gone = $now === null || ($current[$id] !== $now['path'] && self::repeaters($current[$id]) !== self::repeaters($now['path']));
            if ($gone) {
                $count = $this->countValues($name, $f['path']);
                $changes[] = ['change' => 'removed', 'destructive' => $count > 0, 'field' => $f['label'], 'from' => self::dotted($f['path']), 'entries' => $count,
                    'path_remove' => $current[$id]] + ($now === null ? [] : ['reason' => 'moved in or out of a repeating group, so its values can\'t follow']);
                continue;
            }
            if ($current[$id] !== $now['path']) {
                $changes[] = ['change' => 'renamed', 'destructive' => false, 'field' => $now['label'], 'from' => self::dotted($f['path']), 'to' => self::dotted($now['path']),
                    'path_from' => $current[$id], 'path_to' => $now['path'], 'indexed' => $f['indexed'] && $now['indexed'] && $f['parent'] === null && $now['parent'] === null,
                    'entries' => $this->countValues($name, $f['path'])];
                $current[$id] = $now['path'];
            }
            if ($now['type'] !== $f['type']) {
                $count = $this->countValues($name, $f['path']);
                $changes[] = ['change' => 'retyped', 'destructive' => $count > 0, 'field' => $now['label'], 'from' => $f['type'], 'to' => $now['type'], 'entries' => $count,
                    'path' => $now['path']];
            }
        }

        return $changes;
    }

    /** @return array<string, array{path: list<string>, name: string, parent: ?string, type: string, label: string, indexed: bool}> id => field, parents first ("*" marks a repeater's items) */
    private static function flatten(array $fields, array $prefix = [], ?string $parent = null): array
    {
        $out = [];
        foreach ($fields as $f) {
            $path = [...$prefix, (string) $f['name']];
            $out[(string) $f['id']] = ['path' => $path, 'name' => (string) $f['name'], 'parent' => $parent, 'type' => (string) $f['type'],
                'label' => (string) $f['label'], 'indexed' => (bool) ($f['indexed'] ?? false)];
            if (($f['children'] ?? []) !== []) {
                $out += self::flatten($f['children'], $f['type'] === 'repeater' ? [...$path, '*'] : $path, (string) $f['id']);
            }
        }

        return $out;
    }

    /** The path up to its innermost repeater: values can move only between paths that share it. */
    private static function repeaters(array $path): array
    {
        $last = -1;
        foreach ($path as $i => $segment) {
            if ($segment === '*') {
                $last = $i;
            }
        }

        return array_slice($path, 0, $last + 1);
    }

    private static function dotted(array $path): string
    {
        return implode('.', array_map(static fn($s) => $s === '*' ? '[]' : $s, $path));
    }

    private function countValues(string $type, array $path): int
    {
        $count = 0;
        foreach ($this->chunks($type) as $rows) {
            foreach ($rows as $row) {
                $data = json_decode((string) $row['data'], true);
                if (is_array($data) && self::has($data, $path)) {
                    $count++;
                }
            }
        }

        return $count;
    }

    /** @return \Generator<list<array{id: int, data: string}>> */
    private function chunks(string $type): \Generator
    {
        $last = 0;
        do {
            $rows = QueryBuilder::table('entries')->select('id', 'data')->where('type', $type)->where('id', '>', $last)->orderBy('id')->limit(500)->get();
            if ($rows !== []) {
                $last = (int) end($rows)['id'];
                yield $rows;
            }
        } while (count($rows) === 500);
    }

    private static function has(array $data, array $path): bool
    {
        $key = array_shift($path);
        if ($key === '*') {
            foreach (array_is_list($data) ? $data : [] as $item) {
                if (is_array($item) && ($path === [] || self::has($item, $path))) {
                    return true;
                }
            }
            return false;
        }
        if (!array_key_exists($key, $data)) {
            return false;
        }

        return $path === [] ? $data[$key] !== null && $data[$key] !== '' && $data[$key] !== [] : is_array($data[$key]) && self::has($data[$key], $path);
    }

    /** Apply renames (and, with $purge, removals) to every entry of the type. */
    private function migrate(string $type, array $changes, bool $purge): void
    {
        $moves = array_values(array_filter($changes, static fn($c) => $c['change'] === 'renamed'));
        $removals = $purge ? array_values(array_filter($changes, static fn($c) => $c['change'] === 'removed')) : [];
        // text that becomes rich text is rendered as HTML from now on: it goes through the sanitizer like any rich text saved
        $toHtml = array_values(array_filter($changes, static fn($c) => $c['change'] === 'retyped' && $c['to'] === 'rich_text' && isset($c['path'])));
        if ($moves === [] && $removals === [] && $toHtml === []) {
            return;
        }
        foreach ($this->chunks($type) as $rows) {
            foreach ($rows as $row) {
                $data = json_decode((string) $row['data'], true);
                if (!is_array($data)) {
                    continue;
                }
                $before = $data;
                foreach ($moves as $m) {
                    $data = self::move($data, $m['path_from'], $m['path_to']);
                }
                foreach ($removals as $r) {
                    $data = self::remove($data, $r['path_remove']);
                }
                foreach ($toHtml as $t) {
                    $data = self::mapValues($data, $t['path'], static fn(mixed $v): mixed => is_string($v) ? Html::sanitize($v, 'rich-text') : $v);
                }
                if ($data !== $before) {
                    QueryBuilder::table('entries')->where('id', (int) $row['id'])->update(['data' => Json::encode($data === [] ? new \stdClass() : $data)]);
                }
            }
            $ids = array_map(static fn($r) => (int) $r['id'], $rows);
            foreach ($moves as $m) {
                if ($m['indexed'] && count($m['path_from']) === 1) {
                    QueryBuilder::table('entry_index')->whereIn('entry_id', $ids)->where('field', $m['path_from'][0])->update(['field' => $m['path_to'][0]]);
                }
                if (count($m['path_from']) === 1) {
                    \Kestello\Cms\Content\EntryRelations::renameField($ids, $m['path_from'][0], $m['path_to'][0]);
                }
            }
        }
    }

    /** Move a value from one path to another; inside repeaters, in every item. */
    private static function move(array $data, array $from, array $to): array
    {
        $star = array_search('*', $from, true);
        if ($star !== false && array_slice($from, 0, $star + 1) === array_slice($to, 0, $star + 1)) {
            $head = array_slice($from, 0, $star);
            $items = self::get($data, $head);
            if (!is_array($items) || !array_is_list($items)) {
                return $data;
            }
            foreach ($items as $i => $item) {
                if (is_array($item)) {
                    $items[$i] = self::move($item, array_slice($from, $star + 1), array_slice($to, $star + 1));
                }
            }

            return self::set($data, $head, $items);
        }
        if (!self::exists($data, $from)) {
            return $data;
        }
        $value = self::get($data, $from);

        return self::set(self::remove($data, $from), $to, $value);
    }

    private static function exists(array $data, array $path): bool
    {
        foreach ($path as $key) {
            if (!is_array($data) || !array_key_exists($key, $data)) {
                return false;
            }
            $data = $data[$key];
        }

        return true;
    }

    private static function get(array $data, array $path): mixed
    {
        foreach ($path as $key) {
            if (!is_array($data) || !array_key_exists($key, $data)) {
                return null;
            }
            $data = $data[$key];
        }

        return $data;
    }

    private static function set(array $data, array $path, mixed $value): array
    {
        $key = array_shift($path);
        if ($path === []) {
            $data[$key] = $value;
            return $data;
        }
        $data[$key] = self::set(is_array($data[$key] ?? null) ? $data[$key] : [], $path, $value);

        return $data;
    }

    /**
     * The value at a path changed by $fn; inside repeaters ("*"), in every item.
     *
     * @param array<array-key, mixed> $data
     * @param list<string> $path
     * @param \Closure(mixed): mixed $fn
     * @return array<array-key, mixed>
     */
    private static function mapValues(array $data, array $path, \Closure $fn): array
    {
        $key = array_shift($path);
        if ($key === null) {
            return $data;
        }
        if ($key === '*') {
            return array_is_list($data) ? array_map(static fn($item) => is_array($item) ? self::mapValues($item, $path, $fn) : $item, $data) : $data;
        }
        if (!array_key_exists($key, $data)) {
            return $data;
        }
        if ($path === []) {
            $data[$key] = $fn($data[$key]);
        } elseif (is_array($data[$key])) {
            $data[$key] = self::mapValues($data[$key], $path, $fn);
        }

        return $data;
    }

    private static function remove(array $data, array $path): array
    {
        $key = array_shift($path);
        if ($key === '*') {
            if (!array_is_list($data)) {
                return $data;
            }
            return array_map(static fn($item) => is_array($item) && $path !== [] ? self::remove($item, $path) : $item, $data);
        }
        if ($path === []) {
            unset($data[$key]);
            return $data;
        }
        if (is_array($data[$key] ?? null)) {
            $data[$key] = self::remove($data[$key], $path);
        }

        return $data;
    }

    // ── export ───────────────────────────────────────────────────────────

    /** The same model as PHP for a plugin or theme provider's boot(). */
    public function export(int $id): string
    {
        $m = $this->find($id);
        $d = $m['definition'];
        $q = static fn(mixed $v): string => var_export($v, true);
        if ($m['kind'] === 'taxonomy') {
            return "\$cms->content->taxonomy({$q($m['name'])})\n    ->label({$q($d['singular'])}, {$q($d['plural'])})"
                . ($d['description'] !== '' ? "\n    ->description({$q($d['description'])})" : '')
                . ($d['hierarchical'] ? "\n    ->hierarchical()" : '')
                . "\n    ->for(" . implode(', ', array_map($q, $d['types'])) . ')'
                . ($d['path'] !== null ? "\n    ->path({$q($d['path'])})" : '') . ";\n";
        }
        $lines = ["\$cms->content->type({$q($m['name'])})", "    ->label({$q($d['singular'])}, {$q($d['plural'])})", "    ->icon({$q($d['icon'])})"];
        foreach ([['description', $d['description'] !== ''], ['hierarchical', $d['hierarchical']], ['excerpt', $d['excerpt']]] as [$k, $on]) {
            if ($on) {
                $lines[] = $k === 'description' ? "    ->description({$q($d['description'])})" : "    ->{$k}()";
            }
        }
        if ($d['path'] !== null) {
            $lines[] = "    ->path({$q($d['path'])})" . ($d['archive'] ? '->archive()' : '') . ($d['permalink'] !== '{slug}' ? "->permalink({$q($d['permalink'])})" : '');
        }
        if (!$d['searchable']) {
            $lines[] = '    ->searchable(false)';
        }
        if ($d['revisions'] !== 25) {
            $lines[] = "    ->revisions({$d['revisions']})";
        }
        $lines[] = "    ->menuOrder({$d['menu_order']})";
        if (($d['checklist'] ?? []) !== []) {
            $lines[] = '    ->checklist([';
            foreach ($d['checklist'] as $item) {
                $pairs = array_map(static fn(string $k, mixed $v): string => var_export($k, true) . ' => ' . var_export($v, true), array_keys($item), $item);
                $lines[] = '        [' . implode(', ', $pairs) . '],';
            }
            $lines[] = '    ])';
        }
        $lines[] = '    ->fields([';
        foreach ($d['fields'] as $f) {
            $lines[] = self::exportField($f, 2) . ',';
        }
        $lines[] = '    ]);';

        return "use Kestello\\Cms\\Content\\Field;\n\n" . implode("\n", $lines) . "\n";
    }

    private static function exportField(array $f, int $level): string
    {
        $q = static fn(mixed $v): string => str_replace(["\n", '  '], [' ', ''], var_export($v, true));
        $pad = str_repeat('    ', $level);
        $code = "Field::make({$q($f['name'])}, {$q($f['type'])})->label({$q($f['label'])})";
        if ($f['help'] !== '') {
            $code .= "->help({$q($f['help'])})";
        }
        foreach (['required', 'indexed', 'internal'] as $flag) {
            if ($f[$flag]) {
                $code .= "->{$flag}()";
            }
        }
        if ($f['width'] !== 'full') {
            $code .= $f['width'] === 'sidebar' ? '->sidebar()' : "->width('half')";
        }
        if ($f['default'] !== null) {
            $code .= "->default({$q($f['default'])})";
        }
        if ($f['searchable'] !== null) {
            $code .= '->searchable(' . ($f['searchable'] ? 'true' : 'false') . ')';
        }
        if (($f['translatable'] ?? null) !== null) {
            $code .= '->translatable(' . ($f['translatable'] ? 'true' : 'false') . ')';
        }
        foreach ($f['options'] + (($f['conditions'] ?? null) !== null ? ['conditions' => $f['conditions']] : []) as $k => $v) {
            $code .= "->option({$q($k)}, {$q($v)})";
        }
        if ($f['children'] !== []) {
            $code .= "->children([\n" . implode(",\n", array_map(static fn($c) => self::exportField($c, $level + 1), $f['children'])) . ",\n{$pad}])";
        }

        return $pad . $code;
    }
}
