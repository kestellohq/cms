<?php

declare(strict_types=1);

use Kestello\Framework\Database\Blueprint;
use Kestello\Framework\Database\Migration;

/**
 * Installed plugins and themes, and whether they are active.
 */
return new class extends Migration {
    public function up(): void
    {
        $this->schema()->create('packages', function (Blueprint $table): void {
            $table->id();
            $table->string('type', 20);
            $table->string('slug', 64);
            $table->string('status', 20)->default('inactive');
            $table->string('version', 50);
            $table->timestamp('updated_at')->nullable();
            $table->uniqueIndex('type', 'slug');
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('packages');
    }
};
