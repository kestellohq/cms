<?php

declare(strict_types=1);

use Kestello\Framework\Database\Blueprint;
use Kestello\Framework\Database\Migration;

/** The jobs a batch has counted, so a job that runs twice (a worker died after it finished) is counted once. */
return new class extends Migration {
    public function up(): void
    {
        $this->schema()->create('job_batch_jobs', function (Blueprint $table): void {
            $table->char('batch_id', 36);
            $table->char('job_uuid', 36);
            $table->primary('batch_id', 'job_uuid');
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('job_batch_jobs');
    }
};
