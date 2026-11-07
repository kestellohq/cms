<?php

declare(strict_types=1);

namespace Kestello\Framework\Database;

use BadMethodCallException;
use Closure;
use DateTimeZone;
use InvalidArgumentException;
use Kestello\Framework\Database\Orm\Caster;
use Kestello\Framework\Database\Orm\EagerLoader;
use Kestello\Framework\Database\Orm\MassAssignmentException;
use Kestello\Framework\Database\Orm\ModelCollection;
use Kestello\Framework\Database\Orm\ModelNotFoundException;
use Kestello\Framework\Database\Orm\ModelQuery;
use Kestello\Framework\Database\Orm\ModelRegistry;
use Kestello\Framework\Database\Orm\Relations;
use Kestello\Support\Clock;
use Kestello\Support\Str;
use LogicException;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * Active Record base class.
 *
 *   final class Entry extends Model
 *   {
 *       protected string $table = 'entries';
 *       protected array $fillable = ['title', 'slug', 'data'];
 *       protected array $casts = ['data' => 'array', 'published_at' => 'datetime'];
 *       protected bool $softDeletes = true;
 *
 *       public function author(): BelongsTo { return $this->belongsTo(User::class, 'author_id'); }
 *       public function terms(): BelongsToMany { return $this->belongsToMany(Term::class); }
 *       protected function scopePublished(ModelQuery $q): void { $q->where('status', 'published'); }
 *   }
 *
 *   Entry::query()->with('author')->published()->latest('published_at')->paginate(10);
 *
 * Safe defaults: mass assignment only touches $fillable columns (others
 * throw); polymorphic types are morph-map aliases, never class names;
 * models do not serialize to JSON by themselves (API Resources do).
 * Timestamps are UTC.
 *
 * @api
 */
abstract class Model
{
    public const string CREATED_AT = 'created_at';
    public const string UPDATED_AT = 'updated_at';
    public const string DELETED_AT = 'deleted_at';

    /** Table name; defaults to the snake_case plural of the class name. */
    protected string $table = '';

    protected string $primaryKey = 'id';

    /** "int" (auto-increment), "ulid" or "uuid" (generated on insert), or "string" (you set it). */
    protected string $keyType = 'int';

    /** @var list<string> columns fill()/create() may set. Anything else throws. */
    protected array $fillable = [];

    /** @var list<string> attributes and relations left out of toArray() */
    protected array $hidden = [];

    /** @var array<string, string> column => cast (see Orm\Caster) */
    protected array $casts = [];

    protected bool $timestamps = true;

    /** Soft deletes: delete() sets deleted_at; queries skip those rows. */
    protected bool $softDeletes = false;

    /** A named connection (DatabaseManager::addConnection), or the default. */
    protected ?string $connection = null;

    /** @var list<string> relations always eager loaded */
    protected array $with = [];

    /** @var array<string, mixed> raw database values */
    protected array $attributes = [];

    /** @var array<string, mixed> raw values as last read or saved */
    private array $original = [];

    /** @var array<string, mixed> columns written by the last save() */
    private array $changes = [];

    /** @var array<string, mixed> loaded relations */
    private array $relations = [];

    private bool $exists = false;
    private bool $recentlyCreated = false;
    private bool $forceDeleting = false;

    /** Loaded as part of a multi-row result (N+1 detection). */
    private bool $inBatch = false;

    private static ?ModelRegistry $registry = null;

    /** @var array<class-string, array<string, bool>> */
    private static array $relationMethods = [];

    /** @var array<class-string, array<string, string>> */
    private static array $castCache = [];

    public function __construct(array $attributes = [])
    {
        if ($attributes !== []) {
            $this->fill($attributes);
        }
    }

    // ── static entry points ──────────────────────────────────────────

    public static function registry(): ModelRegistry
    {
        return self::$registry ??= new ModelRegistry();
    }

    /** @internal tests */
    public static function resetRegistry(): void
    {
        self::$registry = null;
        self::clearCaches();
    }

    /** @internal ModelRegistry: extensions changed. */
    public static function clearCaches(): void
    {
        self::$castCache = [];
    }

    /** @return ModelQuery<static> */
    public static function query(): ModelQuery
    {
        return (new static())->newQuery();
    }

    public static function find(int|string $id): ?static
    {
        return static::query()->find($id);
    }

    public static function findOrFail(int|string $id): static
    {
        return static::query()->findOrFail($id);
    }

    /** @return ModelCollection<static> */
    public static function findMany(array $ids): ModelCollection
    {
        return static::query()->findMany($ids);
    }

    /** @return ModelCollection<static> */
    public static function all(): ModelCollection
    {
        return static::query()->get();
    }

    public static function where(string|Closure $column, mixed $operator = null, mixed $value = null): ModelQuery
    {
        return static::query()->where($column, ...array_slice(func_get_args(), 1));
    }

    public static function with(string|array ...$relations): ModelQuery
    {
        return static::query()->with(...$relations);
    }

    /** Mass-assign and save. */
    public static function create(array $attributes): static
    {
        $model = new static();
        $model->fill($attributes)->save();

        return $model;
    }

    /** Create without the fillable check: only for trusted data. */
    public static function forceCreate(array $attributes): static
    {
        $model = new static();
        $model->forceFill($attributes)->save();

        return $model;
    }

    /** Delete by key(s), one model at a time (events fire). Returns how many. */
    public static function destroy(int|string|array $ids): int
    {
        $count = 0;
        foreach (static::findMany((array) $ids) as $model) {
            $count += $model->delete() ? 1 : 0;
        }

        return $count;
    }

    /** Model::published() → Model::query()->published() (scopes and builder methods). */
    public static function __callStatic(string $method, array $arguments): mixed
    {
        return static::query()->{$method}(...$arguments);
    }

    // ── attributes ───────────────────────────────────────────────────

    /** Mass assignment: only $fillable columns (plus extension fillables); others throw. */
    public function fill(array $attributes): static
    {
        $allowed = $this->fillableColumns();
        foreach ($attributes as $key => $value) {
            if (!in_array($key, $allowed, true)) {
                throw new MassAssignmentException(sprintf('[%s] is not fillable on %s. Add it to $fillable, or set it explicitly.', $key, static::class));
            }
            $this->setAttribute((string) $key, $value);
        }

        return $this;
    }

    /** Set attributes without the fillable check: only for trusted data. */
    public function forceFill(array $attributes): static
    {
        foreach ($attributes as $key => $value) {
            $this->setAttribute((string) $key, $value);
        }

        return $this;
    }

    /** @return list<string> */
    public function fillableColumns(): array
    {
        return [...$this->fillable, ...array_keys(self::registry()->extensions(static::class, 'fillable'))];
    }

    public function getAttribute(string $key): mixed
    {
        $value = $this->attributes[$key] ?? null;
        $cast = $this->castType($key);
        if ($cast !== null) {
            $value = Caster::get($cast, $value, $this, $key, $this->castContext($key));
        }
        $accessor = 'get' . ModelRegistry::studly($key) . 'Attribute';
        if (method_exists($this, $accessor)) {
            return $this->{$accessor}($value);
        }

        return $value;
    }

    public function setAttribute(string $key, mixed $value): static
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key) !== 1) {
            throw new InvalidArgumentException("Invalid attribute name [{$key}].");
        }
        $mutator = 'set' . ModelRegistry::studly($key) . 'Attribute';
        if (method_exists($this, $mutator)) {
            $value = $this->{$mutator}($value);
        }
        $cast = $this->castType($key);
        $this->attributes[$key] = $cast !== null ? Caster::set($cast, $value, $this, $key, $this->castContext($key)) : $value;

        return $this;
    }

    /** The stored (uncast) value. */
    public function getRawAttribute(string $key): mixed
    {
        return $this->attributes[$key] ?? null;
    }

    /** @return array<string, mixed> raw values */
    public function getAttributes(): array
    {
        return $this->attributes;
    }

    /** Raw value(s) as last read or saved. */
    public function getOriginal(?string $key = null): mixed
    {
        return $key === null ? $this->original : ($this->original[$key] ?? null);
    }

    /** @return array<string, mixed> raw values that differ from the original */
    public function getDirty(): array
    {
        $dirty = [];
        foreach ($this->attributes as $key => $value) {
            if (!array_key_exists($key, $this->original) || !self::same($value, $this->original[$key])) {
                $dirty[$key] = $value;
            }
        }

        return $dirty;
    }

    public function isDirty(string ...$keys): bool
    {
        $dirty = $this->getDirty();

        return $keys === [] ? $dirty !== [] : array_intersect($keys, array_keys($dirty)) !== [];
    }

    public function isClean(string ...$keys): bool
    {
        return !$this->isDirty(...$keys);
    }

    /** Did the last save() write any of these columns (or any column)? */
    public function wasChanged(string ...$keys): bool
    {
        return $keys === [] ? $this->changes !== [] : array_intersect($keys, array_keys($this->changes)) !== [];
    }

    /** @return array<string, mixed> */
    public function getChanges(): array
    {
        return $this->changes;
    }

    /**
     * Cast PHP values to their stored form (bulk updates, lookups).
     *
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    public function castForStorage(array $values): array
    {
        $out = [];
        foreach ($values as $key => $value) {
            $cast = $this->castType((string) $key);
            $out[$key] = $cast !== null ? Caster::set($cast, $value, $this, (string) $key, $this->castContext((string) $key)) : $value;
        }

        return $out;
    }

    /** @return array<string, string> */
    public function getCasts(): array
    {
        return self::$castCache[static::class] ??= $this->buildCasts();
    }

    /** @return array<string, string> */
    private function buildCasts(): array
    {
        $casts = [];
        if ($this->keyType === 'int') {
            $casts[$this->primaryKey] = 'int';
        }
        if ($this->timestamps) {
            $casts += [self::CREATED_AT => 'datetime', self::UPDATED_AT => 'datetime'];
        }
        if ($this->softDeletes) {
            $casts += [self::DELETED_AT => 'datetime'];
        }
        $casts = array_merge($casts, $this->casts, self::registry()->extensions(static::class, 'cast'));
        foreach ($casts as $type) {
            Caster::validate($type);
        }

        return $casts;
    }

    private function castType(string $key): ?string
    {
        return $this->getCasts()[$key] ?? null;
    }

    private function castContext(string $key): string
    {
        return 'model:' . $this->getTable() . '.' . $key;
    }

    // ── persistence ──────────────────────────────────────────────────

    /** Insert or update. Returns false when a listener cancelled it. */
    public function save(): bool
    {
        if (!$this->fire('saving')) {
            return false;
        }
        $this->changes = [];

        if ($this->exists) {
            if ($this->isDirty()) {
                if (!$this->fire('updating')) {
                    return false;
                }
                if ($this->timestamps && !$this->isDirty(self::UPDATED_AT)) {
                    $this->attributes[self::UPDATED_AT] = self::freshTimestamp();
                }
                $dirty = $this->getDirty();
                $this->newBaseQuery()->where($this->primaryKey, $this->getRawAttribute($this->primaryKey))->update($dirty);
                $this->changes = $dirty;
                $this->fire('updated');
            }
        } else {
            if (!$this->fire('creating')) {
                return false;
            }
            if (($this->attributes[$this->primaryKey] ?? null) === null) {
                match ($this->keyType) {
                    'ulid' => $this->attributes[$this->primaryKey] = Str::ulid(),
                    'uuid' => $this->attributes[$this->primaryKey] = Str::uuid7(),
                    'string' => throw new LogicException(static::class . ' uses string keys: set ' . $this->primaryKey . ' before saving.'),
                    default => null,
                };
            }
            if ($this->timestamps) {
                $now = self::freshTimestamp();
                $this->attributes[self::CREATED_AT] ??= $now;
                $this->attributes[self::UPDATED_AT] ??= $now;
            }
            $data = $this->attributes;
            if ($this->keyType === 'int' && ($data[$this->primaryKey] ?? null) === null) {
                unset($data[$this->primaryKey]);
                $this->attributes[$this->primaryKey] = $this->newBaseQuery()->insert($data, $this->primaryKey);
            } else {
                $this->newBaseQuery()->insert($data, $this->primaryKey);
            }
            $this->exists = true;
            $this->recentlyCreated = true;
            $this->changes = $this->attributes;
            $this->fire('created');
        }

        $this->original = $this->attributes;
        $this->fire('saved');

        return true;
    }

    public function update(array $attributes): bool
    {
        return $this->fill($attributes)->save();
    }

    /** Delete (soft when the model uses soft deletes). False when cancelled or not saved. */
    public function delete(): bool
    {
        if (!$this->exists || !$this->fire('deleting')) {
            return false;
        }
        $query = $this->newBaseQuery()->where($this->primaryKey, $this->getRawAttribute($this->primaryKey));
        if ($this->softDeletes && !$this->forceDeleting) {
            $now = self::freshTimestamp();
            $query->update([self::DELETED_AT => $now]);
            $this->attributes[self::DELETED_AT] = $this->original[self::DELETED_AT] = $now;
        } else {
            $query->delete();
            $this->exists = false;
        }
        $this->fire('deleted');

        return true;
    }

    /** Delete the row even when the model uses soft deletes. */
    public function forceDelete(): bool
    {
        $this->forceDeleting = true;
        try {
            return $this->delete();
        } finally {
            $this->forceDeleting = false;
        }
    }

    public function isForceDeleting(): bool
    {
        return $this->forceDeleting || !$this->softDeletes;
    }

    public function restore(): bool
    {
        if (!$this->softDeletes || !$this->exists || !$this->fire('restoring')) {
            return false;
        }
        $this->attributes[self::DELETED_AT] = null;
        $saved = $this->save();
        if ($saved) {
            $this->fire('restored');
        }

        return $saved;
    }

    public function trashed(): bool
    {
        return $this->softDeletes && $this->getRawAttribute(self::DELETED_AT) !== null;
    }

    /** Bump updated_at. */
    public function touch(): bool
    {
        if (!$this->timestamps) {
            return false;
        }
        $this->attributes[self::UPDATED_AT] = self::freshTimestamp();

        return $this->save();
    }

    /** Reload attributes from the database (loaded relations are dropped). */
    public function refresh(): static
    {
        $row = $this->newQuery()->withTrashed()->toBase()->where($this->primaryKey, $this->getRawAttribute($this->primaryKey))->first()
            ?? throw new ModelNotFoundException(static::class . ' [' . $this->getKey() . '] no longer exists.');
        $this->attributes = $this->original = $row;
        $this->relations = [];

        return $this;
    }

    /** A new instance of this row from the database, or null. */
    public function fresh(): ?static
    {
        return $this->exists ? $this->newQuery()->withTrashed()->find($this->getKey()) : null;
    }

    public function exists(): bool
    {
        return $this->exists;
    }

    public function wasRecentlyCreated(): bool
    {
        return $this->recentlyCreated;
    }

    // ── keys, tables, connection ─────────────────────────────────────

    public function getKey(): int|string|null
    {
        $key = $this->attributes[$this->primaryKey] ?? null;

        return $key === null ? null : ($this->keyType === 'int' ? (int) $key : (string) $key);
    }

    /** @deprecated use getKey() */
    public function getId(): int|string|null
    {
        return $this->getKey();
    }

    public function getKeyName(): string
    {
        return $this->primaryKey;
    }

    public function getQualifiedKeyName(): string
    {
        return $this->getTable() . '.' . $this->primaryKey;
    }

    public function getTable(): string
    {
        if ($this->table !== '') {
            return $this->table;
        }

        return $this->table = Str::plural(Str::snake((new \ReflectionClass($this))->getShortName()));
    }

    /** Foreign key name other tables use for this model: user_id. */
    public function getForeignKey(): string
    {
        return Str::snake((new \ReflectionClass($this))->getShortName()) . '_' . $this->primaryKey;
    }

    public function getConnection(): Connection
    {
        return DatabaseManager::connection($this->connection);
    }

    public function usesTimestamps(): bool
    {
        return $this->timestamps;
    }

    public function usesSoftDeletes(): bool
    {
        return $this->softDeletes;
    }

    /** The morph-map alias stored in *_type columns. */
    public function getMorphClass(): string
    {
        return self::registry()->morphAlias(static::class)
            ?? throw new LogicException(static::class . ' has no morph alias; register one with $cms->models->morphMap([\'alias\' => ' . static::class . '::class]).');
    }

    /** Same row? */
    public function is(?Model $other): bool
    {
        return $other !== null && $other::class === static::class && $other->getKey() !== null && $other->getKey() === $this->getKey() && $other->getTable() === $this->getTable();
    }

    public static function freshTimestamp(): string
    {
        return Clock::now(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /** @return ModelQuery<static> */
    public function newQuery(): ModelQuery
    {
        $query = new ModelQuery($this, $this->newBaseQuery());

        return $this->with === [] ? $query : $query->with(...$this->with);
    }

    public function newBaseQuery(): QueryBuilder
    {
        return QueryBuilder::table($this->getTable(), $this->getConnection());
    }

    public function newInstance(array $attributes = []): static
    {
        $model = new static();

        return $attributes === [] ? $model : $model->forceFill($attributes);
    }

    /** @internal ModelQuery: a model for a fetched row. */
    public function newFromBuilder(array $row, bool $inBatch = false): static
    {
        $model = new static();
        $model->attributes = $model->original = $row;
        $model->exists = true;
        $model->inBatch = $inBatch;

        return $model;
    }

    /**
     * @internal BelongsToMany: move "pivot_*" columns out of the attributes.
     * @return array<string, mixed>
     */
    public function takeAttributesPrefixed(string $prefix): array
    {
        $taken = [];
        foreach ($this->attributes as $key => $value) {
            if (str_starts_with($key, $prefix)) {
                $taken[substr($key, strlen($prefix))] = $value;
                unset($this->attributes[$key], $this->original[$key]);
            }
        }

        return $taken;
    }

    // ── scopes ───────────────────────────────────────────────────────

    /** @internal ModelQuery */
    public function hasScope(string $name): bool
    {
        return method_exists($this, 'scope' . ucfirst($name)) || isset(self::registry()->extensions(static::class, 'scope')[$name]);
    }

    /** @internal ModelQuery */
    public function callScope(string $name, ModelQuery $query, array $arguments): mixed
    {
        $method = 'scope' . ucfirst($name);
        if (method_exists($this, $method)) {
            return $this->{$method}($query, ...$arguments);
        }

        return self::registry()->extensions(static::class, 'scope')[$name]($query, ...$arguments);
    }

    // ── relations ────────────────────────────────────────────────────

    /** The child holds the key: $post->author. */
    protected function belongsTo(string $related, ?string $foreignKey = null, ?string $ownerKey = null, ?string $name = null): Relations\BelongsTo
    {
        $name ??= self::callerRelationName(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2)[1]['function']);
        $instance = self::relatedInstance($related);

        return new Relations\BelongsTo($instance->newQuery(), $this, $foreignKey ?? Str::snake($name) . '_' . $instance->getKeyName(), $ownerKey ?? $instance->getKeyName(), $name);
    }

    /** One related row holds this model's key: $user->profile. */
    protected function hasOne(string $related, ?string $foreignKey = null, ?string $localKey = null): Relations\HasOne
    {
        return new Relations\HasOne(self::relatedInstance($related)->newQuery(), $this, $foreignKey ?? $this->getForeignKey(), $localKey ?? $this->primaryKey);
    }

    /** Related rows hold this model's key: $user->posts. */
    protected function hasMany(string $related, ?string $foreignKey = null, ?string $localKey = null): Relations\HasMany
    {
        return new Relations\HasMany(self::relatedInstance($related)->newQuery(), $this, $foreignKey ?? $this->getForeignKey(), $localKey ?? $this->primaryKey);
    }

    /** Through a pivot table (default: both singular table names, sorted, joined with "_"). */
    protected function belongsToMany(string $related, ?string $table = null, ?string $foreignPivotKey = null, ?string $relatedPivotKey = null, ?string $parentKey = null, ?string $relatedKey = null): Relations\BelongsToMany
    {
        $instance = self::relatedInstance($related);
        if ($table === null) {
            $names = [Str::snake((new \ReflectionClass($this))->getShortName()), Str::snake((new \ReflectionClass($instance))->getShortName())];
            sort($names);
            $table = implode('_', $names);
        }

        return new Relations\BelongsToMany($instance->newQuery(), $this, $table, $foreignPivotKey ?? $this->getForeignKey(), $relatedPivotKey ?? $instance->getForeignKey(), $parentKey ?? $this->primaryKey, $relatedKey ?? $instance->getKeyName());
    }

    /** Polymorphic child side: {name}_type (a morph alias) and {name}_id. */
    protected function morphTo(?string $name = null, ?string $typeColumn = null, ?string $idColumn = null): Relations\MorphTo
    {
        $name ??= self::callerRelationName(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2)[1]['function']);
        $typeColumn ??= Str::snake($name) . '_type';
        $idColumn ??= Str::snake($name) . '_id';
        $type = (string) $this->getRawAttribute($typeColumn);
        $class = $type === '' ? null : self::registry()->morphClass($type);
        $query = $class !== null ? (new $class())->newQuery() : $this->newQuery();

        return new Relations\MorphTo($query, $this, $typeColumn, $idColumn, $name, $class !== null);
    }

    protected function morphMany(string $related, string $name, ?string $localKey = null): Relations\MorphMany
    {
        return new Relations\MorphMany(self::relatedInstance($related)->newQuery(), $this, $name . '_type', $name . '_id', $localKey ?? $this->primaryKey);
    }

    protected function morphOne(string $related, string $name, ?string $localKey = null): Relations\MorphOne
    {
        return new Relations\MorphOne(self::relatedInstance($related)->newQuery(), $this, $name . '_type', $name . '_id', $localKey ?? $this->primaryKey);
    }

    private static function relatedInstance(string $class): Model
    {
        if (!is_a($class, self::class, true)) {
            throw new InvalidArgumentException("[{$class}] is not a model.");
        }

        return new $class();
    }

    /** The relation name a helper infers: its calling method, or the extension's name when called from its closure. */
    private static function callerRelationName(string $caller): string
    {
        return str_contains($caller, '{closure') && self::$definingRelation !== null ? self::$definingRelation : $caller;
    }

    /** The extension relation being defined right now, if any. */
    private static ?string $definingRelation = null;

    /**
     * The relation called $name: a public method declared to return a
     * Relation, or one added by an extension. Null when there is none.
     */
    public function relationNamed(string $name): ?Relations\Relation
    {
        if (self::isRelationMethod(static::class, $name)) {
            return $this->{$name}();
        }
        $definition = self::registry()->extensions(static::class, 'relation')[$name] ?? null;
        if ($definition === null) {
            return null;
        }
        // run in the model's scope so the definition can use hasOne(), belongsTo() and friends
        $bound = \Closure::bind($definition, (new \ReflectionFunction($definition))->getClosureThis(), static::class) ?? $definition;
        $outer = self::$definingRelation;
        self::$definingRelation = $name;      // belongsTo() and morphTo() take their default name from it, not from "{closure}"
        try {
            $relation = $bound($this);
        } finally {
            self::$definingRelation = $outer;
        }
        if (!$relation instanceof Relations\Relation) {
            throw new LogicException("The [{$name}] extension on " . static::class . ' must return a relation.');
        }

        return $relation;
    }

    private static function isRelationMethod(string $class, string $name): bool
    {
        if (isset(self::$relationMethods[$class][$name])) {
            return self::$relationMethods[$class][$name];
        }
        $ok = false;
        if (method_exists($class, $name)) {
            $m = new ReflectionMethod($class, $name);
            $type = $m->getReturnType();
            $ok = $m->isPublic() && !$m->isStatic() && $m->getNumberOfRequiredParameters() === 0
                && $m->getDeclaringClass()->getName() !== self::class
                && $type instanceof ReflectionNamedType && !$type->isBuiltin() && is_a($type->getName(), Relations\Relation::class, true);
        }

        return self::$relationMethods[$class][$name] = $ok;
    }

    public function relationLoaded(string $name): bool
    {
        return array_key_exists($name, $this->relations);
    }

    public function getRelation(string $name): mixed
    {
        return $this->relations[$name] ?? null;
    }

    /** @return array<string, mixed> */
    public function getRelations(): array
    {
        return $this->relations;
    }

    public function setRelation(string $name, mixed $value): static
    {
        $this->relations[$name] = $value;

        return $this;
    }

    public function unsetRelation(string $name): static
    {
        unset($this->relations[$name]);

        return $this;
    }

    /** Eager load onto this model: $post->load('comments.author'). */
    public function load(string|array ...$relations): static
    {
        EagerLoader::load([$this], EagerLoader::parse($relations));

        return $this;
    }

    public function loadMissing(string|array ...$relations): static
    {
        (new ModelCollection([$this]))->loadMissing(...$relations);

        return $this;
    }

    private function lazyLoad(string $name, Relations\Relation $relation): mixed
    {
        if ($this->inBatch) {
            self::registry()->lazyLoading($this, $name);
        }

        return $this->relations[$name] = $relation->getResults();
    }

    // ── output ───────────────────────────────────────────────────────

    /**
     * Attributes (cast; dates as ISO 8601) and loaded relations, minus
     * $hidden. Not an API format: API endpoints use Resources.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $out = [];
        foreach (array_keys($this->attributes) as $key) {
            if (!in_array($key, $this->hidden, true)) {
                $out[$key] = Caster::present($this->getAttribute($key));
            }
        }
        foreach ($this->relations as $name => $value) {
            if (in_array($name, $this->hidden, true)) {
                continue;
            }
            $out[$name] = $value instanceof self || $value instanceof ModelCollection ? $value->toArray() : $value;
        }

        return $out;
    }

    // ── magic ────────────────────────────────────────────────────────

    public function __get(string $name): mixed
    {
        if (array_key_exists($name, $this->attributes)) {
            return $this->getAttribute($name);
        }
        if (array_key_exists($name, $this->relations)) {
            return $this->relations[$name];
        }
        if (method_exists($this, 'get' . ModelRegistry::studly($name) . 'Attribute')) {
            return $this->getAttribute($name);
        }
        if (($relation = $this->relationNamed($name)) !== null) {
            return $this->lazyLoad($name, $relation);
        }
        $computed = self::registry()->extensions(static::class, 'attribute')[$name] ?? null;
        if ($computed !== null) {
            return $computed($this);
        }

        return $this->castType($name) !== null ? $this->getAttribute($name) : null;
    }

    public function __set(string $name, mixed $value): void
    {
        $this->setAttribute($name, $value);
    }

    public function __isset(string $name): bool
    {
        return isset($this->attributes[$name]) || isset($this->relations[$name])
            || ($this->relationNamed($name) !== null && $this->__get($name) !== null);
    }

    public function __unset(string $name): void
    {
        unset($this->attributes[$name], $this->relations[$name]);
    }

    /** Extension relations: $entry->seo() returns the relation. */
    public function __call(string $method, array $arguments): mixed
    {
        $definition = self::registry()->extensions(static::class, 'relation')[$method] ?? null;
        if ($definition !== null) {
            return $this->relationNamed($method);
        }

        throw new BadMethodCallException(sprintf('Call to undefined method %s::%s()', static::class, $method));
    }

    // ── internals ────────────────────────────────────────────────────

    private function fire(string $event): bool
    {
        return self::registry()->fire($this, $event);
    }

    private static function same(mixed $a, mixed $b): bool
    {
        if ($a === $b) {
            return true;
        }
        $norm = static fn($v) => is_bool($v) ? (string) (int) $v : (is_scalar($v) ? (string) $v : $v);

        return $a !== null && $b !== null && $norm($a) === $norm($b);
    }
}
