<?php

declare(strict_types=1);

use Kestello\Cms\Search\DatabaseEngine;
use Kestello\Framework\Database\Blueprint;
use Kestello\Framework\Database\DatabaseManager;
use Kestello\Framework\Database\Migration;

/**
 * The database search engine's documents.
 * Full-text parts differ per database (FULLTEXT, tsvector, FTS5) and are
 * added by DatabaseEngine::install(); without them search falls back to LIKE.
 */
return new class extends Migration {
    public function up(): void
    {
        $this->schema()->create(DatabaseEngine::TABLE, function (Blueprint $t): void {
            $t->id();
            $t->string('collection', 32);
            $t->string('doc_id', 64);
            $t->string('title', 255)->default('');
            $t->mediumText('body')->nullable();
            $t->string('url', 500)->nullable();
            $t->string('type', 32)->default('');
            $t->string('locale', 12)->default('');
            $t->string('tags', 2000)->default('');          // " type:post category:news " (whole-tag LIKE filters)
            $t->text('meta')->nullable();                     // JSON shown with results, never searched
            $t->bigInteger('published_at')->nullable();       // unix time; later ones are hidden until due
            $t->uniqueIndex('collection', 'doc_id');
            $t->index('collection', 'published_at');
        });
        DatabaseEngine::install(DatabaseManager::getConnection());
    }

    public function down(): void
    {
        DatabaseEngine::uninstall(DatabaseManager::getConnection());
        $this->schema()->dropIfExists(DatabaseEngine::TABLE);
    }
};
