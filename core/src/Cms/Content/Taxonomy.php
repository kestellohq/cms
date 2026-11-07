<?php

declare(strict_types=1);

namespace Kestello\Cms\Content;

use InvalidArgumentException;

/**
 * A way to group entries: categories, tags, a plugin's "event-topic".
 *
 *   $cms->content->taxonomy('category')->label('Category', 'Categories')
 *       ->hierarchical()->for('post')->path('/category');
 *
 * Capabilities: taxonomy.<name>.manage (create, rename, delete terms) and
 * taxonomy.<name>.assign (pick terms on an entry). In flat taxonomies
 * (tags) anyone who can assign may also add a new term by typing it.
 *
 * @api
 */
final class Taxonomy
{
    public const array ACTIONS = ['manage', 'assign'];

    private string $singular;
    private string $plural;
    private string $description = '';
    private bool $hierarchical = false;
    private ?string $path = null;
    private int $menuOrder = 50;
    /** @var list<string> */
    private array $types = [];
    /** @var array<string, list<string>>|null */
    private ?array $grants = null;

    public function __construct(public readonly string $name, public readonly string $owner)
    {
        if (preg_match('/^[a-z][a-z0-9-]{0,31}$/', $name) !== 1) {
            throw new InvalidArgumentException("Invalid taxonomy name [{$name}].");
        }
        $this->singular = ucfirst(str_replace('-', ' ', $name));
        $this->plural = $this->singular . 's';
    }

    public function label(string $singular, ?string $plural = null): self
    {
        $this->singular = $singular;
        $this->plural = $plural ?? $singular . 's';
        return $this;
    }

    public function description(string $text): self
    {
        $this->description = $text;
        return $this;
    }
    public function hierarchical(bool $on = true): self
    {
        $this->hierarchical = $on;
        return $this;
    }
    public function menuOrder(int $order): self
    {
        $this->menuOrder = $order;
        return $this;
    }

    /** Content types whose entries can have these terms. */
    public function for(string ...$types): self
    {
        $this->types = array_values(array_unique([...$this->types, ...$types]));
        return $this;
    }

    /** Public term pages at {prefix}/{slug} listing the term's published entries; null = none. */
    public function path(?string $prefix): self
    {
        if ($prefix !== null && preg_match('#^(/[a-z0-9][a-z0-9-]*)+$#', $prefix) !== 1) {
            throw new InvalidArgumentException("Invalid taxonomy path [{$prefix}] (use /topic).");
        }
        $this->path = $prefix;
        return $this;
    }

    /** Replace the default grants for a role: grant('author', 'assign'). */
    public function grant(string $role, string ...$actions): self
    {
        foreach ($actions as $action) {
            if (!in_array($action, self::ACTIONS, true)) {
                throw new InvalidArgumentException("Unknown taxonomy action [{$action}].");
            }
        }
        $this->grants ??= $this->defaultGrants();
        $this->grants[$role] = array_values($actions);
        return $this;
    }

    public function singular(): string
    {
        return $this->singular;
    }
    public function plural(): string
    {
        return $this->plural;
    }
    public function getDescription(): string
    {
        return $this->description;
    }
    public function isHierarchical(): bool
    {
        return $this->hierarchical;
    }
    public function getPath(): ?string
    {
        return $this->path;
    }
    public function getMenuOrder(): int
    {
        return $this->menuOrder;
    }
    /** @return list<string> */
    public function getTypes(): array
    {
        return $this->types;
    }
    public function appliesTo(string $type): bool
    {
        return in_array($type, $this->types, true);
    }

    public function capability(string $action): string
    {
        return "taxonomy.{$this->name}.{$action}";
    }

    /** Whether $p may add terms by typing their names. */
    public function canCreateInline(\Kestello\Contracts\Auth\Principal $p): bool
    {
        return $p->can($this->capability('manage')) || (!$this->hierarchical && $p->can($this->capability('assign')));
    }

    /** @return array<string, list<string>> role => actions */
    public function grants(): array
    {
        return $this->grants ?? $this->defaultGrants();
    }

    /** @return array<string, list<string>> */
    private function defaultGrants(): array
    {
        return ['editor' => self::ACTIONS, 'author' => ['assign'], 'contributor' => ['assign']];
    }
}
