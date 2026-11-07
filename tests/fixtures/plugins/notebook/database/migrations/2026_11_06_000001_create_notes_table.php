<?php

declare(strict_types=1);

use Kestello\Framework\Database\Blueprint;
use Kestello\Framework\Database\Migration;

/** The plugin's own table, pointing at core's entries (so uninstalling must drop it before entries go). */
return new class extends Migration {
    public function up(): void
    {
        $this->schema()->create('p_notebook_notes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('entry_id')->constrained('entries')->cascadeOnDelete();
            $table->string('text', 200);
            $table->timestamp('created_at')->nullable();
            $table->index('entry_id');
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('p_notebook_notes');
    }
};
