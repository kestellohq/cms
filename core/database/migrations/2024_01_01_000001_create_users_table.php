<?php

declare(strict_types=1);

use Kestello\Framework\Database\Blueprint;
use Kestello\Framework\Database\Migration;

/**
 * Create the users table.
 */
final class CreateUsersTable extends Migration
{
    public function up(): void
    {
        $this->schema()->create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('username', 50)->unique();
            $table->string('email', 255)->unique();
            $table->string('password', 255);
            $table->string('role', 20)->default('user');
            $table->boolean('is_active')->default(1);
            $table->text('bio')->nullable();
            $table->string('avatar', 255)->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('users');
    }
}
