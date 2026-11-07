<?php

declare(strict_types=1);

namespace Kestello\Framework\Database\Orm;

use Closure;

/**
 * Extend a model without subclassing it (plugins, themes):
 *
 *   $cms->models->extend(Entry::class)
 *       ->relation('seo', fn(Entry $e) => $e->hasOne(SeoMeta::class, 'entry_id'))   // runs in the model's scope
 *       ->scope('featured', fn(ModelQuery $q) => $q->where('featured', true))
 *       ->attribute('reading_time', fn(Entry $e) => ...)
 *       ->cast('seo_score', 'int')
 *       ->fillable('seo_score');
 *
 * Names are claimed for the whole model: a second claim, or one that
 * clashes with the model's own method, throws and names the owner.
 * Extensions also apply to subclasses.
 *
 * @api
 */
final class ModelExtension
{
    public function __construct(
        private readonly ModelRegistry $registry,
        private readonly string $class,
    ) {}

    /** @param Closure(\Kestello\Framework\Database\Model): Relations\Relation $definition */
    public function relation(string $name, Closure $definition): self
    {
        $this->registry->claim($this->class, 'relation', $name, $definition);

        return $this;
    }

    /** @param Closure(ModelQuery, mixed ...): mixed $scope called as ->name(...$args) on queries */
    public function scope(string $name, Closure $scope): self
    {
        $this->registry->claim($this->class, 'scope', $name, $scope);

        return $this;
    }

    /** @param Closure(\Kestello\Framework\Database\Model): mixed $value a computed, read-only attribute */
    public function attribute(string $name, Closure $value): self
    {
        $this->registry->claim($this->class, 'attribute', $name, $value);

        return $this;
    }

    public function cast(string $column, string $type): self
    {
        Caster::validate($type);
        $this->registry->claim($this->class, 'cast', $column, $type);

        return $this;
    }

    public function fillable(string ...$columns): self
    {
        foreach ($columns as $column) {
            $this->registry->claim($this->class, 'fillable', $column, true);
        }

        return $this;
    }
}
