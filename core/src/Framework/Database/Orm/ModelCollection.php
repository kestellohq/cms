<?php

declare(strict_types=1);

namespace Kestello\Framework\Database\Orm;

use Kestello\Framework\Database\Model;
use Kestello\Support\Collection;

/**
 * The models a query returned.
 *
 * @template TModel of Model
 * @extends Collection<int, TModel>
 * @api
 */
class ModelCollection extends Collection
{
    /** Eager load relations onto every model: $posts->load('author', 'terms.taxonomy'). */
    public function load(string|array ...$relations): static
    {
        $models = array_values(array_filter($this->all(), static fn($m) => $m instanceof Model));
        if ($models !== []) {
            EagerLoader::load($models, EagerLoader::parse($relations));
        }

        return $this;
    }

    /** Like load(), skipping relations every model already has. */
    public function loadMissing(string|array ...$relations): static
    {
        $models = array_values(array_filter($this->all(), static fn($m) => $m instanceof Model));
        $wanted = EagerLoader::parse($relations);
        foreach (array_keys($wanted) as $name) {
            if (!str_contains($name, '.') && $models !== [] && array_filter($models, static fn(Model $m) => !$m->relationLoaded($name)) === []) {
                unset($wanted[$name]);
            }
        }
        if ($models !== [] && $wanted !== []) {
            EagerLoader::load($models, $wanted);
        }

        return $this;
    }

    /** @return list<int|string> */
    public function modelKeys(): array
    {
        return array_values(array_map(static fn(Model $m) => $m->getKey(), $this->all()));
    }

    /** The model with this key, if present. */
    public function findKey(int|string $key): ?Model
    {
        foreach ($this->all() as $model) {
            if ($model instanceof Model && (string) $model->getKey() === (string) $key) {
                return $model;
            }
        }

        return null;
    }

    /** @return list<mixed> */
    public function toArray(): array
    {
        return array_values(array_map(static fn($v) => $v instanceof Model || $v instanceof Collection ? $v->toArray() : $v, $this->all()));
    }
}
