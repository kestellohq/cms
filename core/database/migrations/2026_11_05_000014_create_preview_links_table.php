<?php

declare(strict_types=1);

use Kestello\Framework\Database\Blueprint;
use Kestello\Framework\Database\Migration;

/**
 * Preview links for entries: a short one an editor opens from the editor,
 * and share links for people without an account. Only the token's hash is
 * stored, so the table cannot be used to open previews.
 */
return new class extends Migration {
    public function up(): void
    {
        $this->schema()->create('preview_links', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('entry_id')->constrained('entries')->cascadeOnDelete();
            $t->char('token_hash', 64);
            $t->string('kind', 10);                         // editor, share
            $t->string('label', 100)->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->datetime('expires_at');
            $t->datetime('last_used_at')->nullable();
            $t->integer('views')->default(0);
            $t->datetime('created_at')->nullable();
            $t->uniqueIndex('token_hash');
            $t->index('entry_id', 'kind');
            $t->index('expires_at');
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('preview_links');
    }
};
