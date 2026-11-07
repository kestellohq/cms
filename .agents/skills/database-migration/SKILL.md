---
name: database-migration
description: Writing database migrations for core or a plugin that run the same on MySQL, MariaDB, PostgreSQL and SQLite, and testing them across the database matrix. Load it whenever a change adds or alters a table, column or index.
---

# Writing a database migration

The schema only changes through migrations. Each one is a file that returns an anonymous `Migration` with `up()` and `down()`, written with the schema builder so it runs on every supported database: MySQL 8, MariaDB 10.6, PostgreSQL 13 and SQLite 3.35 or newer.

## 1. Where the file goes

| Owner | Folder | Tables |
| --- | --- | --- |
| Core | `core/database/migrations/` | Any name; add new core tables to `CORE_TABLES` in `tests/Framework/DatabaseTest.php`. |
| Plugin | `<plugin>/database/migrations/` | Must start with `p_<slug>_` (dashes become underscores): `p_acme_shop_orders`. |
| Theme | `<theme>/database/migrations/` | Must start with `t_<slug>_`. |

The prefix is enforced while a package's migrations run (`Schema::restrictTo()`, `Package::tablePrefix()`): a plugin can't create, alter or drop anyone else's tables.

File names are `YYYY_MM_DD_HHMMSS_what_it_does.php` and must be unique; the `migrations` table records them. For plugins, `php bin/cms make:migration create_orders_table --plugin=acme-shop` writes one. Core migrations are created by hand, dated after the newest one.

**Never edit a migration that has been released.** Sites have already run it. Write a new one that changes the schema further.

## 2. The file

From `core/database/migrations/2026_11_05_000005_create_media_variants_table.php`:

```php
<?php

declare(strict_types=1);

use Kestello\Framework\Database\Blueprint;
use Kestello\Framework\Database\Migration;

return new class extends Migration {
    public function up(): void
    {
        $this->schema()->create('media_variants', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('media_id')->constrained('media')->cascadeOnDelete();
            $t->string('name', 120);
            $t->string('path', 512);
            $t->integer('width');
            $t->timestamps();
            $t->uniqueIndex('media_id', 'name');
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('media_variants');
    }
};
```

Changing an existing table:

```php
$this->schema()->table('p_acme_shop_orders', function (Blueprint $t): void {
    $t->string('note', 500)->nullable();
    $t->index('status', 'created_at');
});
```

## 3. The builder

**Schema** (`core/src/Framework/Database/Schema.php`): `create()`, `table()`, `drop()`, `dropIfExists()`, `rename()`, `hasTable()`, `hasColumn()`, `columns()`, `withoutForeignKeyConstraints()`.

**Columns** (`Blueprint.php`): `id()`, `increments()`, `foreignId()`, `string($name, $length)`, `char()`, `text()`, `mediumText()`, `longText()`, `tinyInteger()`, `smallInteger()`, `integer()`, `bigInteger()`, `boolean()`, `decimal($name, $precision, $scale)`, `double()`, `date()`, `time()`, `datetime()`, `timestamp()`, `json()`, `uuid()`, `ulid()`, `binary()`, `enum($name, $values)`, `timestamps()`, `softDeletes()`.

**Modifiers** (`ColumnDefinition.php`): `nullable()`, `default()`, `useCurrent()`, `unique()`, `index()`, `unsigned()`, `primary()`, `comment()`, `constrained($table)`, then on the foreign key `cascadeOnDelete()`, `nullOnDelete()`, `onDelete()`, `onUpdate()`.

**Table changes:** `index(...$columns)`, `uniqueIndex(...)`, `foreign($column)`, `dropColumn()`, `renameColumn()`, `dropIndex()`, `dropUnique()`, `dropForeign()`, `primary(...)`.

## 4. Portability rules

- Use the builder, not raw SQL. If raw SQL is unavoidable, branch on `$connection->driver()` (`mysql`, `pgsql`, `sqlite`) and test every branch.
- Give every `string` a real length (`string('name', 120)`), and keep indexed ones short: long indexed strings hit MySQL's index size limit with utf8mb4.
- SQLite can't add a foreign key to an existing table (the builder throws a `LogicException`). Declare foreign keys when you create the table, or add a plain indexed column.
- MySQL commits each table change on its own, so a migration that fails halfway can leave part of it done there. Keep each migration to one logical change, and make `up()` safe to re-run where you can (`hasTable()`, `hasColumn()`).
- `json` columns hold data you read whole. Anything you filter or sort on gets its own indexed column.
- Store times in UTC; `timestamps()` adds `created_at` and `updated_at`.
- Store booleans with `boolean()` and compare with `true`/`false`, never `'1'`.
- `down()` undoes `up()` exactly, so rollbacks and plugin uninstalls leave nothing behind.

## 5. Run it

```sh
php bin/cms migrate                 # pending core migrations
php bin/cms migrate:status
php bin/cms migrate:rollback        # the last batch of core migrations
php bin/cms plugin:activate <slug>  # runs that plugin's pending migrations
```

A plugin's new migrations also run when an updated version boots for the first time (after `package:update` or an admin update), and its provider's `upgrade()` runs after them for data changes.

## 6. Test it on every database

The PHP suite always runs on in-memory SQLite. It also runs on every database in `KESTELLO_TEST_DATABASES`, a JSON list of connection configs; those databases are wiped, so point it at test databases only. Set it to the dev containers' test databases when you run the suite locally (`../testing/SKILL.md` has the exact command); CI runs the same matrix.

- Core: `testCoreMigrationsRunOnEveryDialect` in `tests/Framework/DatabaseTest.php` runs every core migration up and all the way down on each connection. Add assertions there for what your migration creates, and add new tables to `CORE_TABLES`.
- Plugins: copy the lifecycle test in `tests/Package/PluginLifecycleTest.php` (install, activate, use, deactivate, uninstall on SQLite and on each database in the list).
- Queries your feature adds need tests that run on each connection too (see the `each()` helper in `DatabaseTest`).

```sh
npm run test:php:matrix              # MySQL, PostgreSQL and SQLite (see ../testing/SKILL.md)
php bin/cms test tests/Framework --filter=Migrations
```

## Done when

- [ ] The file name is unique and dated; released migrations are untouched.
- [ ] Plugin and theme tables carry their prefix.
- [ ] `down()` reverses `up()`.
- [ ] Tests pass on SQLite, MySQL or MariaDB, and PostgreSQL.
- [ ] A changelog entry mentions schema changes that hosts should know about.

Related: `../testing/SKILL.md`, `../write-plugin/SKILL.md`, `../architecture/SKILL.md`.
