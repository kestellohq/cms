<?php

declare(strict_types=1);

use Kestello\Framework\Database\Blueprint;
use Kestello\Framework\Database\Migration;

/**
 * Outgoing webhooks: the endpoints an administrator set up, and a log of
 * every delivery to them. Secrets are stored encrypted with the app key,
 * because each delivery is signed with them.
 */
return new class extends Migration {
    public function up(): void
    {
        $this->schema()->create('webhooks', function (Blueprint $t): void {
            $t->id();
            $t->string('name', 100);
            $t->string('url', 2048);
            $t->text('events');                             // JSON list of event names
            $t->text('secret');                             // encrypted
            $t->text('previous_secret')->nullable();        // encrypted; still signed with until previous_expires_at
            $t->datetime('previous_expires_at')->nullable();
            $t->boolean('active')->default(true);
            $t->integer('failure_streak')->default(0);      // deliveries in a row that gave up
            $t->string('disabled_reason', 255)->nullable();
            $t->datetime('last_success_at')->nullable();
            $t->datetime('last_failure_at')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });
        $this->schema()->create('webhook_deliveries', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('webhook_id')->constrained('webhooks')->cascadeOnDelete();
            $t->char('event_id', 36);                       // the same for every webhook that gets one event
            $t->string('event', 100);
            $t->text('payload');
            $t->string('status', 12);                       // pending, sending, succeeded, retrying, failed
            $t->integer('attempts')->default(0);
            $t->integer('response_status')->nullable();
            $t->text('response_body')->nullable();          // the start of it, with secrets masked
            $t->string('error', 500)->nullable();
            $t->integer('duration_ms')->nullable();
            $t->datetime('next_attempt_at')->nullable();
            $t->datetime('created_at')->nullable();
            $t->datetime('delivered_at')->nullable();
            $t->index('webhook_id', 'id');
            $t->index('status', 'next_attempt_at');
            $t->index('created_at');
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('webhook_deliveries');
        $this->schema()->dropIfExists('webhooks');
    }
};
