<?php

declare(strict_types=1);

use Kestello\Framework\Database\Blueprint;
use Kestello\Framework\Database\Migration;

/**
 * The media library, which entries use
 * which files, and uploads in progress (chunked to PHP or direct to a
 * bucket).
 */
return new class extends Migration {
    public function up(): void
    {
        $schema = $this->schema();
        $schema->create('media', function (Blueprint $t): void {
            $t->id();
            $t->string('disk', 64);
            $t->string('path', 512);                         // key of the original on the disk
            $t->string('filename', 191);                     // cleaned original name, for downloads
            $t->string('title', 191);
            $t->text('alt')->nullable();
            $t->text('caption')->nullable();
            $t->string('mime', 127);
            $t->string('ext', 16);
            $t->string('kind', 16);                          // image, document, audio, video, archive
            $t->bigInteger('size');
            $t->integer('width')->nullable();
            $t->integer('height')->nullable();
            $t->char('checksum', 64)->nullable();            // sha256; unknown for direct uploads
            $t->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $t->longText('meta')->nullable();                // JSON: variants, focal point, placeholder
            $t->timestamps();
            $t->index('kind', 'created_at');
            $t->index('uploaded_by');
            $t->index('checksum');
        });
        $schema->create('media_usage', function (Blueprint $t): void {
            $t->foreignId('media_id')->constrained('media')->cascadeOnDelete();
            $t->foreignId('entry_id')->constrained('entries')->cascadeOnDelete();
            $t->string('field', 100);
            $t->primary('media_id', 'entry_id', 'field');
            $t->index('entry_id');
        });
        $schema->create('uploads', function (Blueprint $t): void {
            $t->char('id', 32)->primary();
            $t->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $t->string('mode', 16);                          // chunked or direct
            $t->string('disk', 64);
            $t->string('path', 512);                         // final key (direct) or planned key
            $t->string('filename', 191);
            $t->string('ext', 16);
            $t->string('mime', 127);
            $t->bigInteger('size');
            $t->integer('chunk_size');
            $t->integer('received')->default(0);             // chunks received, in order
            $t->datetime('expires_at');
            $t->timestamps();
            $t->index('user_id');
            $t->index('expires_at');
        });
    }

    public function down(): void
    {
        $schema = $this->schema();
        foreach (['uploads', 'media_usage', 'media'] as $table) {
            $schema->dropIfExists($table);
        }
    }
};
