<?php

declare(strict_types=1);

namespace Kestello\Framework\Database\Orm\Relations;

use InvalidArgumentException;
use Kestello\Framework\Database\Model;
use Kestello\Framework\Database\Orm\Caster;
use Kestello\Framework\Database\Orm\ModelCollection;
use Kestello\Framework\Database\Orm\ModelQuery;
use Kestello\Framework\Database\QueryBuilder;

/**
 * Many-to-many through a pivot table: $entry->terms (entry_term.entry_id,
 * entry_term.term_id). Each loaded model carries its pivot row as
 * $term->pivot (an array: the two keys plus withPivot() columns).
 *
 * @api
 */
final class BelongsToMany extends Relation
{
    /** @var list<string> */
    private array $pivotColumns = [];
    private bool $pivotTimestamps = false;

    public function __construct(
        ModelQuery $query,
        Model $parent,
        private readonly string $table,
        private readonly string $foreignPivotKey,
        private readonly string $relatedPivotKey,
        private readonly string $parentKey,
        private readonly string $relatedKey,
    ) {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $table) !== 1) {
            throw new InvalidArgumentException("Invalid pivot table [{$table}].");
        }
        parent::__construct($query, $parent);
    }

    /** Also read these pivot columns. */
    public function withPivot(string ...$columns): self
    {
        foreach ($columns as $column) {
            if (preg_match('/^[a-z_][a-z0-9_]*$/', $column) !== 1) {
                throw new InvalidArgumentException("Invalid pivot column [{$column}].");
            }
        }
        $this->pivotColumns = array_values(array_unique([...$this->pivotColumns, ...$columns]));

        return $this;
    }

    /** The pivot table has created_at/updated_at: fill them on attach. */
    public function withTimestamps(): self
    {
        $this->pivotTimestamps = true;

        return $this->withPivot('created_at', 'updated_at');
    }

    public function wherePivot(string $column, mixed $operator = null, mixed $value = null): self
    {
        // the raw query: both the single-parent and the eager constraints join the pivot table, so it can be
        // qualified now without applying the single-parent constraints early (that broke eager loading)
        $this->query->where($this->table . '.' . $column, $operator, ...(func_num_args() === 3 ? [$value] : []));

        return $this;
    }

    public function orderByPivot(string $column, string $direction = 'ASC'): self
    {
        $this->query->orderBy($this->table . '.' . $column, $direction);

        return $this;
    }

    protected function addConstraints(): void
    {
        $this->join($this->query);
        $this->query->where($this->table . '.' . $this->foreignPivotKey, $this->parent->getRawAttribute($this->parentKey));
    }

    public function addEagerConstraints(array $models): void
    {
        $this->join($this->query);
        $this->query->whereIn($this->table . '.' . $this->foreignPivotKey, self::keys($models, $this->parentKey));
    }

    private function join(ModelQuery $query): void
    {
        $query->join($this->table, $this->table . '.' . $this->relatedPivotKey, '=', self::qualify($this->getRelated(), $this->relatedKey));
    }

    protected function fetch(ModelQuery $query): ModelCollection
    {
        $columns = [$this->getRelated()->getTable() . '.*'];
        foreach ([$this->foreignPivotKey, $this->relatedPivotKey, ...$this->pivotColumns] as $column) {
            $columns[] = "{$this->table}.{$column} as pivot_{$column}";
        }
        $models = (clone $query)->select(...$columns)->get();
        foreach ($models as $model) {
            $model->setRelation('pivot', $model->takeAttributesPrefixed('pivot_'));
        }

        return $models;
    }

    public function match(array $models, ModelCollection $results, string $name): void
    {
        $groups = [];
        foreach ($results as $result) {
            $groups[(string) $result->pivot[$this->foreignPivotKey]][] = $result;
        }
        foreach ($models as $model) {
            $model->setRelation($name, new ModelCollection($groups[(string) $model->getRawAttribute($this->parentKey)] ?? []));
        }
    }

    public function getResults(): ModelCollection
    {
        return $this->parent->getRawAttribute($this->parentKey) === null ? new ModelCollection() : $this->get();
    }

    public function emptyValue(): mixed
    {
        return new ModelCollection();
    }

    public function existenceQuery(ModelQuery $sub): ModelQuery
    {
        $this->join($sub);

        return $sub->whereColumn($this->table . '.' . $this->foreignPivotKey, '=', self::qualify($this->parent, $this->parentKey));
    }

    // ── writing the pivot ────────────────────────────────────────────

    /**
     * attach(5), attach([5, 6]), attach($term), attach([5 => ['sort' => 1]]),
     * attach([5, 6], ['sort' => 0]).
     */
    public function attach(mixed $ids, array $attributes = []): void
    {
        $rows = [];
        foreach (self::normalize($ids) as $id => $extra) {
            $rows[] = $this->pivotRow($id, $extra + $attributes);
        }
        if ($rows !== []) {
            $this->pivot()->insertMany($rows);
        }
    }

    /** Remove some (or with no argument all) links. Returns how many. */
    public function detach(mixed $ids = null): int
    {
        $q = $this->pivot()->where($this->foreignPivotKey, $this->parentValue());
        if ($ids !== null) {
            $keys = array_keys(self::normalize($ids));
            if ($keys === []) {
                return 0;
            }
            $q->whereIn($this->relatedPivotKey, $keys);
        }

        return $q->delete();
    }

    /**
     * Make the links exactly $ids (with optional pivot attributes per id).
     *
     * @return array{attached: list<int|string>, detached: list<int|string>, updated: list<int|string>}
     */
    public function sync(mixed $ids, bool $detaching = true): array
    {
        $wanted = self::normalize($ids);
        $current = array_map('strval', $this->pivot()->where($this->foreignPivotKey, $this->parentValue())->pluck($this->relatedPivotKey));
        $out = ['attached' => [], 'detached' => [], 'updated' => []];

        if ($detaching) {
            $gone = array_values(array_diff($current, array_map('strval', array_keys($wanted))));
            if ($gone !== []) {
                $this->detach($gone);
                $out['detached'] = $gone;
            }
        }
        $attach = [];
        foreach ($wanted as $id => $extra) {
            if (!in_array((string) $id, $current, true)) {
                $attach[$id] = $extra;
                $out['attached'][] = $id;
            } elseif ($extra !== [] && $this->updateExistingPivot($id, $extra) > 0) {
                $out['updated'][] = $id;
            }
        }
        if ($attach !== []) {
            $this->attach($attach);
        }

        return $out;
    }

    /** Add links that aren't there yet; keep the rest. */
    public function syncWithoutDetaching(mixed $ids): array
    {
        return $this->sync($ids, false);
    }

    public function updateExistingPivot(int|string $id, array $attributes): int
    {
        if ($this->pivotTimestamps) {
            $attributes['updated_at'] = Caster::toDate(\Kestello\Support\Clock::now())->format('Y-m-d H:i:s');
        }

        return $this->pivot()->where($this->foreignPivotKey, $this->parentValue())->where($this->relatedPivotKey, $id)->update($attributes);
    }

    private function pivotRow(int|string $id, array $attributes): array
    {
        $row = [$this->foreignPivotKey => $this->parentValue(), $this->relatedPivotKey => $id] + $attributes;
        if ($this->pivotTimestamps) {
            $now = Caster::toDate(\Kestello\Support\Clock::now())->format('Y-m-d H:i:s');
            $row += ['created_at' => $now, 'updated_at' => $now];
        }

        return $row;
    }

    private function parentValue(): int|string
    {
        return $this->parent->getRawAttribute($this->parentKey) ?? throw new \LogicException('Save the parent ' . $this->parent::class . ' before linking related models.');
    }

    private function pivot(): QueryBuilder
    {
        return QueryBuilder::table($this->table, $this->parent->getConnection());
    }

    /** @return array<int|string, array<string, mixed>> id => pivot attributes */
    private static function normalize(mixed $ids): array
    {
        if ($ids instanceof Model) {
            return [$ids->getKey() => []];
        }
        if ($ids instanceof ModelCollection) {
            $ids = $ids->all();
        }
        if (!is_array($ids)) {
            return [$ids => []];
        }
        $out = [];
        foreach ($ids as $key => $value) {
            if ($value instanceof Model) {
                $out[$value->getKey()] = [];
            } elseif (is_array($value)) {
                $out[$key] = $value;
            } else {
                $out[$value] = [];
            }
        }

        return $out;
    }
}
