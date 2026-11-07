<?php

declare(strict_types=1);

namespace Kestello\Cms\Blocks;

use InvalidArgumentException;
use Kestello\Cms\Cms;
use Kestello\Cms\Content\Field;
use Kestello\Cms\Content\FieldTypes;
use Kestello\Cms\I18n\Labels;
use Kestello\Cms\Media\MediaPresenter;
use Kestello\Framework\Core\ExecutionContext;

/**
 * $cms->blocks: every block the site knows, and the one path block trees
 * take: validated when saved (errors for the editor), validated again and
 * prepared on the server when rendered (a tampered row can't inject unknown
 * blocks or props), then rendered by <BlockTree> in the browser or SSR.
 *
 * A tree is a list of nodes:
 *   {"type": "core/heading", "id": "a1b2", "props": {"text": "Hi"}, "children": [],
 *    "visibility": {"auth": "user", "roles": ["editor"], "from": "…", "until": "…"},
 *    "design": {"base": {"padding-top": "lg"}, "phone": {"padding-top": "sm"}}}   (BlockDesign)
 *
 * Plugins name their blocks "<slug>/…"; themes may register "<slug>/…" too.
 * Registering an existing name replaces it only for its owner.
 *
 * @api
 */
final class BlockRegistry
{
    public const int MAX_NODES = 1000;
    public const int MAX_DEPTH = 12;
    public const array DEVICES = ['phone', 'tablet', 'desktop'];

    /** @var array<string, BlockDefinition> */
    private array $blocks = [];

    public readonly Bindings $bindings;
    public readonly BlockDesign $design;

    public function __construct(private ?FieldTypes $types = null)
    {
        $this->bindings = new Bindings();
        $this->design = new BlockDesign();
    }

    public function register(BlockDefinition $block): BlockDefinition
    {
        $owner = ExecutionContext::current();
        $vendor = explode('/', $block->name)[0];
        if (str_starts_with($owner, 'plugin:') || str_starts_with($owner, 'theme:')) {
            $slug = explode(':', $owner, 2)[1];
            if ($vendor !== $slug) {
                throw new InvalidArgumentException("Blocks of {$owner} are named \"{$slug}/…\".");
            }
        } elseif ($vendor !== 'core' && $owner === 'core') {
            // core code registering for someone else is fine (tests, CLI)
        }
        if (isset($this->blocks[$block->name]) && $this->blocks[$block->name]->getOwner() !== $owner) {
            throw new InvalidArgumentException("Block [{$block->name}] is already registered by {$this->blocks[$block->name]->getOwner()}.");
        }

        return $this->blocks[$block->name] = $block->ownedBy($owner);
    }

    public function get(string $name): ?BlockDefinition
    {
        return $this->blocks[$name] ?? null;
    }

    public function has(string $name): bool
    {
        return isset($this->blocks[$name]);
    }

    /** @return array<string, BlockDefinition> */
    public function all(): array
    {
        return $this->blocks;
    }

    private function types(): FieldTypes
    {
        return $this->types ??= Cms::instance()?->content->fields ?? new FieldTypes();
    }

    /** For the editor: every block, its fields and nesting rules. @return list<array<string, mixed>> */
    public function describe(): array
    {
        $out = [];
        foreach ($this->blocks as $b) {
            $domain = Labels::domain($b->getOwner());
            $out[] = [
                'name' => $b->name, 'label' => Labels::text($b->getLabel(), $domain), 'description' => Labels::text($b->getDescription(), $domain), 'category' => $b->getCategory(),
                'icon' => $b->getIcon(), 'iconBy' => ($by = $b->getIconBy()) === null ? null : ['field' => $by['field'], 'icons' => (object) $by['icons']], 'owner' => $b->getOwner(), 'children' => $b->getChildren(), 'parents' => $b->getParents(),
                'example' => (object) $b->getExample(), 'fields' => $this->types()->describe($b->getFields(), $domain),
                'bindable' => array_values(array_map(static fn(Field $f) => $f->name, array_filter($b->getFields(), self::bindable(...)))),
                'supports' => $b->getSupports(), 'starter' => array_map(static fn(array $s) => ['type' => $s['type'], 'props' => (object) $s['props']], $b->getStarter()),
            ];
        }

        return $out;
    }

    // ── validation ──────────────────────────────────────────────────────

    /**
     * Validate a tree for saving.
     *
     * @param list<string>|null $allowed block types allowed at the top (null: any without a parent rule)
     * @return array{0: list<array<string, mixed>>, 1: list<string>} clean tree, errors
     */
    public function validate(mixed $tree, ?array $allowed = null): array
    {
        $errors = [];
        $count = 0;
        $clean = $this->walk(self::upgrade($tree), null, $allowed, '', 1, $count, $errors, strict: true);

        return [$clean, $errors];
    }

    /**
     * @param list<string> $errors
     * @return list<array<string, mixed>>
     */
    private function walk(mixed $nodes, ?BlockDefinition $parent, ?array $allowed, string $trail, int $depth, int &$count, array &$errors, bool $strict, bool $draft = false): array
    {
        if ($nodes === null || $nodes === []) {
            return [];
        }
        if (!is_array($nodes) || !array_is_list($nodes)) {
            $errors[] = ($trail === '' ? 'Blocks' : $trail) . ': must be a list of blocks.';
            return [];
        }
        if ($depth > self::MAX_DEPTH) {
            $errors[] = "{$trail}: blocks can nest at most " . self::MAX_DEPTH . ' levels deep.';
            return [];
        }
        $out = [];
        foreach ($nodes as $i => $node) {
            $label = ($trail === '' ? '' : $trail . ' › ') . 'Block ' . ($i + 1);
            if (++$count > self::MAX_NODES) {
                $errors[] = 'A tree can hold at most ' . self::MAX_NODES . ' blocks.';
                break;
            }
            $type = is_array($node) && is_string($node['type'] ?? null) ? $node['type'] : '';
            $def = $this->blocks[$type] ?? null;
            if ($def === null) {
                $errors[] = "{$label}: unknown block type \"" . mb_substr($type, 0, 60) . '".';
                continue;
            }
            $label .= " ({$def->getLabel()})";
            $placementOk = $parent === null
                ? ($allowed === null ? $def->getParents() === null : in_array($type, $allowed, true))
                : $parent->allowsChild($type);
            if ($placementOk && $def->getParents() !== null && ($parent === null || !in_array($parent->name, $def->getParents(), true))) {
                $placementOk = false;
            }
            if (!$placementOk) {
                $errors[] = "{$label}: can't be placed " . ($parent === null ? 'here' : 'inside ' . $parent->getLabel()) . '.';
                continue;
            }
            $before = count($errors);
            $props = $this->props($def, is_array($node['props'] ?? null) ? $node['props'] : [], $label, $errors, $draft);
            $visibility = $this->visibility($node['visibility'] ?? null, $label, $errors);
            $lock = $this->lock($node['lock'] ?? null, $label, $errors);
            $design = $this->design->clean($node['design'] ?? null, $def->getSupports(), $label, $errors, $strict);
            $children = $node['children'] ?? [];
            if (!$def->acceptsChildren() && $children !== [] && $children !== null) {
                $errors[] = "{$label}: holds no other blocks.";
                $children = [];
            }
            $broken = count($errors) > $before;       // its own problems, before its children add theirs
            $children = $def->acceptsChildren() ? $this->walk($children, $def, null, $label, $depth + 1, $count, $errors, $strict, $draft) : [];
            if (!$strict && $broken) {
                continue;      // rendering: an invalid node is dropped; its siblings, and its parent, stay
            }
            $id = is_string($node['id'] ?? null) && preg_match('/^[a-z0-9]{1,16}$/', $node['id']) === 1 ? $node['id'] : bin2hex(random_bytes(4));
            $clean = ['type' => $type, 'id' => $id, 'props' => (object) $props, 'children' => $children];
            if ($visibility !== null) {
                $clean['visibility'] = $visibility;
            }
            if ($lock !== null) {
                $clean['lock'] = $lock;
            }
            if ($design !== null) {
                $clean['design'] = $design;
            }
            $out[] = $clean;
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $props
     * @param list<string> $errors
     * @return array<string, mixed>
     */
    private function props(BlockDefinition $def, array $props, string $label, array &$errors, bool $draft = false): array
    {
        $fields = $def->getFields();
        $bound = [];
        $skip = [];
        $plain = [];
        foreach ($fields as $field) {
            if (!array_key_exists($field->name, $props)) {
                continue;
            }
            $value = $props[$field->name];
            if (is_array($value) && array_key_exists('bind', $value)) {
                $skip[$field->name] = true;      // one error per prop: no "required" on top of a bad binding
                if (!self::bindable($field)) {
                    $errors[] = "{$label}, {$this->fieldLabel($field)}: can't be bound to data.";
                } elseif (!is_string($value['bind']) || !$this->bindings->known($value['bind'])) {
                    $errors[] = "{$label}, {$this->fieldLabel($field)}: unknown data \"" . (is_string($value['bind']) ? mb_substr($value['bind'], 0, 80) : '?') . '".';
                } else {
                    $bound[$field->name] = ['bind' => $value['bind']];
                }
                continue;
            }
            $plain[$field->name] = $value;
        }
        $unbound = array_values(array_filter($fields, static fn(Field $f) => !isset($skip[$f->name])));
        [$clean, $schemaErrors] = $this->types()->objectSchema($unbound, $draft)->validate($plain);
        $byName = [];
        foreach ($fields as $f) {
            $byName[$f->name] = $f;
        }
        foreach ($schemaErrors as $path => $messages) {
            $name = explode('.', (string) $path)[0];
            $field = $byName[$name] ?? null;
            foreach ($messages as $m) {
                $errors[] = $label . ($field !== null ? ', ' . $this->fieldLabel($field) : '') . ': ' . $m;
            }
        }
        if ($schemaErrors !== []) {
            return [];
        }
        $finishErrors = [];
        $done = $this->types()->finish($unbound, is_array($clean) ? $clean : [], '', $finishErrors);
        foreach ($finishErrors as $name => $messages) {
            foreach ($messages as $m) {
                $errors[] = $label . (isset($byName[$name]) ? ', ' . $this->fieldLabel($byName[$name]) : '') . ': ' . $m;
            }
        }
        // present but empty (after trimming and sanitizing) is as good as missing: "", "  ", "<p></p>"
        if (!$draft) {
            foreach ($unbound as $f) {
                if ($f->isRequired() && !isset($finishErrors[$f->name]) && array_key_exists($f->name, $done) && self::blank($done[$f->name], $f->type)) {
                    $errors[] = "{$label}, {$this->fieldLabel($f)}: This field is required.";
                }
            }
        }

        return array_filter($done + $bound, static fn($v) => $v !== null);
    }

    /** Does a (prepared) tree hold a block of this type anywhere? */
    public static function contains(array $tree, string $type): bool
    {
        foreach ($tree as $node) {
            if (!is_array($node)) {
                continue;
            }
            if (($node['type'] ?? null) === $type || (is_array($node['children'] ?? null) && self::contains($node['children'], $type))) {
                return true;
            }
        }

        return false;
    }

    /** Holds nothing a visitor would see: null, blank text, (rich text) markup with no text or media in it, or a table of empty cells. */
    private static function blank(mixed $value, string $type): bool
    {
        if ($value === null || $value === []) {
            return true;
        }
        if ($type === 'table') {
            return !\Kestello\Cms\Content\Fields\TableField::hasText($value);
        }
        $markup = $type === 'rich_text';
        if (!is_string($value)) {
            return false;
        }
        if ($markup) {
            if (preg_match('/<(img|video|iframe|hr)\b/i', $value) === 1) {
                return false;
            }
            $value = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5);
        }
        return trim($value, " \t\n\r\0\x0B\u{A0}") === '';
    }

    /**
     * A bound value gets the same checks as a typed one: the field's schema,
     * then its normalize step (rich text is sanitized, links must be safe).
     * Entry titles and text fields are plain text written by authors, so
     * without this a binding would be a way around the sanitizer.
     */
    private function cleanBound(BlockDefinition $def, string $name, mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }
        foreach ($def->getFields() as $field) {
            if ($field->name !== $name) {
                continue;
            }
            [$clean, $schemaErrors] = $this->types()->objectSchema([$field], false)->validate([$name => $value]);
            if ($schemaErrors !== [] || !is_array($clean)) {
                return null;
            }
            $errors = [];
            $done = $this->types()->finish([$field], $clean, '', $errors);

            return $errors === [] ? ($done[$name] ?? null) : null;
        }

        return null;
    }

    private function fieldLabel(Field $f): string
    {
        return $f->getLabel() !== '' ? $f->getLabel() : ucfirst(str_replace('_', ' ', $f->name));
    }

    private static function bindable(Field $f): bool
    {
        return (bool) $f->getOption('bindable', in_array($f->type, ['text', 'textarea', 'rich_text', 'url', 'link', 'number', 'date', 'datetime', 'media'], true));
    }

    /**
     * @param list<string> $errors
     * @return array<string, mixed>|null
     */
    /**
     * What the editor may not do to a block: move it, remove it, edit its
     * settings. A guide for people filling in a layout (the builder and the
     * block editor honour it); people who may design can lift it.
     *
     * @param list<string> $errors
     * @return list<string>|null
     */
    private function lock(mixed $v, string $label, array &$errors): ?array
    {
        if ($v === null || $v === []) {
            return null;
        }
        $lock = is_array($v) ? array_values(array_unique(array_filter($v, static fn($x) => in_array($x, ['move', 'remove', 'edit'], true)))) : [];
        if (!is_array($v) || count($lock) !== count($v)) {
            $errors[] = "{$label}: lock lists move, remove or edit.";
            return null;
        }

        return $lock;
    }

    private function visibility(mixed $v, string $label, array &$errors): ?array
    {
        if ($v === null || $v === []) {
            return null;
        }
        if (!is_array($v)) {
            $errors[] = "{$label}: visibility must be an object.";
            return null;
        }
        $out = [];
        if (isset($v['auth'])) {
            in_array($v['auth'], ['guest', 'user'], true) ? $out['auth'] = $v['auth'] : $errors[] = "{$label}: visibility.auth is \"guest\" or \"user\".";
        }
        if (isset($v['roles'])) {
            $roles = is_array($v['roles']) ? array_values(array_filter($v['roles'], static fn($r) => is_string($r) && preg_match('/^[a-z][a-z0-9_-]{0,39}$/', $r) === 1)) : [];
            if (!is_array($v['roles']) || count($roles) !== count($v['roles']) || count($roles) > 20) {
                $errors[] = "{$label}: visibility.roles is a list of role names.";
            } elseif ($roles !== []) {
                $out['roles'] = $roles;
            }
        }
        if (isset($v['hide'])) {
            // screen sizes it is hidden on (CSS does it: the server can't know the device)
            $hide = is_array($v['hide']) ? array_values(array_unique(array_filter($v['hide'], static fn($d) => in_array($d, self::DEVICES, true)))) : [];
            if (!is_array($v['hide']) || count($hide) !== count($v['hide'])) {
                $errors[] = "{$label}: visibility.hide lists phone, tablet or desktop.";
            } elseif (count($hide) === count(self::DEVICES)) {
                $errors[] = "{$label}: a block can't be hidden on every screen size.";
            } elseif ($hide !== []) {
                $out['hide'] = $hide;
            }
        }
        foreach (['from', 'until'] as $k) {
            if (isset($v[$k]) && $v[$k] !== '') {
                $t = is_string($v[$k]) ? strtotime($v[$k]) : false;
                $t === false ? $errors[] = "{$label}: visibility.{$k} is a date and time." : $out[$k] = gmdate('Y-m-d\TH:i:s\Z', $t);
            }
        }

        return $out === [] ? null : $out;
    }

    // ── rendering ───────────────────────────────────────────────────────

    /**
     * Validate (dropping what fails), apply visibility, resolve bindings and
     * media, run each block's prepare(). The result is what <BlockTree> renders:
     * nodes of {type, id, component, props, children}.
     *
     * A draft (the editors' canvas) keeps blocks whose required fields are
     * still empty, so a new heading shows up and can be typed into.
     *
     * @param list<string>|null $allowed
     * @return list<array<string, mixed>>
     */
    public function prepare(mixed $tree, BlockContext $ctx, ?array $allowed = null, bool $draft = false): array
    {
        $errors = [];
        $count = 0;
        $clean = $this->walk(self::upgrade(self::plain($tree)), null, $allowed, '', 1, $count, $errors, strict: false, draft: $draft);
        if ($errors !== []) {
            error_log('[blocks] dropped invalid blocks while rendering: ' . implode(' | ', array_slice($errors, 0, 5)));
        }
        $out = $this->prepareNodes($clean, $ctx);
        $this->resolveMedia($out);

        return $out;
    }

    /**
     * A tree made elsewhere (a pattern core or a theme ships) as this site can
     * use it: blocks it doesn't have are left out, and so are styles naming a
     * size or colour its theme doesn't have, so the result saves as it is.
     * Required fields may stay empty (they are for the editor to fill in).
     *
     * @return list<array<string, mixed>>
     */
    public function fit(mixed $tree): array
    {
        $errors = [];
        $count = 0;

        return $this->walk(self::upgrade(self::plain($tree)), null, null, '', 1, $count, $errors, strict: false, draft: true);
    }

    /**
     * Prepare already validated nodes (block prepare() callbacks call this for
     * the children they render with another context, like query loops).
     *
     * @param list<array<string, mixed>> $nodes
     * @return list<array<string, mixed>>
     */
    public function prepareNodes(array $nodes, BlockContext $ctx): array
    {
        $out = [];
        foreach ($nodes as $node) {
            if (!$this->visible($node['visibility'] ?? null, $ctx)) {
                continue;
            }
            $def = $this->blocks[$node['type']];
            $props = (array) $node['props'];
            foreach ($props as $name => $value) {
                if (is_array($value) && isset($value['bind'])) {
                    $props[$name] = $this->cleanBound($def, (string) $name, $this->bindings->resolve($value['bind'], $ctx));
                }
            }
            $hide = $node['visibility']['hide'] ?? null;
            $design = $node['design'] ?? null;
            $node = ['type' => $node['type'], 'id' => $node['id'], 'props' => $props, 'children' => $node['children']];
            if ($def->getPrepare() !== null) {
                try {
                    $node = ($def->getPrepare())($node, $ctx, $this);
                } catch (\Throwable $e) {
                    error_log("[blocks] {$def->name} failed to prepare: " . $e->getMessage());
                    $node = null;
                }
                if ($node === null) {
                    continue;
                }
            }
            if (!($node['prepared'] ?? false)) {
                $node['children'] = $this->prepareNodes($node['children'], $ctx);
            }
            unset($node['prepared']);
            if ($hide !== null) {
                $node['hide'] = $hide;          // <BlockTree> adds k-hide-{device} classes
            }
            if ($design !== null) {
                $node['design'] = $design;      // <BlockTree> turns it into custom properties (shared/block-design.js)
            }
            $node['component'] = $def->getComponent();
            $node['props'] = (object) $node['props'];
            $out[] = $node;
        }

        return $out;
    }

    private function visible(?array $v, BlockContext $ctx): bool
    {
        if ($v === null) {
            return true;
        }
        $now = time();
        // A cached page must change when a block appears or disappears.
        foreach (['from', 'until'] as $edge) {
            $at = isset($v[$edge]) ? strtotime((string) $v[$edge]) : false;
            if ($at !== false && $at > $now) {
                \Kestello\Cms\Cache\PageTags::until($at);
            }
        }
        if (isset($v['from']) && strtotime($v['from']) > $now) {
            return false;
        }
        if (isset($v['until']) && strtotime($v['until']) <= $now) {
            return false;
        }
        if (($v['auth'] ?? null) === 'guest' && $ctx->viewer !== null) {
            return false;
        }
        if ((($v['auth'] ?? null) === 'user' || isset($v['roles'])) && $ctx->viewer === null) {
            return false;
        }

        return !isset($v['roles']) || in_array((string) ($ctx->viewer['role'] ?? ''), $v['roles'], true);
    }

    /** Media fields (top level) hold ids when saved; pages get the files. @param list<array<string, mixed>> $nodes */
    private function resolveMedia(array &$nodes): void
    {
        $ids = [];
        $visit = function (array $nodes, callable $each) use (&$visit): void {
            foreach ($nodes as $n) {
                $each($n);
                $visit($n['children'] ?? [], $each);
            }
        };
        $visit($nodes, function (array $n) use (&$ids): void {
            foreach ($this->mediaFields($n['type']) as $f) {
                $v = ((array) $n['props'])[$f->name] ?? null;
                foreach ((array) $v as $id) {
                    if (is_int($id) || (is_string($id) && ctype_digit($id))) {
                        $ids[] = (int) $id;
                    }
                }
            }
        });
        $found = MediaPresenter::many($ids);
        $replace = function (array &$nodes) use (&$replace, $found): void {
            foreach ($nodes as &$n) {
                $props = (array) $n['props'];
                foreach ($this->mediaFields($n['type']) as $f) {
                    $v = $props[$f->name] ?? null;
                    $ids = is_int($v) || (is_string($v) && ctype_digit($v)) || (is_array($v) && array_is_list($v) && !is_array($v[0] ?? null));
                    if ($ids) {
                        $props[$f->name] = MediaPresenter::value($props[$f->name], (bool) $f->getOption('multiple', false), $found);
                    }
                }
                $n['props'] = (object) $props;
                $replace($n['children']);
            }
        };
        $replace($nodes);
    }

    /** @return list<Field> */
    private function mediaFields(string $type): array
    {
        return array_values(array_filter(($this->blocks[$type] ?? null)?->getFields() ?? [], static fn(Field $f) => $f->type === 'media'));
    }

    /**
     * Media ids a saved tree uses (so the library knows which entries use a file).
     *
     * @return list<int>
     */
    public function mediaIn(mixed $tree): array
    {
        $ids = [];
        foreach (is_array($tree) ? $tree : [] as $node) {
            $def = is_array($node) ? ($this->blocks[$node['type'] ?? ''] ?? null) : null;
            if ($def === null) {
                continue;
            }
            $props = array_filter((array) ($node['props'] ?? []), static fn($v) => !(is_array($v) && isset($v['bind'])));
            foreach ($this->types()->mediaIn($def->getFields(), $props) as $list) {
                $ids = array_merge($ids, $list);
            }
            $ids = array_merge($ids, $this->mediaIn($node['children'] ?? []));
        }

        return array_values(array_unique(array_map('intval', $ids)));
    }

    /**
     * Entry ids a block tree links to through its blocks' relationship fields
     * (bound props hold no ids of their own).
     *
     * @return list<int>
     */
    public function relationsIn(mixed $tree): array
    {
        $ids = [];
        foreach (is_array($tree) ? $tree : [] as $node) {
            $def = is_array($node) ? ($this->blocks[$node['type'] ?? ''] ?? null) : null;
            if ($def === null) {
                continue;
            }
            $props = array_filter((array) ($node['props'] ?? []), static fn($v) => !(is_array($v) && isset($v['bind'])));
            foreach ($this->types()->relationsIn($def->getFields(), $props) as $list) {
                $ids = array_merge($ids, $list);
            }
            $ids = array_merge($ids, $this->relationsIn($node['children'] ?? []));
        }

        return array_values(array_unique(array_map('intval', $ids)));
    }

    /** Components a prepared tree renders (for modulepreload). @return list<string> */
    public static function components(array $prepared): array
    {
        $out = [];
        foreach ($prepared as $n) {
            $out[] = (string) $n['component'];
            $out = array_merge($out, self::components($n['children'] ?? []));
        }

        return array_values(array_unique($out));
    }

    /**
     * A field changed from rich text to blocks in the content model builder still holds HTML in its
     * entries: that HTML becomes one text block.
     */
    private static function upgrade(mixed $tree): mixed
    {
        return is_string($tree) && trim($tree) !== ''
            ? [['type' => 'core/text', 'props' => ['text' => $tree], 'children' => []]]
            : ($tree === '' ? [] : $tree);
    }

    /** Stored trees may hold stdClass props (json_decode without assoc): make them arrays. */
    private static function plain(mixed $tree): mixed
    {
        return is_object($tree) || is_array($tree) ? json_decode((string) json_encode($tree), true) : $tree;
    }
}
