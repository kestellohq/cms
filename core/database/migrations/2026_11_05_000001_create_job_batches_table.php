<?php

declare(strict_types=1);

use Kestello\Framework\Database\Blueprint;
use Kestello\Framework\Database\Migration;

/** Job batches: progress and then/catch/finally jobs (every queue driver). */
return new class extends Migration {
    public function up(): void
    {
        $this->schema()->create('job_batches', function (Blueprint $table): void {
            $table->char('id', 36)->primary();
            $table->string('name', 191);
            $table->string('owner', 100);
            $table->integer('total');
            $table->integer('pending');
            $table->integer('failed')->default(0);
            $table->longText('options');                    // JSON: callback jobs, allow_failures, queue
            $table->bigInteger('created_at');
            $table->bigInteger('cancelled_at')->nullable();
            $table->bigInteger('caught_at')->nullable();
            $table->bigInteger('finished_at')->nullable();
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('job_batches');
    }
};
