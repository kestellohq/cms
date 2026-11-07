<?php

declare(strict_types=1);

namespace Kestello\Cms\Content;

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

    /** Public URL, or null when the taxonomy has no path. */
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

    public function path(Taxonomy $taxonomy): ?string
    {
        return $taxonomy->getPath() === null ? null : $taxonomy->getPath() . '/' . $this->slug;
    }
}
