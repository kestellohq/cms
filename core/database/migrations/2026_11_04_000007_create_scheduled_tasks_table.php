<?php

declare(strict_types=1);

use Kestello\Framework\Database\Blueprint;
use Kestello\Framework\Database\Migration;

/** Scheduler state: last run per task, and admin overrides (disabled). */
return new class extends Migration {
    public function up(): void
    {
        $this->schema()->create('scheduled_tasks', function (Blueprint $table): void {
            $table->string('name', 191)->primary();
            $table->string('owner', 100);
            $table->bigInteger('last_due_at');              // the due time last claimed (each runs once)
            $table->bigInteger('last_started_at')->nullable();
            $table->bigInteger('last_finished_at')->nullable();
            $table->integer('last_duration_ms')->nullable();
            $table->string('last_status', 20)->nullable();  // ok | failed | skipped | running
            $table->text('last_output')->nullable();
            $table->boolean('disabled')->default(0);
            $table->bigInteger('created_at');
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('scheduled_tasks');
    }
};
