<?php

declare(strict_types=1);

namespace Kestello\Cms\Content;

use InvalidArgumentException;
use Kestello\Cms\Auth\Roles;
use Kestello\Framework\Core\ExecutionContext;
use LogicException;

/**
 * Content types and field types ($cms->content).
 *
 * @api
 */
final class ContentRegistry
{
    public readonly FieldTypes $fields;

    /** @var array<string, ContentType> */
    private array $types = [];

    /** @var array<string, Taxonomy> */
    private array $taxonomies = [];

    private bool $finalized = false;

    public function __construct()
    {
        $this->fields = new FieldTypes();
    }

    /**
     * Declare a content type. Plugin types are named after the plugin
     * ("<slug>" or "<slug>-…").
     */
    public function type(string $name): ContentType
    {
        $owner = ExecutionContext::current();
        self::assertOwnName($name, 'Content types');
        if ($this->finalized) {
            throw new LogicException('Content types are declared while packages boot.');
        }
        if (isset($this->types[$name])) {
            throw new LogicException("Content type [{$name}] is already declared by {$this->types[$name]->owner}.");
        }

        return $this->types[$name] = new ContentType($name, $owner);
    }

    /**
     * Declare a taxonomy. Plugin taxonomies are named after the plugin
     * ("<slug>" or "<slug>-…"), like content types.
     */
    public function taxonomy(string $name): Taxonomy
    {
        $owner = ExecutionContext::current();
        self::assertOwnName($name, 'Taxonomies');
        if ($this->finalized) {
            throw new LogicException('Taxonomies are declared while packages boot.');
        }
        if (isset($this->taxonomies[$name])) {
            throw new LogicException("Taxonomy [{$name}] is already declared by {$this->taxonomies[$name]->owner}.");
        }

        return $this->taxonomies[$name] = new Taxonomy($name, $owner);
    }

    /**
     * Packages name their types and taxonomies after themselves ("<slug>" or
     * "<slug>-…"); core may use any name.
     *
     * @internal
     */
    public static function assertOwnName(string $name, string $what): void
    {
        $owner = ExecutionContext::current();
        if (str_starts_with($owner, 'plugin:') || str_starts_with($owner, 'theme:')) {
            $slug = explode(':', $owner, 2)[1];
            if ($name !== $slug && !str_starts_with($name, $slug . '-')) {
                throw new InvalidArgumentException(ucfirst($what) . " of {$owner} must be named \"{$slug}\" or \"{$slug}-…\".");
            }
        }
    }

    public function getTaxonomy(string $name): ?Taxonomy
    {
        return $this->taxonomies[$name] ?? null;
    }

    /** @return array<string, Taxonomy> in menu order */
    public function taxonomies(): array
    {
        $out = $this->taxonomies;
        uasort($out, static fn(Taxonomy $a, Taxonomy $b) => [$a->getMenuOrder(), $a->plural()] <=> [$b->getMenuOrder(), $b->plural()]);

        return $out;
    }

    /** @return array<string, Taxonomy> taxonomies whose terms entries of $type can have */
    public function taxonomiesFor(string $type): array
    {
        return array_filter($this->taxonomies(), static fn(Taxonomy $t) => $t->appliesTo($type));
    }

    public function get(string $name): ?ContentType
    {
        return $this->types[$name] ?? null;
    }

    public function has(string $name): bool
    {
        return isset($this->types[$name]);
    }

    /** @return array<string, ContentType> in menu order */
    public function types(): array
    {
        $types = $this->types;
        uasort($types, static fn(ContentType $a, ContentType $b) => [$a->getMenuOrder(), $a->plural()] <=> [$b->getMenuOrder(), $b->plural()]);

        return $types;
    }

    /**
     * @internal Kernel, after packages booted: check fields and paths and
     * declare each type's capabilities (as core).
     */
    public function finalize(Roles $roles): void
    {
        if ($this->finalized) {
            return;
        }
        $this->finalized = true;
        $this->validate();
        ExecutionContext::run('core', function () use ($roles): void {
            foreach ($this->taxonomies as $tax) {
                $roles->capability($tax->capability('manage'), 'Manage ' . strtolower($tax->plural()));
                $roles->capability($tax->capability('assign'), 'Assign ' . strtolower($tax->plural()) . ' to entries');
                foreach ($tax->grants() as $role => $actions) {
                    if ($roles->exists($role)) {
                        $roles->grant($role, ...array_map($tax->capability(...), $actions));
                    }
                }
            }
            foreach ($this->types as $type) {
                foreach (ContentType::ACTIONS as $action) {
                    $roles->capability($type->capability($action), ucfirst(str_replace('_', ' ', $action)) . ' ' . strtolower($type->plural()));
                }
                foreach ($type->grants() as $role => $actions) {
                    if ($roles->exists($role)) {
                        $roles->grant($role, 'content.access', 'admin.access', ...array_map($type->capability(...), $actions));
                    }
                }
            }
        });
    }

    /**
     * A plugin being activated boots into a request whose types are already
     * final. Its declarations are checked here as finalize() would check
     * them (fields, addresses, taxonomy targets), then dropped: the next
     * request declares them for real. A plugin whose types clash is refused
     * instead of breaking every request after it.
     *
     * @internal ExtensionManager::activatePlugin()
     */
    public function preflight(\Closure $boot): void
    {
        if (!$this->finalized) {
            $boot();
            return;
        }
        [$types, $taxonomies] = [$this->types, $this->taxonomies];
        $this->finalized = false;
        try {
            $boot();
            $this->validate();
        } finally {
            [$this->types, $this->taxonomies, $this->finalized] = [$types, $taxonomies, true];
        }
    }

    private function validate(): void
    {
        $paths = [];
        foreach ($this->types as $type) {
            foreach ($type->getFields() as $field) {
                $this->assertField($type, $field);
            }
            if (($path = $type->getPath()) !== null) {
                if (isset($paths[$path])) {
                    throw new LogicException("Content types [{$paths[$path]}] and [{$type->name}] share the path \"{$path}\".");
                }
                $paths[$path] = $type->name;
            }
        }
        foreach ($this->taxonomies as $tax) {
            foreach ($tax->getTypes() as $typeName) {
                if (!isset($this->types[$typeName])) {
                    throw new LogicException("Taxonomy [{$tax->name}] is for unknown content type [{$typeName}].");
                }
            }
            if (($path = $tax->getPath()) !== null) {
                if (isset($paths[$path])) {
                    throw new LogicException("[{$paths[$path]}] and taxonomy [{$tax->name}] share the path \"{$path}\".");
                }
                $paths[$path] = $tax->name;
            }
        }
    }

    private function assertField(ContentType $type, Field $field): void
    {
        if (!$this->fields->has($field->type)) {
            throw new LogicException("Content type [{$type->name}] field [{$field->name}] uses unknown field type [{$field->type}].");
        }
        $fieldType = $this->fields->get($field->type);
        if ($field->isIndexed() && !$fieldType->index(self::sample($field), $field)) {
            throw new LogicException("Field [{$type->name}.{$field->name}] ({$field->type}) cannot be indexed.");
        }
        $fieldType->schema($field, $this->fields); // throws on bad options (e.g. a select without choices)
        foreach ($field->getChildren() as $child) {
            $this->assertField($type, $child);
        }
    }

    private static function sample(Field $field): mixed
    {
        return match ($field->type) {
            'number', 'entry', 'term', 'user' => 1,
            'boolean' => true,
            'date', 'datetime' => '2026-01-01',
            'select' => (string) array_key_first((array) $field->getOption('choices', ['x' => 'x'])),
            'multi_select' => [(string) array_key_first((array) $field->getOption('choices', ['x' => 'x']))],
            'rich_text', 'group', 'repeater' => null,
            default => 'x',
        };
    }
}
