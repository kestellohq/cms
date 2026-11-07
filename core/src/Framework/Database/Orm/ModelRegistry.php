<?php

declare(strict_types=1);

namespace Kestello\Framework\Database\Orm;

use Closure;
use InvalidArgumentException;
use Kestello\Framework\Core\ExecutionContext;
use Kestello\Framework\Database\Model;
use Kestello\Framework\Events\EventDispatcher;
use LogicException;

/**
 * Shared ORM state, exposed as $cms->models: model events, extensions,
 * the morph map and lazy-loading checks.
 *
 * @api
 */
final class ModelRegistry
{
    public const array EVENTS = [
        'creating' => Events\Creating::class, 'created' => Events\Created::class,
        'updating' => Events\Updating::class, 'updated' => Events\Updated::class,
        'saving' => Events\Saving::class, 'saved' => Events\Saved::class,
        'deleting' => Events\Deleting::class, 'deleted' => Events\Deleted::class,
        'restoring' => Events\Restoring::class, 'restored' => Events\Restored::class,
    ];

    private ?EventDispatcher $events = null;

    /** @var array<class-string, array<string, array<string, array{value: mixed, owner: string}>>> class => kind => name => entry */
    private array $claims = [];

    /** @var array<string, array<string, array<string, mixed>>> resolved per concrete class */
    private array $resolved = [];

    /** @var array<string, class-string<Model>> */
    private array $morphMap = [];

    /** @var array<string, string> */
    private array $morphOwners = [];

    /** @var (Closure(Model, string): void)|null */
    private ?Closure $lazyHandler = null;

    private bool $preventLazy = false;

    // ── events ───────────────────────────────────────────────────────

    public function setDispatcher(?EventDispatcher $events): void
    {
        $this->events = $events;
    }

    public function events(): EventDispatcher
    {
        return $this->events ??= new EventDispatcher();
    }

    /**
     * Listen to one model class (and its subclasses):
     * $cms->models->on(Entry::class, 'saving', fn(Saving $e) => ...).
     *
     * @param class-string<Model> $class
     */
    public function on(string $class, string $event, callable $listener, int $priority = 0): void
    {
        $eventClass = self::EVENTS[$event] ?? throw new InvalidArgumentException("Unknown model event [{$event}]; use one of: " . implode(', ', array_keys(self::EVENTS)) . '.');
        if (!is_a($class, Model::class, true)) {
            throw new InvalidArgumentException("[{$class}] is not a model.");
        }
        $this->events()->on($eventClass, static function (Events\ModelEvent $e) use ($class, $listener): void {
            if ($e->model instanceof $class) {
                $listener($e);
            }
        }, $priority);
    }

    /** @internal Model: dispatch and report whether the write may go ahead. */
    public function fire(Model $model, string $event): bool
    {
        if ($this->events === null) {
            return true;
        }
        $class = self::EVENTS[$event];
        if (!$this->events->hasListeners($class) && !$this->events->hasListeners(Events\ModelEvent::class) && !$this->events->hasListeners(Events\CancellableModelEvent::class)) {
            return true;
        }
        $e = $this->events->dispatch(new $class($model));

        return !($e instanceof Events\CancellableModelEvent && $e->cancelled());
    }

    // ── extensions ───────────────────────────────────────────────────

    /** @param class-string<Model> $class */
    public function extend(string $class): ModelExtension
    {
        if (!is_a($class, Model::class, true)) {
            throw new InvalidArgumentException("[{$class}] is not a model.");
        }

        return new ModelExtension($this, $class);
    }

    /** @internal ModelExtension */
    public function claim(string $class, string $kind, string $name, mixed $value): void
    {
        $pattern = $kind === 'cast' || $kind === 'fillable' ? '/^[a-z][a-z0-9_]{0,63}$/' : '/^[a-z][A-Za-z0-9_]{0,63}$/';
        if (preg_match($pattern, $name) !== 1) {
            throw new InvalidArgumentException("Invalid {$kind} name [{$name}] for {$class}.");
        }
        $owner = ExecutionContext::current();
        if ($kind !== 'fillable') {
            if (in_array($kind, ['relation', 'attribute'], true) && (method_exists($class, $name) || method_exists($class, 'get' . self::studly($name) . 'Attribute'))) {
                throw new LogicException("{$class} already has [{$name}]; {$owner} cannot add a {$kind} with that name.");
            }
            if ($kind === 'scope' && (method_exists($class, 'scope' . ucfirst($name)) || method_exists(ModelQuery::class, $name) || method_exists(\Kestello\Framework\Database\QueryBuilder::class, $name))) {
                throw new LogicException("[{$name}] is already a query method; {$owner} cannot add a scope with that name.");
            }
            foreach ($this->claims as $claimed => $kinds) {
                if (!is_a($class, $claimed, true) && !is_a($claimed, $class, true)) {
                    continue;
                }
                $taken = $kind === 'scope' || $kind === 'cast' ? ($kinds[$kind][$name] ?? null) : ($kinds['relation'][$name] ?? $kinds['attribute'][$name] ?? null);
                if ($taken !== null) {
                    throw new LogicException("{$class} {$kind} [{$name}] was already added by {$taken['owner']}.");
                }
            }
        }
        $this->claims[$class][$kind][$name] = ['value' => $value, 'owner' => $owner];
        $this->resolved = [];
        Model::clearCaches();
    }

    /**
     * @internal Model: every extension of this kind that applies to $class.
     * @return array<string, mixed>
     */
    public function extensions(string $class, string $kind): array
    {
        if (!isset($this->resolved[$class])) {
            $out = [];
            foreach ($this->claims as $claimed => $kinds) {
                if (is_a($class, $claimed, true)) {
                    foreach ($kinds as $k => $entries) {
                        foreach ($entries as $name => $entry) {
                            $out[$k][$name] = $entry['value'];
                        }
                    }
                }
            }
            $this->resolved[$class] = $out;
        }

        return $this->resolved[$class][$kind] ?? [];
    }

    // ── morph map ────────────────────────────────────────────────────

    /**
     * Name the models that polymorphic relations may point at. Only
     * mapped aliases are stored in *_type columns and resolved back, so
     * a database row can never name an arbitrary class. Plugin aliases
     * start with "<slug>.".
     *
     * @param array<string, class-string<Model>> $map
     */
    public function morphMap(array $map): void
    {
        $owner = ExecutionContext::current();
        foreach ($map as $alias => $class) {
            if (preg_match('/^[a-z][a-z0-9_]*(?:[.\/-][a-z0-9_]+)*$/', (string) $alias) !== 1 || strlen((string) $alias) > 64) {
                throw new InvalidArgumentException("Invalid morph alias [{$alias}].");
            }
            if (str_starts_with($owner, 'plugin:') && !str_starts_with((string) $alias, substr($owner, 7) . '.')) {
                throw new InvalidArgumentException("Morph alias [{$alias}] must start with \"" . substr($owner, 7) . '.".');
            }
            if (!is_a($class, Model::class, true)) {
                throw new InvalidArgumentException("[{$class}] is not a model.");
            }
            if (isset($this->morphMap[$alias]) && $this->morphMap[$alias] !== $class) {
                throw new LogicException("Morph alias [{$alias}] is already {$this->morphMap[$alias]} (registered by {$this->morphOwners[$alias]}).");
            }
            $this->morphMap[$alias] = $class;
            $this->morphOwners[$alias] = $owner;
        }
    }

    /** @return class-string<Model>|null */
    public function morphClass(string $alias): ?string
    {
        return $this->morphMap[$alias] ?? null;
    }

    public function morphAlias(string $class): ?string
    {
        $alias = array_search($class, $this->morphMap, true);

        return $alias === false ? null : (string) $alias;
    }

    // ── lazy loading ─────────────────────────────────────────────────

    /**
     * Called when a relation is lazy loaded on a model that came from a
     * multi-row result: the N+1 pattern. The kernel logs a warning in
     * debug mode.
     *
     * @param (Closure(Model, string): void)|null $handler
     */
    public function handleLazyLoadingWith(?Closure $handler): void
    {
        $this->lazyHandler = $handler;
    }

    /** Throw instead of warning (tests). */
    public function preventLazyLoading(bool $prevent = true): void
    {
        $this->preventLazy = $prevent;
    }

    /** @internal Model */
    public function lazyLoading(Model $model, string $relation): void
    {
        if ($this->preventLazy) {
            throw new LazyLoadingViolation(sprintf('Lazy loading [%s] on %s from a list of models: add ->with(\'%s\') to the query.', $relation, $model::class, $relation));
        }
        if ($this->lazyHandler !== null) {
            ($this->lazyHandler)($model, $relation);
        }
    }

    public static function studly(string $name): string
    {
        return str_replace(' ', '', ucwords(str_replace(['_', '-', '.'], ' ', $name)));
    }
}
