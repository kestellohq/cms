<?php

declare(strict_types=1);

use Kestello\Framework\Database\Blueprint;
use Kestello\Framework\Database\Migration;

/**
 * Content types and taxonomies built in the admin.
 */
return new class extends Migration {
    public function up(): void
    {
        $this->schema()->create('content_models', function (Blueprint $t): void {
            $t->id();
            $t->string('kind', 10);                       // type | taxonomy
            $t->string('name', 32);                       // the type or taxonomy name (entries.type), never changes
            $t->mediumText('definition');                 // JSON: labels, settings, fields (each with a stable id)
            $t->bigInteger('created_by')->nullable();
            $t->timestamps();
            $t->uniqueIndex('kind', 'name');
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('content_models');
    }
};
