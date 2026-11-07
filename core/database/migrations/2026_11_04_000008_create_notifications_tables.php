<?php

declare(strict_types=1);

use Kestello\Framework\Database\Blueprint;
use Kestello\Framework\Database\Migration;

/** In-app notifications (inbox) and per-user channel preferences. */
return new class extends Migration {
    public function up(): void
    {
        $this->schema()->create('notifications', function (Blueprint $table): void {
            $table->char('id', 36)->primary();               // UUIDv7: time-ordered
            $table->foreignId('user_id');
            $table->string('type', 191);
            $table->text('data');                             // JSON: title, body, url, icon…
            $table->bigInteger('read_at')->nullable();
            $table->bigInteger('created_at');
            $table->index('user_id', 'read_at');
            $table->index('created_at');
        });

        // Only switched-off channels are stored; everything else is on.
        $this->schema()->create('notification_preferences', function (Blueprint $table): void {
            $table->foreignId('user_id');
            $table->string('type', 191);
            $table->string('channel', 50);
            $table->boolean('enabled')->default(0);
            $table->primary('user_id', 'type', 'channel');
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('notification_preferences');
        $this->schema()->dropIfExists('notifications');
    }
};
