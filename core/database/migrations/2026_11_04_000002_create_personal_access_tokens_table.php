<?php

declare(strict_types=1);

use Kestello\Framework\Database\Blueprint;
use Kestello\Framework\Database\Migration;

/**
 * Personal access tokens (hashed, scoped, expiring). Replaces the plaintext
 * users.api_token column, which is dropped: old tokens stop working.
 */
return new class extends Migration {
    public function up(): void
    {
        $this->schema()->create('personal_access_tokens', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id');
            $table->string('name', 100);
            $table->string('token_hash', 64)->unique();
            $table->text('scopes');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->string('last_used_ip', 45)->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index('user_id');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('CASCADE');
        });

        if ($this->schema()->hasColumn('users', 'api_token')) {
            $this->schema()->table('users', function (Blueprint $table): void {
                $table->dropUnique('api_token');
                $table->dropColumn('api_token');
            });
        }
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('personal_access_tokens');

        if (!$this->schema()->hasColumn('users', 'api_token')) {
            $this->schema()->table('users', function (Blueprint $table): void {
                $table->string('api_token', 64)->nullable()->unique();
            });
        }
    }
};
