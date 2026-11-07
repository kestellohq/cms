<?php

declare(strict_types=1);

use Kestello\Framework\Database\Blueprint;
use Kestello\Framework\Database\Migration;

/**
 * Tables for the database cache store (and its locks) and database
 * sessions (hashed ids + device metadata).
 */
return new class extends Migration {
    public function up(): void
    {
        $this->schema()->create('cache', function (Blueprint $table): void {
            $table->string('key', 191)->primary();
            $table->longText('value');
            $table->bigInteger('expiration');
            $table->index('expiration');
        });

        $this->schema()->create('sessions', function (Blueprint $table): void {
            $table->char('id', 64)->primary();           // sha256 of the session id
            $table->foreignId('user_id')->nullable();
            $table->string('ip', 45);
            $table->string('user_agent', 500);
            $table->longText('payload');
            $table->bigInteger('created_at');
            $table->bigInteger('last_activity');
            $table->index('user_id');
            $table->index('last_activity');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('CASCADE');
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('sessions');
        $this->schema()->dropIfExists('cache');
    }
};
