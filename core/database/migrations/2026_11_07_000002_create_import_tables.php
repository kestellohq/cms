<?php

declare(strict_types=1);

use Kestello\Framework\Database\Blueprint;
use Kestello\Framework\Database\Migration;

/**
 * WordPress imports (Kestello\Cms\Import\WordPress).
 *
 *   imports         one row per uploaded export: its site, options, phase and status
 *   import_items    the export staged one record per row, with what became of each
 *   import_sources  what each WordPress item became here, kept across imports of the
 *                   same site so running an import again updates instead of duplicating
 */
return new class extends Migration {
    public function up(): void
    {
        $this->schema()->create('imports', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('source', 20);                       // wordpress
            $t->string('site_url', 255);
            $t->string('site_title', 255);
            $t->char('site_key', 40);                       // sha1 of the normalized site address
            $t->string('file', 255)->nullable();            // the export, relative to storage; removed when finished
            $t->string('status', 12);                       // ready, running, done, cancelled
            $t->string('phase', 12)->nullable();            // terms, media, entries, links, menus, redirects, finish
            $t->text('options');                            // JSON
            $t->longText('summary');                        // JSON: what the analysis found
            $t->longText('notes');                          // JSON: what conversion could not carry over exactly
            $t->string('error', 500)->nullable();           // why it stopped by itself, when it did
            $t->datetime('started_at')->nullable();
            $t->datetime('finished_at')->nullable();
            $t->timestamps();
            $t->index('status');
        });
        $this->schema()->create('import_items', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('import_id')->constrained('imports')->cascadeOnDelete();
            $t->string('kind', 12);                         // term, attachment, file, entry, menu, redirect, reusable
            $t->string('source_key', 191);                  // what identifies it in the export (post id, taxonomy:slug, …)
            $t->bigInteger('source_id')->default(0);        // WordPress's numeric id, when it has one
            $t->integer('depth')->default(0);               // parents before children
            $t->string('label', 255);                       // shown in the report
            $t->string('link_path', 500)->nullable();       // its old address, for links in content
            $t->string('file_key', 500)->nullable();        // uploads/… path without size suffix, for attachments and files
            $t->longText('payload');                        // JSON
            $t->string('state', 8)->default('pending');     // pending, done
            $t->integer('attempts')->default(0);            // times work on it began: one that keeps taking the process down is given up
            $t->string('result', 10)->nullable();           // created, updated, unchanged, kept, matched, skipped, failed
            $t->string('message', 500)->nullable();
            $t->bigInteger('target_id')->nullable();
            $t->boolean('needs_links')->default(false);     // links to items imported after it: converted again at the end
            $t->index('import_id', 'kind', 'state', 'depth');
            $t->index('import_id', 'kind', 'source_key');
            $t->index('import_id', 'link_path');
            $t->index('import_id', 'file_key');
        });
        $this->schema()->create('import_sources', function (Blueprint $t): void {
            $t->id();
            $t->char('site_key', 40);
            $t->string('kind', 12);
            $t->string('source_key', 191);
            $t->string('target_type', 12);                  // entry, term, media, menu, redirect
            $t->bigInteger('target_id');
            $t->char('checksum', 40);                       // of the WordPress item as last imported
            $t->char('target_hash', 40)->nullable();        // of what was written here, to notice later edits
            $t->integer('target_version')->nullable();      // entries: the version written
            $t->timestamps();
            $t->uniqueIndex('site_key', 'kind', 'source_key');
            $t->index('target_type', 'target_id');
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('import_items');
        $this->schema()->dropIfExists('import_sources');
        $this->schema()->dropIfExists('imports');
    }
};
