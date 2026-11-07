<?php

declare(strict_types=1);

namespace Kestello\Tests\Framework;

use Kestello\Framework\Database\Blueprint;
use Kestello\Framework\Database\Connection;
use Kestello\Framework\Database\DatabaseManager;
use Kestello\Framework\Database\MigrationRunner;
use Kestello\Framework\Database\QueryBuilder;
use Kestello\Framework\Testing\TestCase;
use LogicException;

/**
 * Runs against in-memory SQLite, plus every database in the
 * KESTELLO_TEST_DATABASES env var (JSON list of connection configs), e.g.
 *
 *   KESTELLO_TEST_DATABASES='[{"driver":"mysql","host":"db","database":"kestello_test",...},
 *                            {"driver":"pgsql","host":"pgsql","database":"kestello_test",...}]'
 *
 * Those databases are wiped: never point this at real data.
 */
final class DatabaseTest extends TestCase
{
    private const array TABLES = ['t_comments', 't_posts', 't_tags', 't_kv', 't_renamed', 't_alter'];
    private const array CORE_TABLES = ['scheduled_tasks', 'failed_jobs', 'jobs', 'user_identities', 'audit_log', 'mfa_recovery_codes', 'mfa_methods', 'password_resets', 'sessions', 'cache', 'personal_access_tokens', 'packages', 'settings', 'users', 'migrations'];

    /** @return array<string, Connection> */
    private function connections(): array
    {
        $connections = ['sqlite' => Connection::sqliteMemory()];

        $extra = json_decode((string) getenv('KESTELLO_TEST_DATABASES'), true);
        foreach (is_array($extra) ? $extra : [] as $config) {
            $connection = new Connection($config);
            $connections[$connection->driver() . '@' . ($config['host'] ?? '')] = $connection;
        }

        return $connections;
    }

    /** @param callable(Connection, string): void $test */
    private function each(callable $test): void
    {
        foreach ($this->connections() as $name => $connection) {
            $this->wipe($connection, self::TABLES);
            $this->createFixtures($connection);
            $test($connection, $name);
            $this->wipe($connection, self::TABLES);
        }
    }

    /** @param list<string> $tables */
    private function wipe(Connection $c, array $tables): void
    {
        $schema = $c->schema();
        $schema->withoutForeignKeyConstraints(function () use ($schema, $tables): void {
            foreach ($tables as $table) {
                $schema->dropIfExists($table);
            }
        });
    }

    private function createFixtures(Connection $c): void
    {
        $schema = $c->schema();
        $schema->create('t_posts', function (Blueprint $t): void {
            $t->id();
            $t->string('title', 100);
            $t->string('slug', 100)->unique();
            $t->text('body')->nullable();
            $t->boolean('published')->default(false);
            $t->integer('views')->default(0);
            $t->decimal('rating', 4, 2)->nullable();
            $t->json('meta')->nullable();
            $t->enum('status', ['draft', 'live'])->default('draft');
            $t->timestamps();
            $t->index('published', 'created_at');
        });
        $schema->create('t_comments', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('post_id')->constrained('t_posts')->cascadeOnDelete();
            $t->string('author', 50);
        });
        $schema->create('t_kv', function (Blueprint $t): void {
            $t->string('key', 50)->primary();
            $t->text('value')->nullable();
        });
    }

    // ── Schema ──────────────────────────────────────────────────────

    public function testSchemaCreateIntrospectAlterRenameDrop(): void
    {
        $this->each(function (Connection $c, string $name): void {
            $schema = $c->schema();
            $this->assertTrue($schema->hasTable('t_posts'), $name);
            $this->assertFalse($schema->hasTable('t_nope'), $name);
            $this->assertSame(['id', 'title', 'slug', 'body', 'published', 'views', 'rating', 'meta', 'status', 'created_at', 'updated_at'], $schema->columns('t_posts'), $name);

            $schema->create('t_alter', function (Blueprint $t): void {
                $t->id();
                $t->string('a', 20);
            });
            $schema->table('t_alter', function (Blueprint $t): void {
                $t->string('b', 20)->nullable()->unique();
                $t->boolean('flag')->default(true);
                $t->renameColumn('a', 'aa');
            });
            $this->assertSame(['id', 'aa', 'b', 'flag'], $schema->columns('t_alter'), $name);

            $c->table('t_alter')->insert(['aa' => 'x', 'b' => 'same']);
            $this->assertThrows(\PDOException::class, fn() => $c->table('t_alter')->insert(['aa' => 'y', 'b' => 'same']), "{$name}: unique added by alter");
            $this->assertSame(1, (int) $c->table('t_alter')->value('flag'), "{$name}: boolean default");

            $schema->table('t_alter', function (Blueprint $t): void {
                $t->dropUnique('b');
                $t->dropColumn('b');
            });
            $this->assertFalse($schema->hasColumn('t_alter', 'b'), $name);

            $schema->rename('t_alter', 't_renamed');
            $this->assertTrue($schema->hasTable('t_renamed'), $name);
            $schema->drop('t_renamed');
            $this->assertFalse($schema->hasTable('t_renamed'), $name);
        });
    }

    public function testForeignKeysCascadeAndAreEnforced(): void
    {
        $this->each(function (Connection $c, string $name): void {
            $post = $c->table('t_posts')->insert(['title' => 'A', 'slug' => 'a']);
            $c->table('t_comments')->insert(['post_id' => $post, 'author' => 'x']);

            $this->assertThrows(\PDOException::class, fn() => $c->table('t_comments')->insert(['post_id' => 999, 'author' => 'y']), $name);

            $c->table('t_posts')->where('id', $post)->delete();
            $this->assertSame(0, $c->table('t_comments')->count(), "{$name}: cascade");
        });
    }

    // ── Queries ─────────────────────────────────────────────────────

    public function testInsertReturnsIdsAndTypesRoundTrip(): void
    {
        $this->each(function (Connection $c, string $name): void {
            $first = $c->table('t_posts')->insert(['title' => 'One', 'slug' => 'one', 'published' => true, 'meta' => json_encode(['a' => 1]), 'rating' => 4.5, 'created_at' => new \DateTimeImmutable('2026-01-02 03:04:05')]);
            $second = $c->table('t_posts')->insert(['title' => 'Two', 'slug' => 'two']);

            $this->assertTrue($first > 0 && $second === $first + 1, "{$name}: ids {$first}, {$second}");
            $this->assertSame(0, $c->table('t_kv')->insert(['key' => 'k', 'value' => 'v']), "{$name}: table without id");

            $row = $c->table('t_posts')->find($first);
            $this->assertTrue((bool) $row['published'], $name);
            $this->assertSame(['a' => 1], json_decode((string) $row['meta'], true), $name);
            $this->assertSame('2026-01-02 03:04:05', substr((string) $row['created_at'], 0, 19), $name);
            $this->assertSame('draft', $row['status'], "{$name}: enum default");
            $this->assertSame(1, $c->table('t_posts')->where('published', true)->count(), "{$name}: bool binding");
            $this->assertSame(1, $c->table('t_posts')->where('published', false)->count(), $name);

            $this->assertThrows(\PDOException::class, fn() => $c->table('t_posts')->insert(['title' => 'x', 'slug' => 'x', 'status' => 'bogus']), "{$name}: enum check");
        });
    }

    public function testWheresNestingAndOperators(): void
    {
        $this->each(function (Connection $c, string $name): void {
            $this->seedPosts($c);
            $posts = fn() => $c->table('t_posts');

            $this->assertSame(['b', 'c'], $posts()->where('views', '>=', 20)->orderBy('slug')->pluck('slug'), $name);
            $this->assertSame(['a', 'c'], $posts()->where('published', true)
                ->where(fn($q) => $q->where('views', '<', 15)->orWhere('views', '>', 25))->orderBy('slug')->pluck('slug'), "{$name}: nested");
            $this->assertSame(['a', 'c'], $posts()->whereIn('slug', ['a', 'c', 'zz'])->orderBy('slug')->pluck('slug'), $name);
            $this->assertSame([], $posts()->whereIn('slug', [])->pluck('slug'), "{$name}: empty IN matches nothing");
            $this->assertSame(3, $posts()->whereNotIn('slug', [])->count(), "{$name}: empty NOT IN matches all");
            $this->assertSame(['b'], $posts()->whereBetween('views', 15, 25)->pluck('slug'), $name);
            $this->assertSame(['b'], $posts()->whereNull('body')->pluck('slug'), $name);
            $this->assertSame(['b'], $posts()->where('body', null)->pluck('slug'), "{$name}: where null value");
            $this->assertSame(2, $posts()->whereNotNull('body')->count(), $name);
            $c->table('t_comments')->insert(['post_id' => $posts()->where('slug', 'c')->value('id'), 'author' => 'z']);
            $this->assertSame(['c'], $posts()->whereExists(fn($q) => $q->from('t_comments')->whereColumn('t_comments.post_id', '=', 't_posts.id'))->pluck('slug'), "{$name}: exists");
        });
    }

    public function testLikeIsEscapedAndCaseInsensitive(): void
    {
        $this->each(function (Connection $c, string $name): void {
            $c->table('t_posts')->insertMany([
                ['title' => '100% Off', 'slug' => 's1'],
                ['title' => '1000 Offers', 'slug' => 's2'],
                ['title' => 'snake_case', 'slug' => 's3'],
                ['title' => 'snakeXcase', 'slug' => 's4'],
            ]);

            $this->assertSame(['s1'], $c->table('t_posts')->whereLike('title', QueryBuilder::escapeLike('100%') . '%')->pluck('slug'), "{$name}: % literal");
            $this->assertSame(['s3'], $c->table('t_posts')->where('title', 'LIKE', QueryBuilder::escapeLike('snake_') . '%')->pluck('slug'), "{$name}: _ literal");
            $this->assertSame(['s1', 's2'], $c->table('t_posts')->whereLike('title', '%OFF%')->orderBy('slug')->pluck('slug'), "{$name}: case-insensitive");
        });
    }

    public function testAggregatesPluckChunkPaginate(): void
    {
        $this->each(function (Connection $c, string $name): void {
            $this->seedPosts($c);
            $posts = fn() => $c->table('t_posts');

            $this->assertSame(60, (int) $posts()->sum('views'), $name);
            $this->assertSame(30, (int) $posts()->max('views'), $name);
            $this->assertSame(20.0, $posts()->avg('views'), $name);
            $this->assertTrue($posts()->where('slug', 'a')->exists(), $name);
            $this->assertSame(['a' => 'A', 'b' => 'B', 'c' => 'C'], $posts()->orderBy('slug')->pluck('title', 'slug'), $name);

            $seen = [];
            $posts()->chunkById(2, function (array $rows) use (&$seen): void {
                foreach ($rows as $r) {
                    $seen[] = $r['slug'];
                }
            });
            $this->assertSame(['a', 'b', 'c'], $seen, "{$name}: chunkById");
            $this->assertSame(['a', 'b', 'c'], array_column(iterator_to_array($posts()->lazyById(2), false), 'slug'), "{$name}: lazyById");

            $page = $posts()->orderBy('slug')->paginate(2, 2);
            $this->assertSame(['c'], array_column($page['data'], 'slug'), $name);
            $this->assertSame([3, 2], [$page['total'], $page['last_page']], $name);
            $this->assertSame(['b', 'c'], $posts()->orderBy('slug')->offset(1)->pluck('slug'), "{$name}: offset without limit");
        });
    }

    public function testUpdatesIncrementsUpsertTruncate(): void
    {
        $this->each(function (Connection $c, string $name): void {
            $this->seedPosts($c);

            $this->assertSame(1, $c->table('t_posts')->where('slug', 'a')->increment('views', 5, ['title' => 'A+']), $name);
            $this->assertSame(15, (int) $c->table('t_posts')->where('slug', 'a')->value('views'), $name);
            $c->table('t_posts')->where('slug', 'a')->decrement('views');
            $this->assertSame(14, (int) $c->table('t_posts')->where('slug', 'a')->value('views'), $name);

            $c->table('t_kv')->upsert([['key' => 'a', 'value' => '1'], ['key' => 'b', 'value' => '2']], ['key']);
            $c->table('t_kv')->upsert([['key' => 'a', 'value' => 'updated'], ['key' => 'c', 'value' => '3']], ['key']);
            $this->assertSame(['a' => 'updated', 'b' => '2', 'c' => '3'], $c->table('t_kv')->orderBy('key')->pluck('value', 'key'), "{$name}: upsert");

            $c->table('t_kv')->upsert(['key' => 'a', 'value' => 'ignored'], ['key'], update: []);
            $this->assertSame('updated', $c->table('t_kv')->where('key', 'a')->value('value'), "{$name}: upsert do-nothing");

            // Truncate a table nothing references (Postgres and MySQL refuse to
            // truncate a referenced table; that's intended).
            $post = (int) $c->table('t_posts')->where('slug', 'a')->value('id');
            $c->table('t_comments')->insertMany([['post_id' => $post, 'author' => 'x'], ['post_id' => $post, 'author' => 'y']]);
            $c->table('t_comments')->truncate();
            $this->assertSame(0, $c->table('t_comments')->count(), $name);
            $this->assertSame(1, $c->table('t_comments')->insert(['post_id' => $post, 'author' => 'z']), "{$name}: ids restart after truncate");
        });
    }

    public function testNestedTransactionsUseSavepoints(): void
    {
        $this->each(function (Connection $c, string $name): void {
            $c->transaction(function (Connection $c): void {
                $c->table('t_kv')->insert(['key' => 'outer', 'value' => '1']);
                try {
                    $c->transaction(function (Connection $c): void {
                        $c->table('t_kv')->insert(['key' => 'inner', 'value' => '1']);
                        throw new \RuntimeException('rollback inner');
                    });
                } catch (\RuntimeException) {
                }
            });

            $this->assertSame(['outer'], $c->table('t_kv')->pluck('key'), $name);
            $this->assertSame(0, $c->transactionLevel(), $name);

            try {
                $c->transaction(function (Connection $c): void {
                    $c->table('t_kv')->insert(['key' => 'gone', 'value' => '1']);
                    throw new \RuntimeException('rollback all');
                });
            } catch (\RuntimeException) {
            }
            $this->assertSame(['outer'], $c->table('t_kv')->pluck('key'), $name);
        });
    }

    public function testAFailingMigrationLeavesNothingWhereTableChangesAreTransactional(): void
    {
        $dir = $this->tempDir() . '/migrations';
        mkdir($dir);
        file_put_contents($dir . '/2026_01_01_000001_half.php', "<?php\nreturn new class extends \\Kestello\\Framework\\Database\\Migration {\n    public function up(): void { \$this->schema()->create('t_half', fn(\\Kestello\\Framework\\Database\\Blueprint \$t) => \$t->id()); throw new \\RuntimeException('second step failed'); }\n    public function down(): void {}\n};\n");
        foreach ($this->connections() as $name => $c) {
            if (!in_array($c->driver(), ['sqlite', 'pgsql'], true)) {
                continue;       // MySQL commits table changes one by one
            }
            $c->schema()->dropIfExists('t_half');
            $previous = DatabaseManager::hasConnection() ? DatabaseManager::getConnection() : null;
            DatabaseManager::setConnection($c);
            try {
                $this->assertThrows(\RuntimeException::class, fn() => (new MigrationRunner($dir, 'test'))->migrate());
                $c->forgetColumns('t_half');
                $this->assertFalse($c->schema()->hasTable('t_half'), "{$name}: the half-made table is gone");
            } finally {
                $c->table('migrations')->where('owner', 'test')->delete();
                $previous !== null && DatabaseManager::setConnection($previous);
            }
        }
    }

    public function testCoreMigrationsRunOnEveryDialect(): void
    {
        foreach ($this->connections() as $name => $c) {
            $this->wipe($c, self::CORE_TABLES);
            $previous = DatabaseManager::hasConnection() ? DatabaseManager::getConnection() : null;
            DatabaseManager::setConnection($c);

            try {
                $runner = new MigrationRunner(dirname(__DIR__, 2) . '/core/database/migrations');
                $ran = $runner->migrate();
                $this->assertTrue(count($ran) >= 5, "{$name}: migrations ran");

                $schema = $c->schema();
                $this->assertTrue($schema->hasTable('personal_access_tokens'), $name);
                $this->assertFalse($schema->hasColumn('users', 'api_token'), "{$name}: api_token dropped again");

                $user = $c->table('users')->insert(['username' => 'u', 'email' => 'u@x.test', 'password' => 'h', 'role' => 'admin', 'is_active' => true]);
                $this->assertSame(1, $user, $name);

                $this->assertSame(count($ran), count($runner->rollback(100)), "{$name}: everything rolls back");
                $this->assertFalse($schema->hasTable('users'), $name);
            } finally {
                $this->wipe($c, self::CORE_TABLES);
                $previous !== null ? DatabaseManager::setConnection($previous) : DatabaseManager::reset();
            }
        }
    }

    public function testSqliteRefusesForeignKeysOnExistingTables(): void
    {
        $c = Connection::sqliteMemory();
        $c->schema()->create('t_a', fn(Blueprint $t) => $t->id());
        $c->schema()->create('t_b', fn(Blueprint $t) => $t->id());

        $this->assertThrows(LogicException::class, fn() => $c->schema()->table('t_b', fn(Blueprint $t) => $t->foreignId('a_id')->constrained('t_a')));
    }

    // ── Compiled SQL per dialect ────────────────────────────────────

    public function testDialectsCompileExpectedSql(): void
    {
        $q = fn(string $driver) => QueryBuilder::table('settings', new Connection(['driver' => $driver]))
            ->select('key', 'value as v')->where('key', 'LIKE', 'a%')->whereLike('value', '%x%')->orderByDesc('id')->limit(5)->offset(10);

        $this->assertSame('SELECT `key`, `value` AS `v` FROM `settings` WHERE `key` LIKE ? AND LOWER(`value`) LIKE LOWER(?) ORDER BY `id` DESC LIMIT 5 OFFSET 10', $q('mysql')->toSql());
        $this->assertSame('SELECT "key", "value" AS "v" FROM "settings" WHERE "key" LIKE ? AND "value" ILIKE ? ORDER BY "id" DESC LIMIT 5 OFFSET 10', $q('pgsql')->toSql());
        $this->assertSame("SELECT \"key\", \"value\" AS \"v\" FROM \"settings\" WHERE \"key\" LIKE ? ESCAPE '\\' AND LOWER(\"value\") LIKE LOWER(?) ESCAPE '\\' ORDER BY \"id\" DESC LIMIT 5 OFFSET 10", $q('sqlite')->toSql());

        $blueprint = new Blueprint('t');
        $blueprint->id();
        $blueprint->boolean('on')->default(true);
        $blueprint->json('data')->nullable();

        $this->assertContains('`id` BIGINT UNSIGNED AUTO_INCREMENT NOT NULL', (new Connection(['driver' => 'mysql']))->schemaGrammar()->compileCreate($blueprint)[0]);
        $this->assertContains('"on" BOOLEAN NOT NULL DEFAULT TRUE', (new Connection(['driver' => 'pgsql']))->schemaGrammar()->compileCreate($blueprint)[0]);
        $this->assertContains('"data" JSONB NULL', (new Connection(['driver' => 'pgsql']))->schemaGrammar()->compileCreate($blueprint)[0]);
        $this->assertContains('"id" INTEGER PRIMARY KEY AUTOINCREMENT', (new Connection(['driver' => 'sqlite']))->schemaGrammar()->compileCreate($blueprint)[0]);
    }

    public function testFrameworkConditionsAreNotSwallowedByOr(): void
    {
        $this->each(function (Connection $c, string $name): void {
            $this->seedPosts($c);
            $q = static fn() => QueryBuilder::table('t_posts', $c)->where('slug', 'a')->orWhere('slug', 'c');
            $b = QueryBuilder::table('t_posts', $c)->where('slug', 'b')->value('id');
            $this->assertNull($q()->find($b), "{$name}: find() keeps the OR inside its group");
            $seen = 0;
            $q()->chunkById(1, function (array $rows) use (&$seen): void {
                $seen += count($rows);
            });
            $this->assertSame(2, $seen, "{$name}: chunkById ends");
            $this->assertSame(2, iterator_count($q()->lazyById(1)), "{$name}: lazyById ends");
        });
    }

    public function testTwoArgumentOrWhereNeverReadsTheValueAsAnOperator(): void
    {
        $this->each(function (Connection $c, string $name): void {
            $this->seedPosts($c);
            foreach (['!=', '=', 'LIKE', '<>'] as $value) {
                $rows = QueryBuilder::table('t_posts', $c)->where('slug', 'none')->orWhere('slug', $value)->get();
                $this->assertSame([], $rows, "{$name}: orWhere('slug', '{$value}')");
            }
        });
    }

    public function testCountsRespectGroupsAndDistinct(): void
    {
        $this->each(function (Connection $c, string $name): void {
            $this->seedPosts($c);
            $this->assertSame(2, (int) QueryBuilder::table('t_posts', $c)->select('published')->groupBy('published')->count(), "{$name}: groups");
            $this->assertSame(2, (int) QueryBuilder::table('t_posts', $c)->select('published')->distinct()->count(), "{$name}: distinct");
            $page = QueryBuilder::table('t_posts', $c)->select('published')->groupBy('published')->paginate(10);
            $this->assertSame(2, $page['total'], "{$name}: paginate total");
            $this->assertSame(3, (int) QueryBuilder::table('t_posts', $c)->count(), "{$name}: plain");
        });
    }

    public function testARefusedCommitLeavesNoOpenTransaction(): void
    {
        $c = Connection::sqliteMemory();
        $c->raw('PRAGMA foreign_keys = ON');
        $c->raw('CREATE TABLE p (id INTEGER PRIMARY KEY)');
        $c->raw('CREATE TABLE ch (id INTEGER PRIMARY KEY, p_id INTEGER REFERENCES p(id) DEFERRABLE INITIALLY DEFERRED)');
        $this->assertThrows(\PDOException::class, fn() => $c->transaction(fn(Connection $c) => $c->raw('INSERT INTO ch (p_id) VALUES (99)')));
        $this->assertSame(0, $c->transactionLevel());
        $this->assertFalse($c->getPdo()->inTransaction());
        $c->transaction(fn(Connection $c) => $c->raw('INSERT INTO p (id) VALUES (1)'));
        $this->assertSame(1, (int) $c->table('p')->count(), 'the next transaction works');
    }

    public function testDatesAreBoundAsUtc(): void
    {
        $c = Connection::sqliteMemory();
        $c->raw('CREATE TABLE d (at TEXT)');
        $c->table('d')->insert(['at' => new \DateTimeImmutable('2026-06-01 14:00:00', new \DateTimeZone('Europe/Belgrade'))], '');
        $this->assertSame('2026-06-01 12:00:00', $c->table('d')->value('at'));
    }

    public function testAPluginCannotReachTablesOfAPluginWhoseNameExtendsItsOwn(): void
    {
        $c = Connection::sqliteMemory();
        \Kestello\Framework\Database\Schema::packagePrefixes(['p_acme_', 'p_acme_seo_']);
        try {
            $create = fn(string $t) => \Kestello\Framework\Database\Schema::restrictTo('p_acme_', fn() => $c->schema()->create($t, fn(Blueprint $b) => $b->id()));
            $create('p_acme_orders');
            $this->assertTrue($c->schema()->hasTable('p_acme_orders'));
            $this->assertThrows(\RuntimeException::class, fn() => $create('p_acme_seo_orders'));
        } finally {
            \Kestello\Framework\Database\Schema::packagePrefixes([]);
        }
    }

    public function testIdentifiersAndValuesCannotInject(): void
    {
        $this->assertThrows(\InvalidArgumentException::class, fn() => new Blueprint('t; DROP TABLE users'));
        $this->assertThrows(\InvalidArgumentException::class, fn() => (new Blueprint('t'))->string('a b'));
        $this->assertThrows(\InvalidArgumentException::class, fn() => (new Blueprint('t'))->foreign('x')->onDelete('CASCADE; DROP'));

        $sql = (new Connection(['driver' => 'pgsql']))->schemaGrammar()->compileCreate(
            (static function () {
                $b = new Blueprint('t');
                $b->string('s')->default("it's");
                return $b;
            })(),
        )[0];
        $this->assertContains("DEFAULT 'it''s'", $sql);
    }

    public function testSqlitePathsResolveAgainstSiteRoot(): void
    {
        $this->assertSame('/site/storage/database/a.sqlite', DatabaseManager::resolveConfig(['driver' => 'sqlite', 'database' => 'storage/database/a.sqlite'], '/site/')['database']);
        $this->assertSame('/abs/a.sqlite', DatabaseManager::resolveConfig(['driver' => 'sqlite', 'database' => '/abs/a.sqlite'], '/site')['database']);
        $this->assertSame(':memory:', DatabaseManager::resolveConfig(['driver' => 'sqlite', 'database' => ':memory:'], '/site')['database']);
        $this->assertSame('db', DatabaseManager::resolveConfig(['driver' => 'mysql', 'database' => 'db'], '/site')['database']);
    }

    public function testSqliteFileDatabaseIsCreatedWithWal(): void
    {
        $dir = sys_get_temp_dir() . '/kst_db_' . bin2hex(random_bytes(4));
        $config = ['driver' => 'sqlite', 'database' => $dir . '/nested/site.sqlite'];

        try {
            Connection::createDatabase($config);
            $c = new Connection($config);
            $c->schema()->create('t_x', fn(Blueprint $t) => $t->id());
            $this->assertTrue(is_file($config['database']));
            $this->assertSame('wal', strtolower((string) $c->query('PRAGMA journal_mode')->fetchColumn()));
            $this->assertSame(1, (int) $c->query('PRAGMA foreign_keys')->fetchColumn());
        } finally {
            foreach (glob($dir . '/nested/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($dir . '/nested');
            @rmdir($dir);
        }
    }

    private function seedPosts(Connection $c): void
    {
        $c->table('t_posts')->insertMany([
            ['title' => 'A', 'slug' => 'a', 'views' => 10, 'published' => true, 'body' => 'x'],
            ['title' => 'B', 'slug' => 'b', 'views' => 20, 'published' => false, 'body' => null],
            ['title' => 'C', 'slug' => 'c', 'views' => 30, 'published' => true, 'body' => 'y'],
        ]);
    }
}
