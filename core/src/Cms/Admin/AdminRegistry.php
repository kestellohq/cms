<?php

declare(strict_types=1);

namespace Kestello\Cms\Admin;

use Closure;
use InvalidArgumentException;
use Kestello\Cms\Api\Schema;
use Kestello\Cms\I18n\Labels;
use Kestello\Contracts\Auth\Principal;
use Kestello\Framework\Core\ExecutionContext;

/**
 * $cms->admin: pages and menu of the admin app.
 *
 *   $cms->admin->page('reports', '@acme-shop/admin/Reports', title: 'Sales reports',
 *       capability: 'acme-shop.reports', menu: ['label' => 'Reports', 'icon' => 'chart-line-up', 'group' => 'plugins']);
 *   // → GET /admin/p/acme-shop/reports, rendered in the admin layout
 *
 *   $cms->admin->group('shop', 'Shop', order: 30);
 *   $cms->admin->link('acme-shop.docs', 'Help', 'https://docs.acme.test', group: 'shop');
 *
 * Every admin page is its own server route: the capability is checked on
 * the server before the page (and its JavaScript) is sent, and the menu a
 * user sees only lists what they can open. Page data comes from /api/v1.
 *
 * Core pages live at /admin/<path>; plugin pages at /admin/p/<slug>/<path>
 * with components named "@<slug>/…".
 *
 * Extension points (v1 catalogue, see POINTS; capability-filtered before
 * anything reaches the browser):
 *
 *   $cms->admin->widget('dashboard', '@acme/admin/SalesWidget', title: 'Sales today', size: 'half');
 *   $cms->admin->slot('users.edit.panels', '@acme/admin/LoyaltyPanel', capability: 'acme.loyalty');
 *   $cms->admin->column('users.list', 'acme_tier', label: 'Tier', component: '@acme/admin/TierCell');
 *   $cms->admin->column('entries.list', 'acme_stock', label: 'Stock', when: ['type' => 'acme-product']);
 *   $cms->admin->settings('general', 'Acme', Schema::object([...]), secrets: ['api_key']);
 *   $cms->admin->replace('users.avatar', '@acme/admin/Avatar');
 *
 * Extension components are ordinary SFCs under resources/js/pages/admin/
 * (named "@<slug>/admin/…"), loaded on demand by the admin.
 *
 * @api
 */
final class AdminRegistry
{
    /**
     * Where extensions render. Slots receive `context` (documented per
     * point) and may be filtered with `when` against it.
     */
    public const array POINTS = [
        'widgets' => ['dashboard'],
        'slots' => [
            'users.edit.panels', // Person screen, below the details. context: { user }
            'profile.tabs',      // Your profile, as extra tabs (label required). context: { user }
        ],
        'columns' => [
            'users.list',   // People table. Cells receive { row, value }
            'entries.list', // Content lists, one per type. context: { type }; narrow with when: ['type' => [...]]
        ],
    ];

    /** Core components a plugin may swap for its own (same props/emits). */
    public const array REPLACEABLE = ['users.avatar', 'media.picker', 'editor.richtext', 'editor.code', 'auth.login-form'];

    private const array WIDGET_SIZES = ['quarter', 'half', 'full'];

    /** @var list<array{kind: string, point: string, component: string, capability: string, order: int, owner: string, title: string, size: string, label: string, when: array<string, list<scalar>>, key: string, align: string}> */
    private array $extensions = [];

    /** @var array<string, array{component: string, owner: string}> */
    private array $replacements = [];

    /** @var array<string, array{id: string, label: string, description: string, schema: Schema, labels: array<string, string>, secrets: list<string>, defaults: array<string, mixed>, capability: string, component: ?string, order: int, owner: string, prefix: string}> */
    private array $settingsTabs = [];
    /** @var array<string, array{label: string, order: int, owner: string}> */
    private array $groups = [];

    /** @var array<string, array{path: string, component: string, title: string, capability: string, owner: string}> path => page */
    private array $pages = [];

    /** @var array<string, array{id: string, label: string, href: string, icon: string, group: string, order: int, capability: string, owner: string, match: string}> */
    private array $menu = [];

    /** @var (Closure(array{path: string, component: string, title: string, capability: string, owner: string}): void)|null */
    private ?Closure $router = null;

    /**
     * @internal AdminModule: register a route for each page (now and later).
     * @param Closure(array{path: string, component: string, title: string, capability: string, owner: string}): void $router
     */
    public function setRouter(Closure $router): void
    {
        $this->router = $router;
        foreach ($this->pages as $page) {
            ExecutionContext::run($page['owner'], static fn() => $router($page));
        }
    }

    public function __construct()
    {
        $this->groups = [
            'main' => ['label' => '', 'order' => 0, 'owner' => 'core'],
            'content' => ['label' => 'Content', 'order' => 10, 'owner' => 'core'],
            'people' => ['label' => 'People', 'order' => 20, 'owner' => 'core'],
            'appearance' => ['label' => 'Appearance', 'order' => 30, 'owner' => 'core'],
            // the whole site: what an owner changes or runs now and then
            'site' => ['label' => 'Site', 'order' => 40, 'owner' => 'core'],
            'plugins' => ['label' => 'Plugins', 'order' => 80, 'owner' => 'core'],
            // how it runs: checks, logs and tools for whoever looks after it
            'system' => ['label' => 'System', 'order' => 90, 'owner' => 'core'],
        ];
    }

    public function group(string $id, string $label, int $order = 50): void
    {
        self::assertId($id);
        $owner = ExecutionContext::current();
        if (isset($this->groups[$id]) && $this->groups[$id]['owner'] !== $owner) {
            throw new InvalidArgumentException("Admin menu group \"{$id}\" already exists.");
        }
        $this->groups[$id] = ['label' => $label, 'order' => $order, 'owner' => $owner];
    }

    /** A record id in a page address: a positive whole number. */
    public const string ID = '[1-9][0-9]*';

    /** What a page parameter may be when the page sets no rule: a plain name. */
    public const string SLUG = '[A-Za-z0-9][A-Za-z0-9_.-]*';

    /**
     * Register an admin page (and, with $menu, its menu entry).
     *
     * Each {parameter} in the path matches a plain name (SLUG) unless $where
     * gives it a pattern, for example ['id' => AdminRegistry::ID]. Addresses
     * that don't match answer 404 instead of reaching the page.
     *
     * @param array{label?: string, icon?: string, group?: string, order?: int}|null $menu
     * @param array<string, string> $where parameter name => regular expression (no delimiters or anchors)
     * @return string the page URL
     */
    public function page(string $path, string $component, string $title, string $capability = 'admin.access', ?array $menu = null, array $where = []): string
    {
        $owner = ExecutionContext::current();
        $path = trim($path, '/');
        if ($path !== '' && preg_match('#^[a-z0-9][a-z0-9_-]*(?:/(?:[a-z0-9][a-z0-9_-]*|\{[a-z][a-z0-9_]*\}))*$#', $path) !== 1) {
            throw new InvalidArgumentException("Invalid admin page path \"{$path}\".");
        }
        if ($owner === 'core') {
            if (!str_starts_with($component, '@core/')) {
                throw new InvalidArgumentException('Core admin components are named "@core/…".');
            }
            $url = '/admin' . ($path === '' ? '' : '/' . $path);
        } else {
            $slug = self::slug($owner);
            if (!str_starts_with($component, "@{$slug}/")) {
                throw new InvalidArgumentException("Admin pages of {$owner} must use components named \"@{$slug}/…\".");
            }
            $url = "/admin/p/{$slug}" . ($path === '' ? '' : '/' . $path);
        }
        if (isset($this->pages[$url])) {
            throw new InvalidArgumentException("The admin page {$url} is already registered.");
        }
        $this->pages[$url] = ['path' => $url, 'component' => $component, 'title' => $title, 'capability' => $capability, 'owner' => $owner, 'where' => self::rules($url, $where)];
        if ($this->router !== null) {
            ($this->router)($this->pages[$url]);
        }

        if ($menu !== null) {
            if (str_contains($url, '{')) {
                throw new InvalidArgumentException('Pages with parameters cannot be menu entries.');
            }
            $this->link(
                ($owner === 'core' ? 'core' : self::slug($owner)) . '.' . ($path === '' ? 'home' : str_replace('/', '.', $path)),
                $menu['label'] ?? $title,
                $url,
                icon: $menu['icon'] ?? 'circle',
                group: $menu['group'] ?? ($owner === 'core' ? 'main' : 'plugins'),
                order: $menu['order'] ?? 50,
                capability: $capability,
            );
        }

        return $url;
    }

    /** A menu entry pointing anywhere (a page, a legacy screen, external docs). */
    public function link(string $id, string $label, string $href, string $icon = 'circle', string $group = 'plugins', int $order = 50, string $capability = 'admin.access', ?string $match = null): void
    {
        $owner = ExecutionContext::current();
        if (preg_match('/^[a-z0-9][a-z0-9_.-]{0,99}$/', $id) !== 1) {
            throw new InvalidArgumentException("Invalid admin menu id \"{$id}\".");
        }
        if ($owner !== 'core' && !str_starts_with($id, self::slug($owner) . '.')) {
            throw new InvalidArgumentException("Admin menu ids of {$owner} must start with \"" . self::slug($owner) . '.".');
        }
        if (!str_starts_with($href, '/') && !str_starts_with($href, 'https://')) {
            throw new InvalidArgumentException('Admin menu links must be site paths or https URLs.');
        }
        if (preg_match('/^[a-z0-9-]{1,40}$/', $icon) !== 1) {
            throw new InvalidArgumentException("Invalid icon name \"{$icon}\".");
        }
        if (!isset($this->groups[$group])) {
            throw new InvalidArgumentException("Unknown admin menu group \"{$group}\".");
        }
        if (isset($this->menu[$id]) && $this->menu[$id]['owner'] !== $owner) {
            throw new InvalidArgumentException("Admin menu entry \"{$id}\" already exists.");
        }
        $this->menu[$id] = ['id' => $id, 'label' => $label, 'href' => $href, 'icon' => $icon, 'group' => $group, 'order' => $order, 'capability' => $capability, 'owner' => $owner, 'match' => $match ?? $href];
    }

    /** A dashboard widget. Sizes: quarter, half or full width. */
    public function widget(string $area, string $component, string $title, string $size = 'half', string $capability = 'admin.access', int $order = 50): void
    {
        if (!in_array($size, self::WIDGET_SIZES, true)) {
            throw new InvalidArgumentException('Widget size must be one of: ' . implode(', ', self::WIDGET_SIZES) . '.');
        }
        $this->add('widgets', $area, $component, $capability, $order, ['title' => $title, 'size' => $size]);
    }

    /**
     * A component rendered at a named point of a core screen.
     *
     * @param array<string, scalar|list<scalar>> $when only where context[key] is one of these values
     * @param string|null $label tab label, for points that render tabs
     */
    public function slot(string $point, string $component, string $capability = 'admin.access', array $when = [], int $order = 50, ?string $label = null): void
    {
        $filter = self::when($when);
        if ($point === 'profile.tabs' && ($label === null || trim($label) === '')) {
            throw new InvalidArgumentException('Profile tabs need a label.');
        }
        $this->add('slots', $point, $component, $capability, $order, ['when' => $filter, 'label' => (string) $label]);
    }

    /**
     * A column in a core list. Without a component the cell shows row[$key]
     * as text; add the field to the resource with $cms->api->extend().
     */
    public function column(string $list, string $key, string $label, ?string $component = null, string $capability = 'admin.access', int $order = 50, string $align = 'start', array $when = []): void
    {
        $filter = self::when($when);
        if (preg_match('/^[a-z][a-z0-9_]{0,63}$/', $key) !== 1) {
            throw new InvalidArgumentException("Invalid column key \"{$key}\".");
        }
        if (!in_array($align, ['start', 'end'], true)) {
            throw new InvalidArgumentException('Column align must be start or end.');
        }
        foreach ($this->extensions as $e) {
            if ($e['kind'] === 'columns' && $e['point'] === $list && $e['key'] === $key) {
                throw new InvalidArgumentException("The column \"{$key}\" of {$list} is already added by {$e['owner']}.");
            }
        }
        $this->add('columns', $list, $component, $capability, $order, ['key' => $key, 'label' => $label, 'align' => $align, 'when' => $filter]);
    }

    /**
     * @param array<string, scalar|list<scalar>> $when context field => allowed values
     * @return array<string, list<scalar>>
     */
    private static function when(array $when): array
    {
        $filter = [];
        foreach ($when as $key => $values) {
            if (!is_string($key) || preg_match('/^[a-z][a-z0-9_]{0,39}$/', $key) !== 1) {
                throw new InvalidArgumentException('"when" keys are context field names.');
            }
            $filter[$key] = array_values(array_map(static fn($v) => is_scalar($v) ? $v : throw new InvalidArgumentException('"when" values must be scalars.'), (array) $values));
        }

        return $filter;
    }

    /**
     * A settings tab for this plugin. With a schema, the admin renders the
     * form and the API stores each field as "plugin.<slug>.<id>.<field>"
     * (read it with $this->setting('<id>.<field>'); secrets are sealed with
     * the app key, read them with $this->secret('<id>.<field>')). With only
     * a component, the tab renders that component instead.
     *
     * @param array<string, string> $labels field => label
     * @param list<string> $secrets fields that are never sent back to the browser
     * @param array<string, mixed> $defaults field => value shown until saved
     */
    public function settings(
        string $id,
        string $label,
        ?Schema $schema = null,
        array $labels = [],
        array $secrets = [],
        array $defaults = [],
        string $description = '',
        string $capability = 'settings.manage',
        ?string $component = null,
        int $order = 50,
    ): void {
        $owner = ExecutionContext::current();
        if ($owner === 'core' || !str_starts_with($owner, 'plugin:')) {
            throw new InvalidArgumentException('Settings tabs are for plugins; core groups live in SiteSettings.');
        }
        self::assertId($id);
        $slug = self::slug($owner);
        $group = "{$slug}.{$id}";
        if (isset($this->settingsTabs[$group])) {
            throw new InvalidArgumentException("The settings tab \"{$group}\" is already registered.");
        }
        if ($schema === null && $component === null) {
            throw new InvalidArgumentException('A settings tab needs a schema or a component.');
        }
        if ($component !== null) {
            $this->assertComponent($component, $owner);
        }
        $properties = $schema?->toOpenApi()['properties'] ?? [];
        foreach ($properties as $field => $_) {
            if (preg_match('/^[a-z][a-z0-9_]{0,63}$/', (string) $field) !== 1) {
                throw new InvalidArgumentException("Invalid settings field \"{$field}\" (lowercase letters, digits and underscores).");
            }
        }
        foreach ($secrets as $secret) {
            if (!isset($properties[$secret])) {
                throw new InvalidArgumentException("The secret \"{$secret}\" is not a field of the schema.");
            }
        }

        $this->settingsTabs[$group] = [
            'id' => $group,
            'label' => $label,
            'description' => $description,
            'schema' => $schema ?? Schema::object([]),
            'labels' => $labels,
            'secrets' => array_values($secrets),
            'defaults' => $defaults,
            'capability' => $capability,
            'component' => $component,
            'order' => $order,
            'owner' => $owner,
            'prefix' => "plugin.{$slug}.{$id}.",
        ];
    }

    /**
     * Swap a core component (REPLACEABLE) for this plugin's. The original
     * stays available to the replacement as the `core` prop, so it can wrap it.
     * Two plugins replacing the same component is a conflict.
     */
    public function replace(string $name, string $component): void
    {
        $owner = ExecutionContext::current();
        if (!in_array($name, self::REPLACEABLE, true)) {
            throw new InvalidArgumentException("\"{$name}\" is not replaceable. Replaceable: " . implode(', ', self::REPLACEABLE) . '.');
        }
        $this->assertComponent($component, $owner);
        if (isset($this->replacements[$name]) && $this->replacements[$name]['owner'] !== $owner) {
            throw new InvalidArgumentException("\"{$name}\" is already replaced by {$this->replacements[$name]['owner']}; two plugins cannot replace the same component.");
        }
        $this->replacements[$name] = ['component' => $component, 'owner' => $owner];
    }

    /**
     * Plugin settings tabs, in order.
     *
     * @return list<array{id: string, label: string, description: string, schema: Schema, labels: array<string, string>, secrets: list<string>, defaults: array<string, mixed>, capability: string, component: ?string, order: int, owner: string, prefix: string}>
     */
    public function settingsTabs(): array
    {
        $tabs = array_values($this->settingsTabs);
        usort($tabs, static fn(array $a, array $b) => [$a['order'], $a['label']] <=> [$b['order'], $b['label']]);

        return $tabs;
    }

    /**
     * What the admin may load for this principal (sent with every admin page).
     *
     * @return array{widgets: array<string, list<array<string, mixed>>>, slots: array<string, list<array<string, mixed>>>, columns: array<string, list<array<string, mixed>>>, replace: array<string, string>}
     */
    public function extensionsFor(Principal $principal): array
    {
        $out = ['widgets' => [], 'slots' => [], 'columns' => [], 'replace' => []];
        $visible = array_filter($this->extensions, static fn(array $e) => $principal->can($e['capability']));
        usort($visible, static fn(array $a, array $b) => [$a['order'], $a['owner'], $a['component']] <=> [$b['order'], $b['owner'], $b['component']]);

        foreach ($visible as $e) {
            $base = ['id' => self::slug($e['owner']) . ':' . ($e['key'] !== '' ? $e['key'] : $e['component']), 'component' => $e['component'], 'owner' => self::slug($e['owner'])];
            $domain = Labels::domain($e['owner']);
            $out[$e['kind']][$e['point']][] = match ($e['kind']) {
                'widgets' => $base + ['title' => Labels::text($e['title'], $domain), 'size' => $e['size']],
                'slots' => $base + ['when' => (object) $e['when'], 'label' => Labels::text($e['label'], $domain)],
                'columns' => $base + ['key' => $e['key'], 'label' => Labels::text($e['label'], $domain), 'align' => $e['align'], 'when' => (object) $e['when']],
            };
        }
        foreach ($this->replacements as $name => $r) {
            $out['replace'][$name] = $r['component'];
        }

        return $out;
    }

    /** @param array<string, mixed> $extra */
    private function add(string $kind, string $point, ?string $component, string $capability, int $order, array $extra): void
    {
        $owner = ExecutionContext::current();
        if (!in_array($point, self::POINTS[$kind], true)) {
            throw new InvalidArgumentException("Unknown {$kind} point \"{$point}\". Known: " . implode(', ', self::POINTS[$kind]) . '.');
        }
        if ($component !== null) {
            $this->assertComponent($component, $owner);
        }
        if (preg_match('/^(\*|[a-z0-9][a-z0-9_.:-]{0,99})$/', $capability) !== 1) {
            throw new InvalidArgumentException("Invalid capability \"{$capability}\".");
        }
        $this->extensions[] = [
            'kind' => $kind, 'point' => $point, 'component' => (string) $component, 'capability' => $capability, 'order' => $order, 'owner' => $owner,
            'title' => '', 'size' => '', 'label' => '', 'when' => [], 'key' => '', 'align' => 'start', ...$extra,
        ];
    }

    private function assertComponent(string $component, string $owner): void
    {
        // Extension components live under pages/admin/ so they wear the admin skin.
        $prefix = ($owner === 'core' ? '@core/' : '@' . self::slug($owner) . '/') . 'admin/';
        if (!str_starts_with($component, $prefix) || preg_match('#^@[a-z0-9-]+(/[A-Za-z0-9_-]+)+$#', $component) !== 1) {
            throw new InvalidArgumentException("Components of {$owner} are named \"{$prefix}…\" (got \"{$component}\").");
        }
    }

    /**
     * The pattern for each parameter of a page path.
     *
     * @param array<string, string> $where
     * @return array<string, string>
     */
    private static function rules(string $url, array $where): array
    {
        preg_match_all('#\{([a-z][a-z0-9_]*)\}#', $url, $m);
        $unknown = array_diff(array_keys($where), $m[1]);
        if ($unknown !== []) {
            throw new InvalidArgumentException("The admin page {$url} has no parameter \"" . implode('", "', $unknown) . '".');
        }
        $rules = [];
        foreach ($m[1] as $name) {
            $rule = $where[$name] ?? self::SLUG;
            if (str_contains($rule, '#') || @preg_match('#^(?:' . $rule . ')$#', '') === false) {
                throw new InvalidArgumentException("The rule for {{$name}} on {$url} is not a valid pattern.");
            }
            $rules[$name] = $rule;
        }

        return $rules;
    }

    /** @return array<string, array{path: string, component: string, title: string, capability: string, owner: string}> */
    public function pages(): array
    {
        return $this->pages;
    }

    /**
     * The menu a principal may see: groups in order, empty groups dropped.
     *
     * @return list<array{id: string, label: string, items: list<array{id: string, label: string, href: string, icon: string, match: string}>}>
     */
    public function menuFor(Principal $principal): array
    {
        $groups = $this->groups;
        uasort($groups, static fn(array $a, array $b) => $a['order'] <=> $b['order']);
        $items = array_filter($this->menu, static fn(array $m) => $principal->can($m['capability']));
        usort($items, static fn(array $a, array $b) => [$a['order'], $a['label']] <=> [$b['order'], $b['label']]);

        $out = [];
        foreach ($groups as $id => $group) {
            $entries = array_values(array_map(
                static fn(array $m) => ['id' => $m['id'], 'label' => Labels::text($m['label'], Labels::domain($m['owner'])), 'href' => $m['href'], 'icon' => $m['icon'], 'match' => $m['match']],
                array_filter($items, static fn(array $m) => $m['group'] === $id),
            ));
            if ($entries !== []) {
                $out[] = ['id' => $id, 'label' => Labels::text($group['label'], Labels::domain($group['owner'])), 'items' => $entries];
            }
        }

        return $out;
    }

    private static function slug(string $owner): string
    {
        return explode(':', $owner, 2)[1] ?? $owner;
    }

    private static function assertId(string $id): void
    {
        if (preg_match('/^[a-z0-9][a-z0-9_-]{0,49}$/', $id) !== 1) {
            throw new InvalidArgumentException("Invalid admin id \"{$id}\".");
        }
    }
}
