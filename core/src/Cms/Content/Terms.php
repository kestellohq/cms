<?php

declare(strict_types=1);

namespace Kestello\Cms\Content;

use Kestello\Contracts\Auth\Principal;
use Kestello\Framework\Database\DatabaseManager;
use Kestello\Framework\Database\QueryBuilder;
use Kestello\Support\Str;
use Kestello\Support\Translator;

/**
 * Writing terms and assigning them to entries.
 *
 *   $terms->create($cms->content->getTaxonomy('category'), ['name' => 'News'], $principal);
 *   $ids = $terms->ensure($tax, $terms->check($tax, [3, 'new tag'], $principal, $errors, 'terms.tag'));
 *
 * Throws ContentException (invalid / forbidden / conflict).
 *
 * @api
 */
final class Terms
{
    public const int MAX_PER_ENTRY = 50;

    /** @param array{name?: string, slug?: string, parent_id?: ?int, description?: ?string, menu_order?: int} $input */
    public function create(Taxonomy $tax, array $input, Principal $p): Term
    {
        if (!$p->can($tax->capability('manage'))) {
            throw ContentException::forbidden("You can't add " . strtolower($tax->plural()) . '.');
        }

        return $this->write($tax, (new Term())->forceFill(['taxonomy' => $tax->name, 'menu_order' => 0]), $input, true);
    }

    public function update(Taxonomy $tax, Term $term, array $input, Principal $p): Term
    {
        if (!$p->can($tax->capability('manage'))) {
            throw ContentException::forbidden("You can't edit " . strtolower($tax->plural()) . '.');
        }

        return $this->write($tax, $term, $input, false);
    }

    /**
     * Delete every term of a taxonomy (a plugin's uninstall(); only its own
     * taxonomies). Entries lose them.
     *
     * @return int terms deleted
     */
    public function purgeTaxonomy(string $taxonomy): int
    {
        ContentRegistry::assertOwnName($taxonomy, 'Taxonomies');

        return DatabaseManager::getConnection()->transaction(static function () use ($taxonomy): int {
            QueryBuilder::table('entry_term')->where('taxonomy', $taxonomy)->delete();
            QueryBuilder::table('terms')->where('taxonomy', $taxonomy)->update(['parent_id' => null]);

            return QueryBuilder::table('terms')->where('taxonomy', $taxonomy)->delete();
        });
    }

    /** Delete a term. Its children move up to its parent; entries just lose it. */
    public function delete(Taxonomy $tax, Term $term, Principal $p): void
    {
        if (!$p->can($tax->capability('manage'))) {
            throw ContentException::forbidden("You can't delete " . strtolower($tax->plural()) . '.');
        }
        DatabaseManager::getConnection()->transaction(static function () use ($term): void {
            QueryBuilder::table('terms')->where('parent_id', $term->getKey())->update(['parent_id' => $term->parent_id]);
            $term->delete();
        });
    }

    /**
     * Check a list of term ids and (where allowed) names of new terms,
     * without writing anything. Errors go to $errors[$key].
     *
     * @param list<mixed> $values
     * @param array<string, list<string>> $errors
     * @return array{ids: list<int>, names: list<string>}
     */
    public function check(Taxonomy $tax, array $values, Principal $p, array &$errors, string $key): array
    {
        $none = ['ids' => [], 'names' => []];
        if (count($values) > self::MAX_PER_ENTRY) {
            $errors[$key][] = Translator::get('At most {max} {terms}.', ['max' => self::MAX_PER_ENTRY, 'terms' => mb_strtolower($tax->plural())]);
            return $none;
        }
        $ids = [];
        $names = [];
        foreach ($values as $v) {
            if (is_int($v) || (is_string($v) && ctype_digit($v))) {
                $ids[] = (int) $v;
            } elseif (is_string($v) && trim($v) !== '' && mb_strlen(trim($v)) <= 191) {
                $names[] = trim($v);
            } else {
                $errors[$key][] = Translator::get('Use term ids, or names of new terms.');
                return $none;
            }
        }
        $ids = array_values(array_unique($ids));
        if ($ids !== []) {
            $found = array_map('intval', QueryBuilder::table('terms')->where('taxonomy', $tax->name)->whereIn('id', $ids)->pluck('id'));
            $missing = array_diff($ids, $found);
            if ($missing !== []) {
                $errors[$key][] = Translator::get('No such {term}: {list}.', ['term' => mb_strtolower($tax->singular()), 'list' => implode(', ', $missing)]);
                return $none;
            }
        }
        if ($names !== [] && !$tax->canCreateInline($p)) {
            $errors[$key][] = Translator::get('You can\'t add new {terms}; pick existing ones.', ['terms' => mb_strtolower($tax->plural())]);
            return $none;
        }

        return ['ids' => $ids, 'names' => array_values(array_unique($names))];
    }

    /**
     * Ids for checked values, creating the named terms (a name or slug that
     * already exists is reused rather than becoming "news-2").
     *
     * @param array{ids: list<int>, names: list<string>} $checked
     * @return list<int>
     */
    public function ensure(Taxonomy $tax, array $checked): array
    {
        $ids = $checked['ids'];
        foreach ($checked['names'] as $name) {
            $existing = QueryBuilder::table('terms')->where('taxonomy', $tax->name)
                ->where(static fn(QueryBuilder $q) => $q->where('name', $name)->orWhere('slug', Str::slug($name)))->value('id');
            $ids[] = $existing !== null ? (int) $existing : (int) $this->write($tax, (new Term())->forceFill(['taxonomy' => $tax->name, 'menu_order' => 0]), ['name' => $name], true)->getKey();
        }

        return array_values(array_unique($ids));
    }

    /**
     * Set an entry's terms of one taxonomy (in the given order).
     *
     * @param list<int> $ids
     */
    public function sync(Entry $entry, Taxonomy $tax, array $ids): void
    {
        QueryBuilder::table('entry_term')->where('entry_id', $entry->getKey())->where('taxonomy', $tax->name)->delete();
        $rows = [];
        foreach (array_values($ids) as $i => $id) {
            $rows[] = ['entry_id' => $entry->getKey(), 'term_id' => $id, 'taxonomy' => $tax->name, 'position' => $i];
        }
        if ($rows !== []) {
            QueryBuilder::table('entry_term')->insertMany($rows);
        }
    }

    /**
     * Term ids per taxonomy for entries.
     *
     * @param list<int> $entryIds
     * @return array<int, array<string, list<int>>> entry id => taxonomy => ids
     */
    public function idsFor(array $entryIds): array
    {
        $out = [];
        if ($entryIds === []) {
            return $out;
        }
        foreach (QueryBuilder::table('entry_term')->whereIn('entry_id', $entryIds)->orderBy('position')->get() as $row) {
            $out[(int) $row['entry_id']][(string) $row['taxonomy']][] = (int) $row['term_id'];
        }

        return $out;
    }

    /**
     * Terms of entries, for display.
     *
     * @param list<int> $entryIds
     * @return array<int, array<string, list<array{id: int, name: string, slug: string}>>> entry id => taxonomy => terms
     */
    public function summaries(array $entryIds): array
    {
        $out = [];
        if ($entryIds === []) {
            return $out;
        }
        $rows = QueryBuilder::table('entry_term')->join('terms', 'terms.id', '=', 'entry_term.term_id')
            ->whereIn('entry_term.entry_id', $entryIds)->orderBy('entry_term.position')
            ->select('entry_term.entry_id', 'entry_term.taxonomy', 'terms.id', 'terms.name', 'terms.slug')->get();
        foreach ($rows as $row) {
            $out[(int) $row['entry_id']][(string) $row['taxonomy']][] = ['id' => (int) $row['id'], 'name' => (string) $row['name'], 'slug' => (string) $row['slug']];
        }

        return $out;
    }

    /** Published entries per term (for lists and the admin). @return array<int, int> */
    public function counts(Taxonomy $tax): array
    {
        $rows = QueryBuilder::table('entry_term')
            ->join('entries', 'entries.id', '=', 'entry_term.entry_id')
            ->where('entry_term.taxonomy', $tax->name)->where('entries.status', 'published')->whereNull('entries.deleted_at')
            ->select('entry_term.term_id')->selectRaw('COUNT(*) AS n')->groupBy('entry_term.term_id')->get();
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['term_id']] = (int) $row['n'];
        }

        return $out;
    }

    /** $base, or $base-2… unique in the taxonomy. */
    public function uniqueSlug(Taxonomy $tax, string $base, int|string|null $exceptId = null): string
    {
        $base = trim(mb_substr($base, 0, 180), '-') ?: 'term';
        $taken = QueryBuilder::table('terms')->where('taxonomy', $tax->name)
            ->where(static fn(QueryBuilder $q) => $q->where('slug', $base)->orWhereLike('slug', QueryBuilder::escapeLike($base) . '-%'))
            ->when($exceptId !== null, static fn($q) => $q->where('id', '!=', $exceptId))->pluck('slug');
        if (!in_array($base, $taken, true)) {
            return $base;
        }
        for ($i = 2; ; $i++) {
            if (!in_array("{$base}-{$i}", $taken, true)) {
                return "{$base}-{$i}";
            }
        }
    }

    private function write(Taxonomy $tax, Term $term, array $input, bool $new): Term
    {
        $errors = [];
        $name = array_key_exists('name', $input) ? trim((string) $input['name']) : (string) $term->name;
        if ($name === '') {
            $errors['name'][] = Translator::get('Give it a name.');
        } elseif (mb_strlen($name) > 191) {
            $errors['name'][] = Translator::get('Must be at most 191 characters.');
        }
        $parentId = array_key_exists('parent_id', $input) ? ($input['parent_id'] === null ? null : (int) $input['parent_id']) : $term->parent_id;
        if ($parentId !== null) {
            if (!$tax->isHierarchical()) {
                $errors['parent_id'][] = strtolower($tax->plural()) . " don't nest.";
            } elseif (($problem = $this->parentProblem($tax, $term, $parentId)) !== null) {
                $errors['parent_id'][] = $problem;
            }
        }
        $slugInput = array_key_exists('slug', $input) ? trim((string) $input['slug']) : '';
        $base = $slugInput !== '' ? Str::slug(mb_substr($slugInput, 0, 180)) : ($new || $term->slug === '' ? Str::slug(mb_substr($name, 0, 180)) : (string) $term->slug);
        if ($slugInput !== '' && $base === '') {
            $errors['slug'][] = Translator::get('Use letters or digits.');
        }
        $description = array_key_exists('description', $input) ? ($input['description'] === null ? null : trim((string) $input['description'])) : $term->description;
        if ($description !== null && mb_strlen($description) > 2000) {
            $errors['description'][] = Translator::get('Must be at most 2000 characters.');
        }
        if ($errors !== []) {
            throw ContentException::invalid($errors);
        }
        $term->forceFill([
            'name' => $name, 'slug' => $this->uniqueSlug($tax, $base ?: 'term', $term->getKey()), 'parent_id' => $parentId,
            'description' => $description === '' ? null : $description,
            'menu_order' => array_key_exists('menu_order', $input) ? (int) $input['menu_order'] : (int) $term->menu_order,
        ])->save();

        return $term;
    }

    private function parentProblem(Taxonomy $tax, Term $term, int $parentId): ?string
    {
        if ($term->getKey() !== null && $parentId === $term->getKey()) {
            return 'A term cannot be its own parent.';
        }
        $current = $parentId;
        for ($depth = 0; $current !== null && $depth < 10; $depth++) {
            $row = QueryBuilder::table('terms')->select('id', 'taxonomy', 'parent_id')->where('id', $current)->first();
            if ($row === null || $row['taxonomy'] !== $tax->name) {
                return 'Pick an existing ' . strtolower($tax->singular()) . '.';
            }
            if ($term->getKey() !== null && (int) $row['id'] === $term->getKey()) {
                return 'That would put it inside itself.';
            }
            $current = $row['parent_id'] === null ? null : (int) $row['parent_id'];
        }

        return $depth >= 10 ? 'Terms can be nested at most 10 levels.' : null;
    }
}
