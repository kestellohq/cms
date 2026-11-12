<?php

declare(strict_types=1);

use Kestello\Framework\Database\Blueprint;
use Kestello\Framework\Database\Migration;

/**
 * The people who sign in. Emails are stored lower-cased (Kestello\Cms\Auth\Emails).
 */
return new class extends Migration {
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
            $table->string('locale', 12)->default('');      // the language of their emails; '' is the site's (PersonLanguage)
            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('users');
    }
};
