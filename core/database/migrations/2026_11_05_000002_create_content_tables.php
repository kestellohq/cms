<?php

declare(strict_types=1);

use Kestello\Framework\Database\Blueprint;
use Kestello\Framework\Database\Migration;

/**
 * Content: entries of every type, the index of
 * indexed() fields, revisions and per-user autosaves.
 */
return new class extends Migration {
    public function up(): void
    {
        $schema = $this->schema();
        $schema->create('entries', function (Blueprint $t): void {
            $t->id();
            $t->string('type', 32);
            $t->string('status', 12)->default('draft');      // draft, scheduled, published, private
            $t->string('title', 255)->default('');
            $t->string('slug', 191);
            $t->foreignId('parent_id')->nullable()->constrained('entries')->nullOnDelete();
            $t->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('locale', 12)->default('');           // '' = the main language, else its code (ContentLanguages)
            $t->char('translation_group', 26)->nullable();   // shared by an entry and its translations
            $t->string('template', 100)->nullable();
            $t->text('excerpt')->nullable();
            $t->json('data')->nullable();
            $t->integer('menu_order')->default(0);
            $t->integer('version')->default(1);
            $t->datetime('published_at')->nullable();
            $t->timestamps();
            $t->softDeletes();
            $t->index('type', 'status', 'published_at');
            $t->index('type', 'locale', 'slug');
            $t->index('translation_group');
            $t->index('parent_id');
            $t->index('author_id');
        });
        $schema->create('entry_index', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('entry_id')->constrained('entries')->cascadeOnDelete();
            $t->string('field', 64);
            $t->string('value_string', 191)->nullable();
            $t->double('value_number')->nullable();
            $t->datetime('value_date')->nullable();
            $t->index('entry_id');
            $t->index('field', 'value_string');
            $t->index('field', 'value_number');
            $t->index('field', 'value_date');
        });
        $schema->create('revisions', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('entry_id')->constrained('entries')->cascadeOnDelete();
            $t->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $t->longText('snapshot');                        // JSON
            $t->string('note', 200)->nullable();
            $t->datetime('created_at')->nullable();
            $t->index('entry_id', 'id');
        });
        $schema->create('autosaves', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('entry_id')->constrained('entries')->cascadeOnDelete();
            $t->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $t->longText('snapshot');                        // JSON
            $t->datetime('updated_at')->nullable();
            $t->uniqueIndex('entry_id', 'user_id');
        });
    }

    public function down(): void
    {
        $schema = $this->schema();
        foreach (['autosaves', 'revisions', 'entry_index', 'entries'] as $table) {
            $schema->dropIfExists($table);
        }
    }
};
