<?php

declare(strict_types=1);

use Kestello\Framework\Database\Blueprint;
use Kestello\Framework\Database\Migration;

/**
 * Taxonomy terms and their entries,
 * menus and the theme locations they fill, and roles edited in the admin
 * (stored as changes to the roles code declares).
 */
return new class extends Migration {
    public function up(): void
    {
        $schema = $this->schema();
        $schema->create('terms', function (Blueprint $t): void {
            $t->id();
            $t->string('taxonomy', 32);
            $t->string('name', 191);
            $t->string('slug', 191);
            $t->foreignId('parent_id')->nullable()->constrained('terms')->nullOnDelete();
            $t->text('description')->nullable();
            $t->integer('menu_order')->default(0);
            $t->timestamps();
            $t->uniqueIndex('taxonomy', 'slug');
            $t->index('taxonomy', 'parent_id');
        });
        $schema->create('entry_term', function (Blueprint $t): void {
            $t->foreignId('entry_id')->constrained('entries')->cascadeOnDelete();
            $t->foreignId('term_id')->constrained('terms')->cascadeOnDelete();
            $t->string('taxonomy', 32);
            $t->integer('position')->default(0);
            $t->primary('entry_id', 'term_id');
            $t->index('term_id');
        });
        $schema->create('menus', function (Blueprint $t): void {
            $t->id();
            $t->string('name', 100);
            $t->longText('items');                           // JSON tree
            $t->timestamps();
        });
        $schema->create('menu_locations', function (Blueprint $t): void {
            $t->string('location', 64)->primary();
            $t->foreignId('menu_id')->constrained('menus')->cascadeOnDelete();
        });
        $schema->create('roles', function (Blueprint $t): void {
            $t->string('name', 20)->primary();
            $t->string('label', 60);
            $t->string('description', 255)->nullable();
            $t->boolean('custom')->default(false);           // created in the admin (not declared in code)
            $t->text('granted');                             // JSON list, added to the code defaults
            $t->text('revoked');                             // JSON list, removed from them
            $t->timestamps();
        });
    }

    public function down(): void
    {
        $schema = $this->schema();
        foreach (['roles', 'menu_locations', 'menus', 'entry_term', 'terms'] as $table) {
            $schema->dropIfExists($table);
        }
    }
};
