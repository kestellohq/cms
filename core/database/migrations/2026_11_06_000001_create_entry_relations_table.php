<?php

declare(strict_types=1);

use Kestello\Framework\Database\Blueprint;
use Kestello\Framework\Database\Migration;

/**
 * Which entries link to which, through relationship fields at any depth (a
 * field inside a group, a repeater or a block counts under its top-level
 * field). Rebuilt from the entry on every save, like media_usage, so an entry
 * can show and query what links to it. Both ends cascade: a deleted entry
 * takes its links, in either direction, with it.
 */
return new class extends Migration {
    public function up(): void
    {
        $this->schema()->create('entry_relations', function (Blueprint $t): void {
            $t->foreignId('source_id')->constrained('entries')->cascadeOnDelete();
            $t->foreignId('target_id')->constrained('entries')->cascadeOnDelete();
            $t->string('field', 64);
            $t->primary('source_id', 'target_id', 'field');
            $t->index('target_id', 'field');
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('entry_relations');
    }
};
