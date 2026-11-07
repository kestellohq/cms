<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Content;

use Kestello\Cms\Api\ApiProblem;
use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Api\ResourceCollection;
use Kestello\Cms\Api\Schema;
use Kestello\Cms\Content\Entry;
use Kestello\Framework\Database\Orm\ModelQuery;
use Kestello\Framework\Database\QueryBuilder;

/**
 * Entries of one type. Without content.<type>.edit_others you see your
 * own entries only. filter[…] (see FieldFilters: equality, in, ranges,
 * exists) and sort=field:<name> work on indexed() fields.
 */
final class ListEntries extends ContentEndpoint
{
    private const array SORTS = ['updated_at', 'published_at', 'created_at', 'title', 'menu_order', 'id'];

    public function route(): ApiRoute
    {
        return ApiRoute::get('/content/{type}/entries')->where('type', self::TYPE);
    }
    public function resource(): ?string
    {
        return \Kestello\Cms\Api\Resources\EntryResource::class;
    }
    public function summary(): string
    {
        return 'List entries of a content type (page based, with counts per status)';
    }

    public function query(): ?Schema
    {
        return Schema::object([
            'page' => Schema::int()->min(1)->max(100000)->default(1),
            'per_page' => Schema::int()->min(1)->max(100)->default(20),
            'status' => Schema::enum(['any', 'draft', 'pending', 'scheduled', 'published', 'private', 'trash'])->default('any'),
            'search' => Schema::string()->max(100)->optional(),
            'author' => Schema::int()->min(1)->optional(),
            'assignee' => Schema::string()->pattern('(me|[0-9]{1,18})')->optional()->description('"me", or a user id'),
            'parent' => Schema::int()->min(0)->optional()->description('0 = top level'),
            'term' => Schema::int()->min(1)->optional()->description('Entries that have this term'),
            'links_to' => Schema::int()->min(1)->optional()->description('Entries that link to this entry through a relationship field'),
            'links_to_field' => Schema::string()->max(64)->pattern('[a-z][a-z0-9_]*')->optional()->description('With links_to: only through this top-level field'),
            'sort' => Schema::string()->max(80)->pattern('-?([a-z_]+|field:[a-z][a-z0-9_]*)')->default('-updated_at'),
            'filter' => Schema::any(4096, 3)->optional()->description('Indexed fields: filter[level]=pro, filter[level][]=a&filter[level][]=b, filter[price][gte]=10&filter[price][lt]=50, filter[starts_at][gte]=2027-03-01, filter[venue][exists]=false. Operators: eq, in, gt, gte, lt, lte, exists'),
        ]);
    }

    public function handle(ApiRequest $request): mixed
    {
        $type = self::type($request);
        $p = $request->principal;
        if (!$p->can($type->capability('edit'))) {
            throw ApiProblem::forbidden("You can't edit " . strtolower($type->plural()) . '.');
        }
        $base = Entry::query()->ofType($type->name);
        if (!$p->can($type->capability('edit_others'))) {
            $base->where('entries.author_id', $p->userId);
        }
        if (($search = $request->query('search')) !== null && $search !== '') {
            $base->whereLike('entries.title', '%' . QueryBuilder::escapeLike($search) . '%');
        }
        if (($author = $request->query('author')) !== null) {
            $base->where('entries.author_id', $author);
        }
        if (($assignee = $request->query('assignee')) !== null) {
            $user = $assignee === 'me' ? (int) $p->userId : (int) $assignee;
            $base->whereExists(static fn(QueryBuilder $q) => $q->from('entry_assignees')->whereColumn('entry_assignees.entry_id', '=', 'entries.id')->where('entry_assignees.user_id', $user));
        }
        if (($parent = $request->query('parent')) !== null) {
            $parent === 0 ? $base->whereNull('entries.parent_id') : $base->where('entries.parent_id', $parent);
        }
        if (($term = $request->query('term')) !== null) {
            $base->whereExists(static fn(QueryBuilder $q) => $q->from('entry_term')->whereColumn('entry_term.entry_id', '=', 'entries.id')->where('entry_term.term_id', $term));
        }
        if (($target = $request->query('links_to')) !== null) {
            $field = $request->query('links_to_field');
            if ($field !== null && !in_array($field, self::relationFields($type), true)) {
                throw ApiProblem::validation(['query.links_to_field' => ["{$type->plural()} have no relationship field \"{$field}\"."]]);
            }
            $base->whereExists(static function (QueryBuilder $q) use ($target, $field): void {
                $q->from(\Kestello\Cms\Content\EntryRelations::TABLE)->whereColumn('source_id', '=', 'entries.id')->where('target_id', $target);
                $field !== null && $q->where('field', $field);
            });
        } elseif ($request->query('links_to_field') !== null) {
            throw ApiProblem::validation(['query.links_to_field' => ['Send links_to with it.']]);
        }
        $filter = $request->query('filter');
        if ($filter !== null) {
            if (!is_array($filter)) {
                throw ApiProblem::validation(['query.filter' => ['Use filter[<field>]=value or filter[<field>][<operator>]=value.']]);
            }
            try {
                \Kestello\Cms\Content\FieldFilters::apply($base, \Kestello\Cms\Content\FieldFilters::parse($filter, $type, self::cms()->content->fields));
            } catch (\Kestello\Cms\Content\FieldFilterException $e) {
                $errors = [];
                foreach ($e->errors as $name => $messages) {
                    $errors[$name === '_' ? 'query.filter' : 'query.filter.' . $name] = $messages;
                }
                throw ApiProblem::validation($errors);
            }
        }

        $counts = ['any' => (clone $base)->count(), 'trash' => (clone $base)->onlyTrashed()->count()];
        foreach (Entry::STATUSES as $s) {
            $counts[$s] = (clone $base)->where('entries.status', $s)->count();
        }

        $status = (string) $request->query('status');
        $q = clone $base;
        if ($status === 'trash') {
            $q->onlyTrashed();
        } elseif ($status !== 'any') {
            $q->where('entries.status', $status);
        }
        $this->sort($q, (string) $request->query('sort'), $type);

        $result = $q->with('author', 'parent')->paginate((int) $request->query('per_page'), (int) $request->query('page'));
        $entries = self::entries();
        $models = $result['data']->all();
        $ids = array_map(static fn($e) => (int) $e->getKey(), $models);
        $terms = (new \Kestello\Cms\Content\Terms())->summaries($ids);
        $assignees = \Kestello\Cms\Content\Reviews::assignees($ids);
        $items = [];
        foreach ($models as $entry) {
            $items[] = new \Kestello\Cms\Api\Resources\EntryResource(['model' => $entry, 'type' => $type, 'can' => $entries->abilities($p, $type, $entry), 'full' => false, 'terms' => $terms[(int) $entry->getKey()] ?? [], 'assignee' => $assignees[(int) $entry->getKey()] ?? null]);
        }

        return new ResourceCollection($items, [
            'total' => $result['total'], 'page' => $result['current_page'], 'per_page' => $result['per_page'], 'last_page' => $result['last_page'], 'counts' => $counts,
        ]);
    }

    /**
     * Top-level fields that can hold a link to an entry: relationship fields,
     * and groups, repeaters or block fields that may contain one.
     *
     * @return list<string>
     */
    private static function relationFields(\Kestello\Cms\Content\ContentType $type): array
    {
        $holds = static function (\Kestello\Cms\Content\Field $f) use (&$holds): bool {
            return in_array($f->type, ['entry', 'blocks'], true) || array_filter($f->getChildren(), $holds) !== [];
        };

        return array_values(array_map(static fn($f) => $f->name, array_filter($type->getFields(), $holds)));
    }

    private function sort(ModelQuery $q, string $sort, \Kestello\Cms\Content\ContentType $type): void
    {
        $desc = str_starts_with($sort, '-');
        $key = ltrim($sort, '-');
        $dir = $desc ? 'DESC' : 'ASC';
        if (str_starts_with($key, 'field:')) {
            $field = $type->getField(substr($key, 6));
            if ($field === null || !$field->isIndexed() || !self::cms()->content->fields->get($field->type)->singleValued($field)) {
                throw ApiProblem::validation(['query.sort' => ['Sort by an indexed, single-valued field.']]);
            }
            $column = 'value_' . self::cms()->content->fields->get($field->type)->indexKind($field);
            $q->select('entries.*')->leftJoin('entry_index', static fn(QueryBuilder $j) => $j->whereColumn('entry_index.entry_id', '=', 'entries.id')->where('entry_index.field', $field->name))
                ->orderBy("entry_index.{$column}", $dir);
        } elseif (in_array($key, self::SORTS, true)) {
            $q->orderBy('entries.' . $key, $dir);
        } else {
            throw ApiProblem::validation(['query.sort' => ['Sort by one of: ' . implode(', ', self::SORTS) . ', or field:<indexed field>.']]);
        }
        $q->orderBy('entries.id', $dir);
    }
}
