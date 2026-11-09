<?php

declare(strict_types=1);

namespace Kestello\Cms\Content;

use InvalidArgumentException;

/**
 * A content type, declared in code:
 *
 *   $cms->content->type('event')
 *       ->label('Event', 'Events')->icon('calendar-dots')
 *       ->fields([Field::datetime('starts_at')->required()->indexed(), Field::text('venue')])
 *       ->path('/events')->archive();
 *
 * Capabilities: content.<type>.{create, edit, edit_others, publish,
 * delete, delete_others}. Default grants: Editor gets all of them; for
 * non-hierarchical types Author gets create/edit/publish/delete and
 * Contributor create/edit (own drafts). Change them with grant().
 *
 * @api
 */
final class ContentType
{
    public const array ACTIONS = ['create', 'edit', 'edit_others', 'publish', 'delete', 'delete_others'];

    private string $singular;
    private string $plural;
    private string $icon = 'file-text';
    private string $description = '';
    private bool $hierarchical = false;
    /** @var list<Field> */
    private array $fields = [];
    private bool $excerpt = false;
    private int $revisions = 25;
    private ?string $path = null;
    private bool $archive = false;
    private string $permalink = '{slug}';
    private ?bool $searchable = null;
    private int $menuOrder = 50;
    /** @var array<string, list<string>>|null */
    private ?array $grants = null;

    /** @var list<array{check: string, level: string, taxonomy?: string, min?: int}> */
    private array $checklist = [];

    public function __construct(public readonly string $name, public readonly string $owner)
    {
        if (preg_match('/^[a-z][a-z0-9-]{0,31}$/', $name) !== 1) {
            throw new InvalidArgumentException("Invalid content type name [{$name}]: lowercase letters, digits and dashes, up to 32.");
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

    public function icon(string $icon): self
    {
        if (preg_match('/^[a-z0-9-]{1,40}$/', $icon) !== 1) {
            throw new InvalidArgumentException("Invalid icon [{$icon}].");
        }
        $this->icon = $icon;
        return $this;
    }

    public function description(string $text): self
    {
        $this->description = $text;
        return $this;
    }

    /** Entries can have a parent (pages). */
    public function hierarchical(bool $on = true): self
    {
        $this->hierarchical = $on;
        return $this;
    }

    /** Show the excerpt box in the editor. */
    public function excerpt(bool $on = true): self
    {
        $this->excerpt = $on;
        return $this;
    }

    /** Revisions kept per entry (0 turns them off). */
    public function revisions(int $keep): self
    {
        $this->revisions = max(0, min(500, $keep));
        return $this;
    }

    public function menuOrder(int $order): self
    {
        $this->menuOrder = $order;
        return $this;
    }

    /**
     * Public URL prefix: "" for /{slug} (nested by parent when
     * hierarchical), "/blog" for /blog/{slug}; null = not public.
     */
    public function path(?string $prefix): self
    {
        if ($prefix !== null && preg_match('#^(/[a-z0-9][a-z0-9-]*)*$#', $prefix) !== 1) {
            throw new InvalidArgumentException("Invalid content path [{$prefix}].");
        }
        $this->path = $prefix;
        return $this;
    }

    /** A paginated list of published entries at the path prefix (/blog). */
    public function archive(bool $on = true): self
    {
        $this->archive = $on;
        return $this;
    }

    /** The default address pattern of a dated type ('{year}/{month}/{slug}'); Settings → Reading can change it. */
    public function permalink(string $pattern): self
    {
        if (!isset(Permalinks::PATTERNS[$pattern])) {
            throw new InvalidArgumentException("Unknown permalink pattern [{$pattern}]; use one of " . implode(', ', array_keys(Permalinks::PATTERNS)) . '.');
        }
        $this->permalink = $pattern;
        return $this;
    }

    public function getPermalink(): string
    {
        return $this->permalink;
    }

    /** Whether published entries appear in site search (default: types with public addresses). */
    public function searchable(bool $on = true): self
    {
        $this->searchable = $on;
        return $this;
    }

    /** @param list<Field> $fields */
    public function fields(array $fields): self
    {
        foreach ($fields as $field) {
            $this->field($field);
        }
        return $this;
    }

    public function field(Field $field): self
    {
        foreach ($this->fields as $existing) {
            if ($existing->name === $field->name) {
                throw new InvalidArgumentException("Content type [{$this->name}] already has a field [{$field->name}].");
            }
        }
        foreach ($field->getChildren() as $child) {
            self::assertNotIndexed($child);
        }
        $this->fields[] = $field;
        return $this;
    }

    /**
     * What an entry must or should have before it is published (Checklist):
     * checklist([['check' => 'featured_image', 'level' => 'required'], ['check' => 'words', 'min' => 300]]).
     *
     * @param list<array<string, mixed>> $items
     */
    public function checklist(array $items): self
    {
        if (count($items) > Checklist::MAX_ITEMS) {
            throw new InvalidArgumentException("Content type [{$this->name}]: a checklist has at most " . Checklist::MAX_ITEMS . ' items.');
        }
        $this->checklist = array_values(array_map(static fn(mixed $i): array => Checklist::item(is_array($i) ? $i : []), $items));
        return $this;
    }

    /** @return list<array{check: string, level: string, taxonomy?: string, min?: int}> */
    public function getChecklist(): array
    {
        return $this->checklist;
    }

    /**
     * Replace the default grants for a role: grant('author', 'create', 'edit').
     */
    public function grant(string $role, string ...$actions): self
    {
        foreach ($actions as $action) {
            if (!in_array($action, self::ACTIONS, true)) {
                throw new InvalidArgumentException("Unknown content action [{$action}].");
            }
        }
        $this->grants = ($this->grants ?? $this->defaultGrants());
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
    public function getIcon(): string
    {
        return $this->icon;
    }
    public function getDescription(): string
    {
        return $this->description;
    }
    public function isHierarchical(): bool
    {
        return $this->hierarchical;
    }
    public function hasExcerpt(): bool
    {
        return $this->excerpt;
    }
    public function keepRevisions(): int
    {
        return $this->revisions;
    }
    public function getPath(): ?string
    {
        return $this->path;
    }
    public function hasArchive(): bool
    {
        return $this->archive && $this->path !== null && $this->path !== '';
    }
    public function getMenuOrder(): int
    {
        return $this->menuOrder;
    }
    public function isSearchable(): bool
    {
        return $this->path !== null && ($this->searchable ?? true);
    }
    /** @return list<Field> */
    public function getFields(): array
    {
        return $this->fields;
    }

    public function getField(string $name): ?Field
    {
        foreach ($this->fields as $field) {
            if ($field->name === $name) {
                return $field;
            }
        }

        return null;
    }

    public function capability(string $action): string
    {
        return "content.{$this->name}.{$action}";
    }

    /** @return array<string, list<string>> role => actions */
    public function grants(): array
    {
        return $this->grants ?? $this->defaultGrants();
    }

    /** @return array<string, list<string>> */
    private function defaultGrants(): array
    {
        $grants = ['editor' => self::ACTIONS];
        if (!$this->hierarchical) {
            $grants['author'] = ['create', 'edit', 'publish', 'delete'];
            $grants['contributor'] = ['create', 'edit'];
        }

        return $grants;
    }

    private static function assertNotIndexed(Field $field): void
    {
        if ($field->isIndexed()) {
            throw new InvalidArgumentException("Only top-level fields can be indexed ([{$field->name}] is nested).");
        }
        foreach ($field->getChildren() as $child) {
            self::assertNotIndexed($child);
        }
    }
}
