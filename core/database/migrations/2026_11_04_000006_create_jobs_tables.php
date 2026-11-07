<?php

declare(strict_types=1);

use Kestello\Framework\Database\Blueprint;
use Kestello\Framework\Database\Migration;

/** The database queue driver and failed jobs (kept for every driver). */
return new class extends Migration {
    public function up(): void
    {
        $this->schema()->create('jobs', function (Blueprint $table): void {
            $table->id();
            $table->string('queue', 50);
            $table->longText('payload');                    // JSON; never unserialize()d
            $table->integer('attempts')->default(0);
            $table->string('unique_key', 191)->nullable()->unique();
            $table->char('reservation', 32)->nullable();
            $table->bigInteger('reserved_until')->nullable();
            $table->bigInteger('available_at');
            $table->bigInteger('created_at');
            $table->index('queue', 'available_at');
        });

        $this->schema()->create('failed_jobs', function (Blueprint $table): void {
            $table->id();
            $table->char('uuid', 36)->unique();
            $table->string('queue', 50);
            $table->string('job', 255);                     // class name, for listing
            $table->string('owner', 100);
            $table->longText('payload');
            $table->longText('exception');
            $table->integer('attempts');
            $table->bigInteger('failed_at');
            $table->index('failed_at');
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('failed_jobs');
        $this->schema()->dropIfExists('jobs');
    }
};
