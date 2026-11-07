<?php

declare(strict_types=1);

namespace Kestello\Cms\Blocks;

use Closure;
use InvalidArgumentException;
use Kestello\Cms\Content\Field;

/**
 * A block: a Vue component plus what the server needs to validate it,
 * prepare it for rendering and describe it to the editor.
 *
 *   $cms->blocks->register(BlockDefinition::make('acme/pricing')
 *       ->label('Pricing table')->category('widgets')->icon('table')
 *       ->component('@acme/blocks/Pricing')
 *       ->fields([Field::text('title')->required(), Field::repeater('plans', [...])->max(6)])
 *       ->example(['title' => 'Plans']));
 *
 * Props are content fields, so they validate, sanitize (rich text), track
 * media usage and get editor inputs exactly like entry fields; plugin field
 * types work too. Text-like props can be bound to data instead of typed
 * ({"bind": "entry.title"}), resolved on the server when the page renders.
 *
 * @api
 */
final class BlockDefinition
{
    public const array CATEGORIES = ['text', 'media', 'layout', 'data', 'widgets'];

    private string $label = '';
    private string $description = '';
    private string $category = 'widgets';
    private string $icon = 'square';
    /** @var array{field: string, icons: array<string, string>}|null an icon per value of one of its fields */
    private ?array $iconBy = null;
    private ?string $component = null;
    /** @var list<Field>|Closure(): list<Field> */
    private array|Closure $fields = [];
    /** @var bool|list<string> true: any block, false: none, list: only these types */
    private bool|array $children = false;
    /** @var list<string>|null only inside these block types */
    private ?array $parents = null;
    /** @var array<string, mixed> */
    private array $example = [];
    private ?Closure $prepare = null;
    private string $owner = 'core';
    /** @var list<string> design groups the block offers (BlockDesign::GROUPS) */
    private array $supports = [];
    /** @var list<array{type: string, props: array<string, mixed>}> blocks a new one starts with */
    private array $starter = [];

    private function __construct(public readonly string $name)
    {
        if (preg_match('#^[a-z][a-z0-9-]{0,39}/[a-z][a-z0-9-]{0,59}$#', $name) !== 1) {
            throw new InvalidArgumentException("Block names are \"vendor/name\" in lowercase: \"{$name}\".");
        }
    }

    public static function make(string $name): self
    {
        return new self($name);
    }

    public function label(string $label): self
    {
        $this->label = $label;
        return $this;
    }
    public function description(string $text): self
    {
        $this->description = $text;
        return $this;
    }
    public function icon(string $icon): self
    {
        $this->icon = $icon;
        return $this;
    }

    /**
     * Each block shows the icon for the value of one of its fields (a heading's level), and
     * icon() where it has none; the library keeps icon(). Names as for icon().
     * @param array<string|int, string> $icons value => icon
     */
    public function iconBy(string $field, array $icons): self
    {
        if (preg_match('/^[a-z][a-z0-9_]{0,63}$/', $field) !== 1) {
            throw new InvalidArgumentException("Block \"{$this->name}\": iconBy() needs a field name, not \"{$field}\".");
        }
        $clean = [];
        foreach ($icons as $value => $icon) {
            if (!is_string($icon) || preg_match('/^[a-z0-9-]{1,64}$/', $icon) !== 1) {
                throw new InvalidArgumentException("Block \"{$this->name}\": iconBy() takes icon names such as \"text-h-two\".");
            }
            $clean[(string) $value] = $icon;
        }
        $this->iconBy = ['field' => $field, 'icons' => $clean];
        return $this;
    }

    public function category(string $category): self
    {
        if (!in_array($category, self::CATEGORIES, true)) {
            throw new InvalidArgumentException('Block categories: ' . implode(', ', self::CATEGORIES) . '.');
        }
        $this->category = $category;

        return $this;
    }

    /** Registered Vue component (default: "@<vendor>/blocks/<Name>"; core: "@core/blocks/<Name>"). */
    public function component(string $component): self
    {
        $this->component = $component;
        return $this;
    }

    /** @param list<Field>|Closure(): list<Field> $fields a closure is resolved when needed (choices known after boot) */
    public function fields(array|Closure $fields): self
    {
        $this->fields = $fields;
        return $this;
    }

    /** @param bool|list<string> $children */
    public function children(bool|array $children = true): self
    {
        $this->children = $children;
        return $this;
    }

    /** @param list<string> $types */
    public function parents(array $types): self
    {
        $this->parents = $types;
        return $this;
    }

    /**
     * The design settings editors get for this block: any of color, spacing, type,
     * border (BlockDesign). None by default; the block's root element receives them.
     *
     * @param list<string> $groups
     */
    public function supports(array $groups): self
    {
        $unknown = array_diff($groups, BlockDesign::GROUPS);
        if ($unknown !== []) {
            throw new \InvalidArgumentException("Block {$this->name}: unknown design support \"" . implode('", "', $unknown) . '"; use ' . implode(', ', BlockDesign::GROUPS) . '.');
        }
        $this->supports = array_values(array_unique($groups));

        return $this;
    }

    /**
     * The blocks a new one starts with in the editor, so it arrives ready to fill:
     * ->starter(['core/button']) or ->starter([['core/heading', ['level' => '2']], 'core/text']).
     * Without one, a block that holds a single kind of child starts with two of them.
     *
     * @param list<string|array{0: string, 1?: array<string, mixed>}> $blocks
     */
    public function starter(array $blocks): self
    {
        if ($this->children === false) {
            throw new \LogicException("Block {$this->name}: call children() before starter(); a block that holds nothing can't start with anything.");
        }
        $this->starter = [];
        foreach ($blocks as $i => $b) {
            [$type, $props] = is_string($b) ? [$b, []] : [$b[0] ?? null, $b[1] ?? []];
            if (!is_string($type) || !is_array($props)) {
                throw new \InvalidArgumentException("Block {$this->name}: starter item {$i} is \"type\" or [\"type\", [props]].");
            }
            if (!$this->allowsChild($type)) {
                throw new \InvalidArgumentException("Block {$this->name}: it can't hold \"{$type}\", so it can't start with one.");
            }
            $this->starter[] = ['type' => $type, 'props' => $props];
        }

        return $this;
    }

    /** @return list<array{type: string, props: array<string, mixed>}> */
    public function getStarter(): array
    {
        return $this->starter;
    }

    /** @param array<string, mixed> $props shown in the editor's library */
    public function example(array $props): self
    {
        $this->example = $props;
        return $this;
    }

    /**
     * Server-side preparation before rendering: fn(array $node, BlockContext $ctx, BlockRegistry $blocks): ?array.
     * Return the node (props may gain data, children may be replaced) or null to hide it.
     */
    public function prepare(Closure $prepare): self
    {
        $this->prepare = $prepare;
        return $this;
    }

    /** @internal BlockRegistry */
    public function ownedBy(string $owner): self
    {
        $this->owner = $owner;
        return $this;
    }

    public function getLabel(): string
    {
        return $this->label !== '' ? $this->label : ucfirst(str_replace('-', ' ', explode('/', $this->name)[1]));
    }
    public function getDescription(): string
    {
        return $this->description;
    }
    public function getCategory(): string
    {
        return $this->category;
    }
    public function getIcon(): string
    {
        return $this->icon;
    }
    /** @return array{field: string, icons: array<string, string>}|null */
    public function getIconBy(): ?array
    {
        return $this->iconBy;
    }
    public function getOwner(): string
    {
        return $this->owner;
    }
    /** @return list<string> */
    public function getSupports(): array
    {
        return $this->supports;
    }
    /** @return array<string, mixed> */
    public function getExample(): array
    {
        return $this->example;
    }
    public function getPrepare(): ?Closure
    {
        return $this->prepare;
    }
    /** @return list<string>|null */
    public function getParents(): ?array
    {
        return $this->parents;
    }

    public function getComponent(): string
    {
        if ($this->component !== null) {
            return $this->component;
        }
        [$vendor, $name] = explode('/', $this->name);
        $studly = str_replace(' ', '', ucwords(str_replace('-', ' ', $name)));

        return '@' . $vendor . '/blocks/' . $studly;
    }

    /** @return list<Field> */
    public function getFields(): array
    {
        if ($this->fields instanceof Closure) {
            $this->fields = ($this->fields)();
        }
        foreach ($this->fields as $field) {
            if (in_array($field->name, self::RESERVED, true)) {
                throw new \InvalidArgumentException("Block {$this->name}: a field can't be named \"{$field->name}\"; the page would never receive it (it means something else to Vue). Pick another name.");
            }
        }

        return $this->fields;
    }

    /** Names Vue keeps for itself: a prop called one of these never reaches the block's component. */
    public const RESERVED = ['style', 'class', 'key', 'ref', 'is'];

    public function allowsChild(string $type): bool
    {
        return $this->children === true || (is_array($this->children) && in_array($type, $this->children, true));
    }

    public function acceptsChildren(): bool
    {
        return $this->children !== false && $this->children !== [];
    }

    /** @return bool|list<string> */
    public function getChildren(): bool|array
    {
        return $this->children;
    }
}
