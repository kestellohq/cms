<?php

declare(strict_types=1);

namespace Kestello\Framework\Database\Orm;

use Closure;
use InvalidArgumentException;
use Kestello\Framework\Database\Model;

/**
 * Eager loading: one query per relation (per type for morphTo), however
 * many parents.
 *
 * @internal
 */
final class EagerLoader
{
    /**
     * with('author', 'terms.taxonomy', ['comments' => fn($q) => $q->where(...)])
     * → ['author' => null, 'terms' => null, 'terms.taxonomy' => null, 'comments' => Closure]
     *
     * @param array<int|string, mixed> $relations
     * @return array<string, Closure|null>
     */
    public static function parse(array $relations): array
    {
        $map = [];
        $add = static function (string $name, ?Closure $constraint) use (&$map): void {
            if (preg_match('/^[a-z][A-Za-z0-9_]*(\.[a-z][A-Za-z0-9_]*)*$/', $name) !== 1) {
                throw new InvalidArgumentException("Invalid relation name [{$name}].");
            }
            $parts = explode('.', $name);
            for ($i = 1; $i < count($parts); $i++) {
                $map[implode('.', array_slice($parts, 0, $i))] ??= null;
            }
            $map[$name] = $constraint ?? ($map[$name] ?? null);
        };
        foreach ($relations as $key => $value) {
            if (is_array($value)) {
                foreach ($value as $k => $v) {
                    is_string($k) ? $add($k, $v instanceof Closure ? $v : null) : $add((string) $v, null);
                }
            } elseif (is_string($key)) {
                $add($key, $value instanceof Closure ? $value : null);
            } else {
                $add((string) $value, null);
            }
        }

        return $map;
    }

    /**
     * @param list<Model> $models
     * @param array<string, Closure|null> $map
     */
    public static function load(array $models, array $map): void
    {
        if ($models === []) {
            return;
        }
        foreach ($map as $name => $constraint) {
            if (str_contains($name, '.')) {
                continue;
            }
            $nested = [];
            foreach ($map as $n => $c) {
                if (str_starts_with($n, $name . '.')) {
                    $nested[substr($n, strlen($name) + 1)] = $c;
                }
            }
            $relation = $models[0]->relationNamed($name)
                ?? throw new InvalidArgumentException(sprintf('Call to undefined relation [%s] on %s.', $name, $models[0]::class));
            $relation->eagerLoad($models, $name, $constraint, $nested);
        }
    }
}
