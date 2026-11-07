<?php

declare(strict_types=1);

use Kestello\Framework\Database\Blueprint;
use Kestello\Framework\Database\Migration;

/**
 * What site owners change in a theme.
 * Files in the theme folder are the defaults; a row here overrides one of
 * them (global styles, a template, a part, a pattern). Every publish keeps
 * a revision, and base_hash records which theme file a change started from,
 * so a theme update can be compared and merged. Fonts are downloaded once and
 * served from this site.
 */
return new class extends Migration {
    public function up(): void
    {
        $this->schema()->create('theme_documents', function (Blueprint $t): void {
            $t->id();
            $t->string('theme', 64);
            $t->string('kind', 16);                          // styles | template | part | pattern
            $t->string('doc_key', 120);                      // "global", "home", "header", …
            $t->string('status', 10)->default('published');  // published | draft
            $t->mediumText('data');                          // JSON
            $t->integer('revision')->default(1);
            $t->bigInteger('updated_by')->nullable();
            $t->string('base_hash', 64)->nullable();         // sha256 of the theme file the change started from
            $t->timestamps();
            $t->uniqueIndex('theme', 'kind', 'doc_key', 'status');
        });
        $this->schema()->create('theme_document_revisions', function (Blueprint $t): void {
            $t->id();
            $t->string('theme', 64);
            $t->string('kind', 16);
            $t->string('doc_key', 120);
            $t->integer('revision');
            $t->mediumText('data');                          // JSON; "null" for a reset to the theme's files
            $t->bigInteger('author')->nullable();
            $t->string('note', 200)->default('');
            $t->timestamps();
            $t->index('theme', 'kind', 'doc_key');
        });
        $this->schema()->create('theme_fonts', function (Blueprint $t): void {
            $t->id();
            $t->string('slug', 64)->unique();                // "gf-inter"
            $t->string('family', 100);
            $t->string('category', 20)->default('sans-serif');
            $t->string('source', 20)->default('google');
            $t->text('files');                               // JSON: [{weight, style, file, range}]
            $t->bigInteger('bytes')->default(0);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('theme_fonts');
        $this->schema()->dropIfExists('theme_document_revisions');
        $this->schema()->dropIfExists('theme_documents');
    }
};
