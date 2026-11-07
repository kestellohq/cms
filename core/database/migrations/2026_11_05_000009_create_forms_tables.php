<?php

declare(strict_types=1);

use Kestello\Framework\Database\Blueprint;
use Kestello\Framework\Database\Migration;

/**
 * Forms and what people sent through them.
 */
return new class extends Migration {
    public function up(): void
    {
        $this->schema()->create('forms', function (Blueprint $t): void {
            $t->id();
            $t->string('title', 200);
            $t->string('status', 10)->default('open');        // open | closed
            $t->mediumText('definition');                    // JSON: fields, messages, notifications, retention, spam settings
            $t->bigInteger('created_by')->nullable();
            $t->timestamps();
        });
        $this->schema()->create('form_submissions', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('form_id')->constrained('forms')->cascadeOnDelete();
            $t->mediumText('data');                          // JSON: field name => value (files: [{name, size, type, key}])
            $t->string('status', 10)->default('new');         // new | read | spam
            $t->string('ip', 45)->default('');
            $t->string('user_agent', 255)->default('');
            $t->string('page', 500)->default('');             // where the form was sent from
            $t->bigInteger('user_id')->nullable();
            $t->timestamps();
            $t->index('form_id', 'created_at');
            $t->index('status');
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('form_submissions');
        $this->schema()->dropIfExists('forms');
    }
};
