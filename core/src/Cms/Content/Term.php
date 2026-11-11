<?php

declare(strict_types=1);

namespace Kestello\Cms\Content;

use Kestello\Cms\I18n\ContentLanguages;
use Kestello\Framework\Database\Model;
use Kestello\Framework\Database\Orm\Relations\BelongsTo;
use Kestello\Framework\Database\Orm\Relations\BelongsToMany;
use Kestello\Framework\Database\Orm\Relations\HasMany;

/**
 * A term of a taxonomy (a category, a tag). Write through Terms.
 *
 * @property int $id
 * @property string $taxonomy
 * @property string $name
 * @property string $slug
 * @property string $locale '' = the main language, else its code
 * @property ?string $translation_group shared by a term and its translations
 * @property ?int $parent_id
 * @property ?string $description
 * @property int $menu_order
 *
 * @api
 */
final class Term extends Model
{
    protected string $table = 'terms';
    protected array $casts = ['parent_id' => 'int', 'menu_order' => 'int'];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Term::class, 'parent_id');
    }
    public function children(): HasMany
    {
        return $this->hasMany(Term::class, 'parent_id');
    }
    public function entries(): BelongsToMany
    {
        return $this->belongsToMany(Entry::class, 'entry_term', 'term_id', 'entry_id')->withPivot('position');
    }

    protected function scopeOf(\Kestello\Framework\Database\Orm\ModelQuery $q, string $taxonomy): void
    {
        $q->where('terms.taxonomy', $taxonomy);
    }

    /**
     * Limit a terms query to terms used by at least one published entry: a
     * tag added on a draft is nobody's business until the draft goes live.
     */
    public static function onlyInUse(\Kestello\Framework\Database\Orm\ModelQuery $q): \Kestello\Framework\Database\Orm\ModelQuery
    {
        return $q->whereExists(static fn(\Kestello\Framework\Database\QueryBuilder $s) => $s->from('entry_term')
            ->join('entries', 'entries.id', '=', 'entry_term.entry_id')
            ->whereColumn('entry_term.term_id', '=', 'terms.id')
            ->where('entries.status', 'published')
            ->where('entries.published_at', '<=', \Kestello\Framework\Database\Model::freshTimestamp())
            ->whereNull('entries.deleted_at'));
    }

    /** Its page's address without a language prefix (/category/news), or null when the taxonomy has no path. */
    public function path(Taxonomy $taxonomy): ?string
    {
        return $taxonomy->getPath() === null ? null : $taxonomy->getPath() . '/' . $this->slug;
    }

    /** Its page's address in its own language (/es/category/noticias), or null. */
    public function url(Taxonomy $taxonomy): ?string
    {
        $path = $this->path($taxonomy);

        return $path === null ? null : ContentLanguages::localize($path, $this->language());
    }

    /** The language it is written in: '' for the main one. */
    public function language(): string
    {
        return (string) ($this->locale ?? '');
    }

    /** This term in $code ('' = the main language): itself, a translation, or null. */
    public function translation(string $code): ?self
    {
        if ($this->language() === $code) {
            return $this;
        }
        if ((string) $this->translation_group === '') {
            return null;
        }
        $q = self::query();
        $q->where('translation_group', $this->translation_group)->where('locale', $code);
        $found = $q->first();

        return $found instanceof self ? $found : null;
    }

    /**
     * Its id and its translations' ids: an entry tagged with any of them is
     * listed on its page (a translated post may still carry the original's
     * category).
     *
     * @return list<int>
     */
    public function groupIds(): array
    {
        if ((string) $this->translation_group === '') {
            return [(int) $this->getKey()];
        }

        return array_map('intval', \Kestello\Framework\Database\QueryBuilder::table('terms')->where('translation_group', $this->translation_group)->pluck('id'));
    }

    /**
     * The term at /{taxonomy path}/{slug} in $language: one written in it,
     * else the main language's (whose page then lists $language's entries).
     */
    public static function findPublic(string $taxonomy, string $slug, string $language): ?self
    {
        foreach (array_values(array_unique([$language, ''])) as $code) {
            $q = self::query();
            $q->where('terms.taxonomy', $taxonomy)->where('slug', $slug)->where('locale', $code);
            if (($term = $q->first()) instanceof self) {
                return $term;
            }
        }

        return null;
    }
}
