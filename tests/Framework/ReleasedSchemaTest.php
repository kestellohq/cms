<?php

declare(strict_types=1);

namespace Kestello\Tests\Framework;

use Kestello\Framework\Database\Blueprint;
use Kestello\Framework\Database\Connection;
use Kestello\Framework\Database\DatabaseManager;
use Kestello\Framework\Database\Migration;
use Kestello\Framework\Testing\TestCase;

/**
 * The migration that brings sites installed from 0.1.0-rc.1 to rc.3 up to
 * date (2026_11_11_000002): run on those releases' tables, on a new
 * install's, and twice. In-memory SQLite plus KESTELLO_TEST_DATABASES (see
 * DatabaseTest; those databases are wiped).
 */
final class ReleasedSchemaTest extends TestCase
{
    private const array TABLES = ['menu_locations', 'menus', 'terms', 'entries', 'users'];
    private const string FILE = __DIR__ . '/../../core/database/migrations/2026_11_11_000002_bring_released_tables_up_to_date.php';

    public function testSitesFromTheReleaseCandidatesGetTheNewColumnsAndKeepTheirData(): void
    {
        $this->each(function (Connection $c, string $name): void {
            $this->releasedTables($c);
            $menu = $c->table('menus')->insert(['name' => 'Main']);
            $c->table('menu_locations')->insert(['location' => 'primary', 'menu_id' => $menu]);
            $c->table('terms')->insert(['taxonomy' => 'category', 'name' => 'News', 'slug' => 'news']);

            $this->migrate();
            $this->migrate(); // again: nothing left to do

            $schema = $c->schema();
            $this->assertTrue($schema->hasColumn('users', 'locale'), $name);
            $this->assertTrue($schema->hasColumn('terms', 'translation_group'), $name);
            $this->assertFalse($schema->hasIndex('entries', 'idx_entries_type_slug'), "{$name}: the old index is gone");
            $this->assertTrue($schema->hasIndex('entries', 'idx_entries_type_locale_slug'), $name);
            $this->assertTrue($schema->hasIndex('entries', 'idx_entries_translation_group'), $name);
            $this->assertTrue($schema->hasIndex('terms', 'uniq_terms_taxonomy_locale_slug'), $name);
            $this->assertFalse($schema->hasIndex('terms', 'uniq_terms_taxonomy_slug'), $name);

            $this->assertSame([['location' => 'primary', 'locale' => '', 'menu_id' => $menu]], array_map(
                static fn(array $r) => ['location' => $r['location'], 'locale' => $r['locale'], 'menu_id' => (int) $r['menu_id']],
                $c->table('menu_locations')->get(),
            ), "{$name}: the theme location keeps its menu");
            $c->table('menu_locations')->insert(['location' => 'primary', 'locale' => 'es', 'menu_id' => $menu]);
            $c->table('terms')->insert(['taxonomy' => 'category', 'name' => 'Noticias', 'slug' => 'news', 'locale' => 'es']);
            $this->assertSame(2, $c->table('terms')->count(), "{$name}: the same slug in another language");
            $this->assertThrows(\PDOException::class, fn() => $c->table('terms')->insert(['taxonomy' => 'category', 'name' => 'X', 'slug' => 'news', 'locale' => 'es']), $name);
        });
    }

    public function testANewInstallIsLeftAsItIs(): void
    {
        $this->each(function (Connection $c, string $name): void {
            $this->newTables($c);
            $before = array_map(fn(string $t) => [$c->schema()->columns($t), $c->schema()->indexes($t)], self::TABLES);
            $this->migrate();
            $this->assertSame($before, array_map(fn(string $t) => [$c->schema()->columns($t), $c->schema()->indexes($t)], self::TABLES), $name);
        });
    }

    /** @param callable(Connection, string): void $test */
    private function each(callable $test): void
    {
        $connections = ['sqlite' => Connection::sqliteMemory()];
        $extra = json_decode((string) getenv('KESTELLO_TEST_DATABASES'), true);
        foreach (is_array($extra) ? $extra : [] as $config) {
            $connection = new Connection($config);
            $connections[$connection->driver() . '@' . ($config['host'] ?? '')] = $connection;
        }
        $previous = DatabaseManager::hasConnection() ? DatabaseManager::getConnection() : null;
        try {
            foreach ($connections as $name => $connection) {
                DatabaseManager::setConnection($connection);
                $this->wipe($connection);
                try {
                    $test($connection, $name);
                } finally {
                    $this->wipe($connection);
                }
            }
        } finally {
            if ($previous !== null) {
                DatabaseManager::setConnection($previous);
            }
        }
    }

    private function migrate(): void
    {
        /** @var Migration $migration */
        $migration = require self::FILE;
        $migration->up();
    }

    private function wipe(Connection $c): void
    {
        $schema = $c->schema();
        $schema->withoutForeignKeyConstraints(function () use ($schema): void {
            foreach (self::TABLES as $table) {
                $schema->dropIfExists($table);
            }
        });
    }

    /** The parts of these tables as 0.1.0-rc.3 created them. */
    private function releasedTables(Connection $c): void
    {
        $this->tables($c, released: true);
    }

    /** The same parts as a new install creates them. */
    private function newTables(Connection $c): void
    {
        $this->tables($c, released: false);
    }

    private function tables(Connection $c, bool $released): void
    {
        $schema = $c->schema();
        $schema->create('users', function (Blueprint $t) use ($released): void {
            $t->id();
            $t->string('email', 191);
            if (!$released) {
                $t->string('locale', 12)->default('');
            }
        });
        $schema->create('entries', function (Blueprint $t) use ($released): void {
            $t->id();
            $t->string('type', 32);
            $t->string('slug', 191);
            $t->string('locale', 12)->default('');
            $t->char('translation_group', 26)->nullable();
            if ($released) {
                $t->index('type', 'slug');
            } else {
                $t->index('type', 'locale', 'slug');
                $t->index('translation_group');
            }
        });
        $schema->create('terms', function (Blueprint $t) use ($released): void {
            $t->id();
            $t->string('taxonomy', 32);
            $t->string('name', 191);
            $t->string('slug', 191);
            if ($released) {
                $t->uniqueIndex('taxonomy', 'slug');
            } else {
                $t->string('locale', 12)->default('');
                $t->char('translation_group', 26)->nullable();
                $t->uniqueIndex('taxonomy', 'locale', 'slug');
                $t->index('translation_group');
            }
        });
        $schema->create('menus', function (Blueprint $t): void {
            $t->id();
            $t->string('name', 120);
        });
        $schema->create('menu_locations', function (Blueprint $t) use ($released): void {
            $t->string('location', 64);
            if (!$released) {
                $t->string('locale', 12)->default('');
            }
            $t->foreignId('menu_id')->constrained('menus')->cascadeOnDelete();
            $released ? $t->primary('location') : $t->primary('location', 'locale');
        });
    }
}
