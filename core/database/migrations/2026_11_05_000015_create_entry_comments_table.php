<?php

declare(strict_types=1);

use Kestello\Framework\Database\Blueprint;
use Kestello\Framework\Database\Migration;

/**
 * The review workflow: editorial comments on an entry, which also record
 * its review steps (submitted, changes requested, approved), and the
 * person an entry is assigned to (a table of its own, so both ends have
 * foreign keys on every database).
 */
return new class extends Migration {
    public function up(): void
    {
        $this->schema()->create('entry_comments', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('entry_id')->constrained('entries')->cascadeOnDelete();
            $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('kind', 20);                         // comment, submitted, changes_requested, approved
            $t->text('body')->nullable();
            $t->datetime('created_at')->nullable();
            $t->index('entry_id', 'id');
        });
        $this->schema()->create('entry_assignees', function (Blueprint $t): void {
            $t->foreignId('entry_id')->constrained('entries')->cascadeOnDelete();
            $t->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $t->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $t->datetime('assigned_at')->nullable();
            $t->uniqueIndex('entry_id');
            $t->index('user_id');
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('entry_assignees');
        $this->schema()->dropIfExists('entry_comments');
    }
};
