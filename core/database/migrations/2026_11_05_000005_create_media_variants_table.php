<?php

declare(strict_types=1);

use Kestello\Framework\Database\Blueprint;
use Kestello\Framework\Database\Migration;

/**
 * Generated image variants. Names carry
 * a hash of everything they depend on, so a changed size or focal point
 * makes new files and the old ones are pruned.
 */
return new class extends Migration {
    public function up(): void
    {
        $this->schema()->create('media_variants', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('media_id')->constrained('media')->cascadeOnDelete();
            $t->string('name', 120);                         // <size>-<width>-<hash>.<format>
            $t->string('path', 512);
            $t->string('format', 10);
            $t->integer('width');
            $t->integer('height');
            $t->bigInteger('size');
            $t->timestamps();
            $t->uniqueIndex('media_id', 'name');
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('media_variants');
    }
};
