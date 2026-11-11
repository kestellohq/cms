<?php

declare(strict_types=1);

namespace Kestello\Cms\Content;

use Kestello\Cms\Cms;
use Kestello\Cms\I18n\ContentLanguages;
use Kestello\Cms\Models\User;
use Kestello\Framework\Database\Model;
use Kestello\Framework\Database\Orm\ModelQuery;
use Kestello\Framework\Database\Orm\Relations\BelongsTo;
use Kestello\Framework\Database\Orm\Relations\HasMany;
use Kestello\Support\Clock;

/**
 * An entry of any content type. Write through Entries (validation,
 * permissions, revisions, the index); read freely:
 *
 *   Entry::query()->ofType('post')->published()->with('author')->latest('published_at')->paginate(10);
 *   $entry->field('subtitle');
 *
 * @property int $id
 * @property string $type
 * @property string $status
 * @property string $title
 * @property string $slug
 * @property array|null $data
 * @property int|null $parent_id
 * @property int|null $author_id
 * @property string $locale '' for the site's main language, else its code (ContentLanguages)
 * @property string|null $translation_group shared by an entry and its translations
 * @property string|null $template
 * @property string|null $excerpt
 * @property int $menu_order
 * @property int $version
 * @api
 */
final class Entry extends Model
{
    public const array STATUSES = ['draft', 'pending', 'scheduled', 'published', 'private'];

    /** Statuses someone who may edit, but not publish, can save: a draft, or one submitted for review. */
    public const array UNPUBLISHED = ['draft', 'pending'];

    protected string $table = 'entries';
    protected bool $softDeletes = true;
    protected array $casts = [
        'data' => 'array',
        'parent_id' => 'int',
        'author_id' => 'int',
        'menu_order' => 'int',
        'version' => 'int',
        'published_at' => 'datetime',
    ];

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }
    public function revisions(): HasMany
    {
        return $this->hasMany(Revision::class, 'entry_id');
    }
    public function terms(): \Kestello\Framework\Database\Orm\Relations\BelongsToMany
    {
        return $this->belongsToMany(Term::class, 'entry_term', 'entry_id', 'term_id')->withPivot('taxonomy', 'position')->orderByPivot('position');
    }

    protected function scopeOfType(ModelQuery $q, string $type): void
    {
        $q->where('entries.type', $type);
    }

    /** Published and due (what visitors may see). */
    protected function scopePublished(ModelQuery $q): void
    {
        $q->where('entries.status', 'published')->where('entries.published_at', '<=', Model::freshTimestamp());
    }

    /**
     * Entries written in $code ('' = the site's main language; default: the language of the page being answered).
     *
     * @param ModelQuery<self> $q
     */
    protected function scopeInLanguage(ModelQuery $q, ?string $code = null): void
    {
        $q->where('entries.locale', $code ?? ContentLanguages::current());
    }

    /** The language this entry is written in: '' for the site's main language, else its code ("es"). */
    public function language(): string
    {
        return (string) ($this->locale ?? '');
    }

    /** This entry in $code ('' = the main language): itself, a translation, or null when there is none. */
    public function translation(string $code, bool $published = true): ?self
    {
        if ($this->language() === $code) {
            return $this;
        }
        if ($this->translation_group === null || $this->translation_group === '') {
            return null;
        }
        $q = self::query();
        $q->where('translation_group', $this->translation_group)->where('locale', $code);
        $found = $q->first();      // one per language

        return $found instanceof self && (!$published || $found->isPublished()) ? $found : null;
    }

    /** A value from the entry's data. */
    public function field(string $name, mixed $default = null): mixed
    {
        return ($this->data ?? [])[$name] ?? $default;
    }

    public function contentType(): ?ContentType
    {
        return Cms::instance()?->content->get((string) $this->type);
    }

    public function isPublished(): bool
    {
        return $this->status === 'published' && $this->published_at !== null && $this->published_at <= Clock::now();
    }

    /**
     * Where the site links to this entry: its path, except for the page
     * chosen as the home page (Settings, Reading), which lives at /. Its
     * path still answers, with a redirect to /.
     */
    public function url(?ContentType $type = null): ?string
    {
        $path = $this->path($type);

        return $path !== null && $this->isFrontPage() ? ContentLanguages::localize('/', $this->language()) : $path;
    }

    /** Whether this is the home page (Settings, Reading) or its translation into another language. */
    public function isFrontPage(): bool
    {
        $front = ContentController::frontPageId();
        if (!$this->exists() || $front < 1) {
            return false;
        }
        if ((int) $this->getKey() === $front) {
            return true;
        }
        if ($this->language() === '' || $this->translation_group === null || $this->translation_group === '') {
            return false;     // only the chosen page itself in the main language
        }

        return self::query()->where('id', $front)->where('translation_group', $this->translation_group)->exists();
    }

    /**
     * The public path (/about/team, /blog/hello; /es/about/equipo for an
     * entry in Spanish), or null when the type isn't public. Parents are read once per call. Links use url(), which
     * knows the home page; path() is the address the slugs make, for
     * routing and redirects.
     */
    public function path(?ContentType $type = null): ?string
    {
        $type ??= $this->contentType();
        if ($type === null || $type->getPath() === null) {
            return null;
        }
        if (!$type->isHierarchical()) {
            return ContentLanguages::localize($type->getPath() . '/' . Permalinks::segments($type, (string) $this->slug, $this->published_at), $this->language());
        }
        $segments = [$this->slug];
        if ($type->isHierarchical()) {
            $seen = $this->getKey() === null ? [] : [$this->getKey() => true];      // an entry not saved yet (the editor's canvas) has no id
            $parentId = $this->parent_id;
            while ($parentId !== null && !isset($seen[$parentId]) && count($segments) <= Entries::MAX_DEPTH) {
                $seen[$parentId] = true;
                $parent = self::parentRow((int) $parentId);
                if ($parent === null) {
                    break;
                }
                array_unshift($segments, $parent['slug']);
                $parentId = $parent['parent_id'] === null ? null : (int) $parent['parent_id'];
            }
        }

        return ContentLanguages::localize($type->getPath() . '/' . implode('/', $segments), $this->language());     // with the language's prefix, and its own for the type
    }

    /** @var array<int, array<string, mixed>|null>|null parent rows, kept only inside remembering() */
    private static ?array $parents = null;

    /**
     * Run $work with parent lookups remembered: a sitemap of a thousand pages
     * then reads each parent once instead of once per child.
     *
     * @template T
     * @param callable(): T $work
     * @return T
     */
    public static function rememberingParents(callable $work): mixed
    {
        $outer = self::$parents;
        self::$parents ??= [];
        try {
            return $work();
        } finally {
            self::$parents = $outer;
        }
    }

    /** @return array<string, mixed>|null */
    private static function parentRow(int $id): ?array
    {
        if (self::$parents !== null && array_key_exists($id, self::$parents)) {
            return self::$parents[$id];
        }
        $row = self::query()->withTrashed()->toBase()->select('id', 'slug', 'status', 'parent_id', self::DELETED_AT)->where('id', $id)->first();
        if (self::$parents !== null) {
            self::$parents[$id] = $row;
        }

        return $row;
    }

    /**
     * Ids of the parents, nearest first, when every one of them is published
     * and not in the trash; null when one is not (then this entry is not
     * public either, whatever its own status).
     *
     * @return list<int>|null
     */
    public function publicAncestors(): ?array
    {
        $ids = [];
        $seen = [$this->getKey() => true];
        $parentId = $this->parent_id;
        while ($parentId !== null && !isset($seen[$parentId]) && count($ids) <= Entries::MAX_DEPTH) {
            $seen[$parentId] = true;
            $parent = self::parentRow((int) $parentId);
            if ($parent === null || $parent['status'] !== 'published' || $parent[self::DELETED_AT] !== null) {
                return null;
            }
            $ids[] = (int) $parent['id'];
            $parentId = $parent['parent_id'] === null ? null : (int) $parent['parent_id'];
        }

        return $ids;
    }
}
