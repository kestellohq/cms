<?php

declare(strict_types=1);

namespace Kestello\Tests\Database;

use Kestello\Framework\Core\ExecutionContext;
use Kestello\Framework\Database\Blueprint;
use Kestello\Framework\Database\Connection;
use Kestello\Framework\Database\DatabaseManager;
use Kestello\Framework\Database\Model;
use Kestello\Framework\Database\Orm\Events\Creating;
use Kestello\Framework\Database\Orm\Events\Saving;
use Kestello\Framework\Database\Orm\LazyLoadingViolation;
use Kestello\Framework\Database\Orm\MassAssignmentException;
use Kestello\Framework\Database\Orm\ModelCollection;
use Kestello\Framework\Database\Orm\ModelNotFoundException;
use Kestello\Framework\Database\Orm\ModelQuery;
use Kestello\Framework\Database\Orm\Relations\BelongsTo;
use Kestello\Framework\Database\Orm\Relations\BelongsToMany;
use Kestello\Framework\Database\Orm\Relations\HasMany;
use Kestello\Framework\Database\Orm\Relations\HasOne;
use Kestello\Framework\Database\Orm\Relations\MorphMany;
use Kestello\Framework\Database\Orm\Relations\MorphTo;
use Kestello\Framework\Events\EventDispatcher;
use Kestello\Framework\Testing\TestCase;
use Kestello\Support\AppKey;
use Kestello\Support\Clock;

enum PostStatus: string
{
    case Draft = 'draft';
    case Live = 'live';
}

final class OUser extends Model
{
    protected string $table = 'o_users';
    protected array $fillable = ['name', 'email', 'settings'];
    protected array $hidden = ['secret'];
    protected array $casts = ['is_admin' => 'bool', 'settings' => 'array', 'secret' => 'encrypted'];

    public function posts(): HasMany
    {
        return $this->hasMany(OPost::class, 'author_id');
    }
    public function profile(): HasOne
    {
        return $this->hasOne(OProfile::class, 'user_id');
    }

    public function getDisplayNameAttribute(): string
    {
        return ucfirst((string) $this->name);
    }
    public function setEmailAttribute(?string $value): ?string
    {
        return $value === null ? null : strtolower($value);
    }

    /** Not a relation: never called through property access. */
    public function wipe(): bool
    {
        return true;
    }
}

final class OProfile extends Model
{
    protected string $table = 'o_profiles';
    protected bool $timestamps = false;
    protected array $fillable = ['bio'];
}

final class OPost extends Model
{
    protected string $table = 'o_posts';
    protected array $fillable = ['title', 'status', 'meta', 'published_at'];
    protected array $casts = ['status' => PostStatus::class, 'meta' => 'array', 'published_at' => 'datetime'];
    protected bool $softDeletes = true;

    public function author(): BelongsTo
    {
        return $this->belongsTo(OUser::class);
    }
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(OTag::class, 'o_post_tag', 'post_id', 'tag_id')->withPivot('sort')->withTimestamps();
    }
    public function comments(): MorphMany
    {
        return $this->morphMany(OComment::class, 'commentable');
    }

    protected function scopeLive(ModelQuery $q): void
    {
        $q->where('status', 'live');
    }
    protected function scopeTitled(ModelQuery $q, string $prefix): void
    {
        $q->whereLike('title', $prefix . '%');
    }
}

final class OTag extends Model
{
    protected string $table = 'o_tags';
    protected string $keyType = 'ulid';
    protected bool $timestamps = false;
    protected array $fillable = ['name'];

    public function posts(): BelongsToMany
    {
        return $this->belongsToMany(OPost::class, 'o_post_tag', 'tag_id', 'post_id');
    }
    public function comments(): MorphMany
    {
        return $this->morphMany(OComment::class, 'commentable');
    }
}

final class OComment extends Model
{
    protected string $table = 'o_comments';
    protected array $fillable = ['body'];

    public function commentable(): MorphTo
    {
        return $this->morphTo();
    }
}

/** ORM: casts, relations, eager loading, events, extensions (SQLite, plus MySQL/Postgres when configured). */
final class OrmTest extends TestCase
{
    private const array TABLES = ['o_post_tag', 'o_comments', 'o_profiles', 'o_tags', 'o_posts', 'o_users'];

    protected function setUp(): void
    {
        AppKey::set(bin2hex(random_bytes(32)));
        $this->freshRegistry();
    }

    private function freshRegistry(): void
    {
        Model::resetRegistry();
        Model::registry()->setDispatcher(new EventDispatcher());
        Model::registry()->morphMap(['o_post' => OPost::class, 'o_tag' => OTag::class]);
    }

    protected function tearDown(): void
    {
        Model::resetRegistry();
        DatabaseManager::reset();
        AppKey::clear();
        Clock::unfreeze();
    }

    /** @return array<string, Connection> */
    private function connections(): array
    {
        $connections = ['sqlite' => Connection::sqliteMemory()];
        $extra = json_decode((string) getenv('KESTELLO_TEST_DATABASES'), true);
        foreach (is_array($extra) ? $extra : [] as $config) {
            $c = new Connection($config);
            $connections[$c->driver()] = $c;
        }

        return $connections;
    }

    /** @param callable(string): void $test */
    private function each(callable $test): void
    {
        foreach ($this->connections() as $name => $c) {
            DatabaseManager::setConnection($c);
            $this->freshRegistry();
            $this->wipe($c);
            $this->schema($c);
            try {
                $test($name);
            } finally {
                $this->wipe($c);
            }
        }
    }

    private function wipe(Connection $c): void
    {
        $schema = $c->schema();
        $schema->withoutForeignKeyConstraints(function () use ($schema): void {
            foreach (self::TABLES as $t) {
                $schema->dropIfExists($t);
            }
        });
    }

    private function schema(Connection $c): void
    {
        $s = $c->schema();
        $s->create('o_users', function (Blueprint $t): void {
            $t->id();
            $t->string('name', 50);
            $t->string('email', 100)->nullable();
            $t->boolean('is_admin')->default(false);
            $t->json('settings')->nullable();
            $t->text('secret')->nullable();
            $t->timestamps();
        });
        $s->create('o_profiles', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('user_id');
            $t->string('bio', 200);
        });
        $s->create('o_posts', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('author_id')->nullable();
            $t->string('title', 100);
            $t->string('status', 10)->default('draft');
            $t->json('meta')->nullable();
            $t->datetime('published_at')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        $s->create('o_tags', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->string('name', 50);
        });
        $s->create('o_post_tag', function (Blueprint $t): void {
            $t->foreignId('post_id');
            $t->ulid('tag_id');
            $t->integer('sort')->default(0);
            $t->timestamps();
            $t->primary('post_id', 'tag_id');
        });
        $s->create('o_comments', function (Blueprint $t): void {
            $t->id();
            $t->string('commentable_type', 64);
            $t->string('commentable_id', 26);
            $t->string('body', 200);
            $t->timestamps();
        });
    }

    private function user(string $name, bool $admin = false): OUser
    {
        $u = OUser::create(['name' => $name, 'email' => strtoupper($name) . '@Example.test']);
        if ($admin) {
            $u->is_admin = true;
            $u->save();
        }

        return $u;
    }

    public function testCrudCastsAndDirtyTracking(): void
    {
        $this->each(function (string $db): void {
            Clock::freeze('2026-11-05 10:00:00 UTC');
            $u = OUser::create(['name' => 'ana', 'email' => 'ANA@Example.TEST', 'settings' => ['theme' => 'dark', 'n' => 2]]);
            $this->assertSame(1, $u->getKey(), $db);
            $this->assertTrue($u->wasRecentlyCreated(), $db);
            $this->assertSame('ana@example.test', $u->email, "{$db}: mutator");
            $this->assertSame('Ana', $u->display_name, "{$db}: accessor");
            $this->assertSame('2026-11-05 10:00:00', $u->created_at->format('Y-m-d H:i:s'), "{$db}: UTC timestamps");

            $u->secret = 'hunter2';
            $u->save();
            $raw = DatabaseManager::getConnection()->table('o_users')->where('id', 1)->value('secret');
            $this->assertFalse(str_contains((string) $raw, 'hunter2'), "{$db}: encrypted at rest");

            $fresh = OUser::findOrFail(1);
            $settings = $fresh->settings;
            ksort($settings);
            $this->assertSame(['n' => 2, 'theme' => 'dark'], $settings, "{$db}: JSON (MySQL reorders keys)");
            $this->assertSame(false, $fresh->is_admin, "{$db}: bool cast");
            $this->assertSame('hunter2', $fresh->secret, "{$db}: decrypted");
            $this->assertFalse(array_key_exists('secret', $fresh->toArray()), "{$db}: hidden");
            $this->assertSame('2026-11-05T10:00:00+00:00', $fresh->toArray()['created_at'], $db);

            $this->assertFalse($fresh->isDirty(), "{$db}: clean after load");
            $fresh->is_admin = false;
            $fresh->name = 'ana';
            $this->assertFalse($fresh->isDirty(), "{$db}: same values are not dirty");
            Clock::freeze('2026-11-05 11:00:00 UTC');
            $fresh->name = 'Ana B';
            $this->assertSame(['name'], array_keys($fresh->getDirty()), $db);
            $fresh->save();
            $this->assertTrue($fresh->wasChanged('name'), $db);
            $this->assertSame(['name', 'updated_at'], array_keys($fresh->getChanges()), "{$db}: only dirty columns written");
            $this->assertSame('11:00', $fresh->refresh()->updated_at->format('H:i'), $db);

            $p = OPost::create(['title' => 'Hi', 'status' => PostStatus::Live, 'published_at' => '2026-11-01 08:30:00']);
            $p = OPost::find($p->getKey());
            $this->assertSame(PostStatus::Live, $p->status, "{$db}: enum cast");
            $this->assertSame('2026-11-01 08:30', $p->published_at->format('Y-m-d H:i'), $db);
            $this->assertNull(OPost::find(999), $db);
            $this->assertThrows(ModelNotFoundException::class, fn() => OPost::findOrFail(999));

            $this->assertTrue($fresh->delete(), $db);
            $this->assertNull(OUser::find(1), $db);
            $this->assertFalse($fresh->exists(), $db);
        });
    }

    public function testMassAssignmentIsDeniedByDefault(): void
    {
        $this->each(function (string $db): void {
            $e = $this->assertThrows(MassAssignmentException::class, fn() => OUser::create(['name' => 'x', 'is_admin' => true]));
            $this->assertTrue(str_contains($e->getMessage(), '[is_admin] is not fillable'), $db);
            $this->assertSame(0, OUser::query()->count(), "{$db}: nothing written");
            $this->assertThrows(MassAssignmentException::class, fn() => (new OUser())->fill(['id' => 5]));

            $u = OUser::forceCreate(['name' => 'root', 'is_admin' => true]);
            $this->assertTrue(OUser::find($u->getKey())->is_admin, $db);
            $this->assertThrows(\InvalidArgumentException::class, fn() => (new OUser())->forceFill(['name; DROP' => 1]));
        });
    }

    public function testQueriesScopesSoftDeletesAndBulkWrites(): void
    {
        $this->each(function (string $db): void {
            foreach (['Alpha' => 'live', 'Beta' => 'draft', 'Gamma' => 'live', 'Delta' => 'live'] as $title => $status) {
                OPost::create(['title' => $title, 'status' => $status]);
            }
            $this->assertSame(['Alpha', 'Delta', 'Gamma'], OPost::live()->orderBy('title')->pluck('title'), "{$db}: static scope");
            $this->assertSame(['Gamma'], OPost::query()->live()->titled('G')->get()->pluck('title')->all(), "{$db}: scope with argument");
            $this->assertSame(['Beta'], OPost::query()->when(true, fn(ModelQuery $q) => $q->where('status', 'draft'))->pluck('title'), $db);

            $gamma = OPost::where('title', 'Gamma')->firstOrFail();
            $this->assertTrue($gamma->delete(), $db);
            $this->assertTrue($gamma->trashed(), $db);
            $this->assertSame(3, OPost::query()->count(), "{$db}: soft-deleted rows hidden");
            $this->assertSame(4, OPost::query()->withTrashed()->count(), $db);
            $this->assertSame(['Gamma'], OPost::query()->onlyTrashed()->pluck('title'), $db);
            $this->assertNull(OPost::find($gamma->getKey()), $db);
            $this->assertTrue($gamma->restore(), $db);
            $this->assertSame(4, OPost::query()->count(), $db);
            $this->assertTrue($gamma->forceDelete(), $db);
            $this->assertSame(3, OPost::query()->withTrashed()->count(), "{$db}: gone for good");

            $this->assertSame(2, OPost::live()->update(['status' => PostStatus::Draft]), "{$db}: bulk update casts values");
            $this->assertSame(3, OPost::where('status', 'draft')->count(), $db);
            $this->assertSame(1, OPost::where('title', 'Beta')->delete(), "{$db}: bulk soft delete");
            $this->assertSame(1, OPost::query()->onlyTrashed()->restore(), $db);

            $page = OPost::query()->orderBy('id')->paginate(2, 2);
            $this->assertSame([3, 2, 2], [$page['total'], $page['last_page'], $page['current_page']], $db);
            $this->assertInstanceOf(OPost::class, $page['data']->first());

            $seen = [];
            foreach (OPost::query()->lazy(2) as $post) {
                $seen[] = $post->title;
            }
            $this->assertCount(3, $seen, $db);

            $made = OPost::query()->firstOrCreate(['title' => 'Epsilon'], ['status' => 'live']);
            $again = OPost::query()->firstOrCreate(['title' => 'Epsilon']);
            $this->assertTrue($made->is($again), "{$db}: found, not duplicated");
            $this->assertThrows(\BadMethodCallException::class, fn() => OPost::query()->insert(['title' => 'x']));
            $this->assertThrows(\BadMethodCallException::class, fn() => OPost::query()->nope());
        });
    }

    public function testRelationsAndEagerLoading(): void
    {
        $this->each(function (string $db): void {
            $ana = $this->user('ana');
            $bo = $this->user('bo');
            $ana->profile()->create(['bio' => 'Writes things']);
            [$p1, $p2] = $ana->posts()->createMany([['title' => 'One'], ['title' => 'Two']]);
            $bo->posts()->create(['title' => 'Three']);
            $orphan = OPost::create(['title' => 'Orphan']);

            $this->assertSame('ana', $p1->author->name, "{$db}: belongsTo");
            $this->assertSame(['One', 'Two'], $ana->posts->pluck('title')->all(), "{$db}: hasMany");
            $this->assertSame('Writes things', $ana->profile->bio, "{$db}: hasOne");
            $this->assertNull($orphan->author, $db);
            $this->assertNull($bo->profile, $db);
            $this->assertSame(['Two'], $ana->posts()->where('title', 'Two')->get()->pluck('title')->all(), "{$db}: relation as query");
            $this->assertSame(2, $ana->posts()->count(), $db);

            $orphan->author()->associate($bo);
            $orphan->save();
            $this->assertSame($bo->getKey(), OPost::find($orphan->getKey())->author_id, $db);

            // Eager loading: one query per relation, whatever the number of parents.
            $queries = 0;
            $conn = DatabaseManager::getConnection();
            Model::registry()->preventLazyLoading();
            $users = OUser::with('posts', 'profile')->orderBy('id')->get();
            $this->assertSame([2, 2], $users->map(fn(OUser $u) => $u->posts->count())->all(), $db);
            $this->assertSame(['Writes things', null], $users->map(fn(OUser $u) => $u->profile?->bio)->all(), $db);
            $posts = OPost::with('author')->orderBy('id')->get();
            $this->assertSame(['ana', 'ana', 'bo', 'bo'], $posts->map(fn(OPost $p) => $p->author->name)->all(), $db);

            $this->assertThrows(LazyLoadingViolation::class, fn() => OPost::query()->get()->first()->author);
            $this->assertSame('ana', OPost::query()->first()->author->name, "{$db}: a single model may lazy load");
            Model::registry()->preventLazyLoading(false);

            // Nested and constrained eager loads.
            $users = OUser::with(['posts' => fn($q) => $q->where('title', '!=', 'Two')], 'posts.author')->orderBy('id')->get();
            $this->assertSame(['One'], $users->first()->posts->pluck('title')->all(), "{$db}: constrained");
            $this->assertSame('ana', $users->first()->posts->first()->author->name, "{$db}: nested");
            $loaded = OPost::query()->orderBy('id')->get()->load('author');
            $this->assertTrue($loaded->first()->relationLoaded('author'), $db);
            $this->assertSame(['id', 'name', 'email', 'is_admin', 'settings', 'created_at', 'updated_at', 'posts'], array_keys($users->first()->toArray()), "{$db}: relations in toArray, hidden left out");

            // whereHas / doesntHave
            $this->assertSame(['ana', 'bo'], OUser::query()->has('posts')->orderBy('id')->pluck('name'), $db);
            $this->assertSame(['ana'], OUser::query()->whereHas('posts', fn($q) => $q->where('title', 'Two'))->pluck('name'), $db);
            $this->assertSame(['bo'], OUser::query()->doesntHave('profile')->pluck('name'), $db);
            $p2->delete();
            $this->assertSame([], OUser::query()->whereHas('posts', fn($q) => $q->where('title', 'Two'))->pluck('name'), "{$db}: trashed related rows don't count");

            $this->assertThrows(\InvalidArgumentException::class, fn() => OUser::with('wipe')->get());
            $this->assertNull($ana->wipe, "{$db}: a non-relation method is never called through a property");
        });
    }

    public function testManyToManyPivotAndPolymorphicRelations(): void
    {
        $this->each(function (string $db): void {
            $post = OPost::create(['title' => 'Tagged']);
            $other = OPost::create(['title' => 'Other']);
            $php = OTag::create(['name' => 'php']);
            $sql = OTag::create(['name' => 'sql']);
            $vue = OTag::create(['name' => 'vue']);
            $this->assertSame(26, strlen((string) $php->getKey()), "{$db}: ULID key");

            $post->tags()->attach([$php->getKey() => ['sort' => 2], $sql->getKey() => ['sort' => 1]]);
            $other->tags()->attach($vue);
            $tags = $post->tags()->orderByPivot('sort')->get();
            $this->assertSame(['sql', 'php'], $tags->pluck('name')->all(), $db);
            $this->assertSame(1, (int) $tags->first()->pivot['sort'], "{$db}: pivot columns");
            $this->assertNotNull($tags->first()->pivot['created_at'], "{$db}: pivot timestamps");
            $this->assertFalse(array_key_exists('pivot_sort', $tags->first()->getAttributes()), $db);
            $this->assertSame(['Tagged'], $php->posts->pluck('title')->all(), "{$db}: inverse");

            $result = $post->tags()->sync([$sql->getKey() => ['sort' => 5], $vue->getKey()]);
            $this->assertSame([[$vue->getKey()], [(string) $php->getKey()], [$sql->getKey()]], [$result['attached'], $result['detached'], $result['updated']], $db);
            $posts = OPost::with('tags')->orderBy('id')->get();
            $this->assertSame([['sql', 'vue'], ['vue']], $posts->map(fn($p) => $p->tags->pluck('name')->sort()->values()->all())->all(), "{$db}: eager many-to-many");
            $this->assertSame(['Other', 'Tagged'], OPost::query()->whereHas('tags', fn($q) => $q->where('name', 'vue'))->orderBy('title')->pluck('title'), $db);
            $this->assertSame(2, $post->tags()->detach(), $db);

            // Polymorphic: comments on posts and tags.
            $post->comments()->create(['body' => 'Nice post']);
            $php->comments()->create(['body' => 'Nice tag']);
            $raw = DatabaseManager::getConnection()->table('o_comments')->orderBy('id')->pluck('commentable_type');
            $this->assertSame(['o_post', 'o_tag'], $raw, "{$db}: aliases stored, not class names");
            $comments = OComment::with('commentable')->orderBy('id')->get();
            $this->assertSame(['Tagged', 'php'], $comments->map(fn($c) => $c->commentable->title ?? $c->commentable->name)->all(), "{$db}: eager morphTo");
            $this->assertSame(['Nice post'], $post->comments->pluck('body')->all(), $db);

            // A row naming a class (or an unknown alias) resolves to nothing.
            DatabaseManager::getConnection()->table('o_comments')->insert(['commentable_type' => OUser::class, 'commentable_id' => '1', 'body' => 'forged', 'created_at' => null, 'updated_at' => null]);
            $forged = OComment::where('body', 'forged')->firstOrFail();
            $this->assertNull($forged->commentable, $db);
            $this->assertNull(OComment::with('commentable')->where('body', 'forged')->first()->commentable, $db);
            $this->assertThrows(\LogicException::class, fn() => (new OUser())->getMorphClass());
        });
    }

    public function testEventsCanObserveAndCancelWrites(): void
    {
        $this->each(function (string $db): void {
            $log = [];
            $events = Model::registry()->events();
            $events->on(Creating::class, function (Creating $e) use (&$log): void {
                $log[] = 'creating:' . $e->model::class;
            });
            Model::registry()->on(OPost::class, 'saving', function (Saving $e) use (&$log): void {
                $log[] = 'saving:' . $e->model->title;
                if ($e->model->title === 'spam') {
                    $e->cancel('no spam');
                }
            });
            Model::registry()->on(OPost::class, 'deleted', function ($e) use (&$log): void {
                $log[] = 'deleted:' . ExecutionContext::current();
            });

            $this->user('ana');
            $post = OPost::create(['title' => 'ok']);
            $spam = new OPost(['title' => 'spam']);
            $this->assertFalse($spam->save(), "{$db}: cancelled");
            $this->assertFalse($spam->exists(), $db);
            ExecutionContext::run('plugin:audit', fn() => Model::registry()->on(OPost::class, 'deleting', fn() => null));
            $post->delete();
            $this->assertSame(['creating:' . OUser::class, 'saving:ok', 'creating:' . OPost::class, 'saving:spam', 'deleted:core'], $log, $db);
            $this->assertThrows(\InvalidArgumentException::class, fn() => Model::registry()->on(OPost::class, 'exploded', fn() => null));
        });
    }

    public function testExtensionsAddRelationsScopesAttributesAndCasts(): void
    {
        $this->each(function (string $db): void {
            $models = Model::registry();
            ExecutionContext::run('plugin:seo', function () use ($models): void {
                $models->extend(OPost::class)
                    ->relation('writer', fn(OPost $p) => $p->author())
                    ->relation('byline', static fn(OPost $p) => $p->belongsTo(OUser::class, 'author_id'))   // protected helper, model scope
                    ->scope('titledLike', fn(ModelQuery $q, string $t) => $q->whereLike('title', "%{$t}%"))
                    ->attribute('shout', fn(OPost $p) => strtoupper((string) $p->title))
                    ->cast('author_id', 'string')
                    ->fillable('author_id');
            });
            $ana = $this->user('ana');
            $post = OPost::create(['title' => 'hello', 'author_id' => $ana->getKey()]);
            $this->assertSame('HELLO', $post->shout, $db);
            $this->assertSame('ana', $post->writer->name, "{$db}: extension relation");
            $this->assertInstanceOf(BelongsTo::class, $post->writer());
            $this->assertSame('ana', $post->byline->name, "{$db}: an extension relation built with the model's own helpers");
            $this->assertSame('ana', OPost::with('byline')->first()->byline->name, "{$db}: eager-loaded under the extension's name, not {closure}");
            $this->assertSame(['hello'], OPost::query()->titledLike('ell')->pluck('title'), "{$db}: extension scope");
            $this->assertSame('ana', OPost::with('writer')->first()->writer->name, "{$db}: eager extension relation");
            $this->assertSame((string) $ana->getKey(), OPost::query()->first()->author_id, "{$db}: extension cast");

            $e = $this->assertThrows(\LogicException::class, fn() => ExecutionContext::run('plugin:other', fn() => $models->extend(OPost::class)->relation('writer', fn($p) => $p->author())));
            $this->assertTrue(str_contains($e->getMessage(), 'plugin:seo'), "{$db}: names the owner");
            $this->assertThrows(\LogicException::class, fn() => $models->extend(OPost::class)->relation('author', fn($p) => $p->author()));
            $this->assertThrows(\LogicException::class, fn() => $models->extend(OPost::class)->scope('where', fn($q) => $q));
            $this->assertThrows(\InvalidArgumentException::class, fn() => $models->extend(OPost::class)->cast('x', 'nonsense'));
            $this->assertThrows(\InvalidArgumentException::class, fn() => ExecutionContext::run('plugin:seo', fn() => $models->morphMap(['page' => OPost::class])));
            ExecutionContext::run('plugin:seo', fn() => $models->morphMap(['seo.post' => OPost::class]));
            $this->assertSame(OPost::class, $models->morphClass('seo.post'));
        });
    }

    public function testCollectionsAndNamedConnections(): void
    {
        $this->each(function (string $db): void {
            $this->user('ana');
            $this->user('bo');
            $all = OUser::all();
            $this->assertInstanceOf(ModelCollection::class, $all);
            $this->assertSame([1, 2], $all->modelKeys(), $db);
            $this->assertSame('bo', $all->findKey(2)->name, $db);
            $this->assertSame(['ana', 'bo'], array_column($all->toArray(), 'name'), $db);
            $this->assertSame(1, OUser::destroy([2, 99]), $db);
        });

        DatabaseManager::setConnection(Connection::sqliteMemory());
        $external = Connection::sqliteMemory();
        $external->schema()->create('legacy', function (Blueprint $t): void {
            $t->id();
            $t->string('name', 20);
        });
        $external->table('legacy')->insert(['name' => 'old']);
        DatabaseManager::addConnection('legacy', static fn() => $external);
        $legacy = new class extends Model {
            protected string $table = 'legacy';
            protected ?string $connection = 'legacy';
            protected bool $timestamps = false;
        };
        $this->assertSame('old', $legacy::query()->first()->name);
        $this->assertThrows(\InvalidArgumentException::class, fn() => DatabaseManager::addConnection('Bad Name', $external));
        $this->assertThrows(\RuntimeException::class, fn() => DatabaseManager::connection('missing'));
    }
}
