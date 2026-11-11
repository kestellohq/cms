<?php

declare(strict_types=1);

use Kestello\Framework\Database\Blueprint;
use Kestello\Framework\Database\DatabaseManager;
use Kestello\Framework\Database\Migration;

/**
 * Brings sites installed from 0.1.0-rc.1 to rc.3 to the schema a new
 * install gets. After rc.3, content translation and the language of each
 * person's emails changed three released create migrations in place, which
 * those sites had already run:
 *
 *   - users.locale (2024_01_01_000001)
 *   - entries: the index (type, locale, slug) instead of (type, slug), and
 *     one on translation_group (2026_11_05_000002)
 *   - terms.locale and translation_group, unique (taxonomy, locale, slug)
 *     instead of (taxonomy, slug); menu_locations.locale in its primary key
 *     (2026_11_05_000003)
 *
 * Each step checks first, so it does nothing on a new install or on a site
 * built from a development version that already has part of it.
 */
return new class extends Migration {
    public function up(): void
    {
        $schema = $this->schema();

        if (!$schema->hasColumn('users', 'locale')) {
            $schema->table('users', fn(Blueprint $t) => $t->string('locale', 12)->default(''));
        }

        if ($schema->hasIndex('entries', 'idx_entries_type_slug')) {
            $schema->table('entries', fn(Blueprint $t) => $t->dropIndex('type', 'slug'));
        }
        if (!$schema->hasIndex('entries', 'idx_entries_type_locale_slug')) {
            $schema->table('entries', fn(Blueprint $t) => $t->index('type', 'locale', 'slug'));
        }
        if (!$schema->hasIndex('entries', 'idx_entries_translation_group')) {
            $schema->table('entries', fn(Blueprint $t) => $t->index('translation_group'));
        }

        if (!$schema->hasColumn('terms', 'locale')) {
            $schema->table('terms', function (Blueprint $t): void {
                $t->string('locale', 12)->default('');
                $t->char('translation_group', 26)->nullable();
            });
        }
        if ($schema->hasIndex('terms', 'uniq_terms_taxonomy_slug')) {
            $schema->table('terms', fn(Blueprint $t) => $t->dropUnique('taxonomy', 'slug'));
        }
        if (!$schema->hasIndex('terms', 'uniq_terms_taxonomy_locale_slug')) {
            $schema->table('terms', fn(Blueprint $t) => $t->uniqueIndex('taxonomy', 'locale', 'slug'));
        }
        if (!$schema->hasIndex('terms', 'idx_terms_translation_group')) {
            $schema->table('terms', fn(Blueprint $t) => $t->index('translation_group'));
        }

        // A primary key can't be changed in place on every database: the
        // table is small (a row per theme location), so it is rebuilt.
        if (!$schema->hasColumn('menu_locations', 'locale')) {
            $db = DatabaseManager::getConnection();
            $rows = $db->table('menu_locations')->select('location', 'menu_id')->get();
            $schema->drop('menu_locations');
            $schema->create('menu_locations', function (Blueprint $t): void {
                $t->string('location', 64);
                $t->string('locale', 12)->default('');
                $t->foreignId('menu_id')->constrained('menus')->cascadeOnDelete();
                $t->primary('location', 'locale');
            });
            foreach ($rows as $row) {
                $db->table('menu_locations')->insert(['location' => $row['location'], 'locale' => '', 'menu_id' => $row['menu_id']]);
            }
        }
    }

    public function down(): void
    {
        // Nothing to undo: the columns and indexes belong to the create
        // migrations now, and rolling those back drops the tables.
    }
};
