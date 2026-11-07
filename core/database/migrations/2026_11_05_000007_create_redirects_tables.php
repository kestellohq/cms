<?php

declare(strict_types=1);

use Kestello\Framework\Database\Blueprint;
use Kestello\Framework\Database\Migration;

/**
 * Redirects and the "not found" log.
 */
return new class extends Migration {
    public function up(): void
    {
        $this->schema()->create('redirects', function (Blueprint $t): void {
            $t->id();
            $t->string('source', 500);                    // "/old-path", "/old-blog" (prefix) or a regular expression
            $t->string('match', 8)->default('exact');      // exact | prefix | regex
            $t->string('target', 1000)->default('');       // "/new-path", "https://…", "" for 410 Gone
            $t->integer('status')->default(301);
            $t->string('origin', 8)->default('manual');    // manual | auto (an entry moved) | import
            $t->bigInteger('entry_id')->nullable();
            $t->string('note', 255)->default('');
            $t->bigInteger('hits')->default(0);
            $t->datetime('last_hit_at')->nullable();
            $t->timestamps();
            $t->index('match', 'source');
            $t->index('entry_id');
        });
        $this->schema()->create('not_found_log', function (Blueprint $t): void {
            $t->id();
            $t->string('path', 500);
            $t->string('referrer', 500)->default('');     // the last page that linked here (no visitor data)
            $t->bigInteger('hits')->default(1);
            $t->datetime('first_seen_at');
            $t->datetime('last_seen_at');
            $t->uniqueIndex('path');
            $t->index('last_seen_at');
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('not_found_log');
        $this->schema()->dropIfExists('redirects');
    }
};
