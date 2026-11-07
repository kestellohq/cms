<?php

declare(strict_types=1);

use Kestello\Framework\Database\Blueprint;
use Kestello\Framework\Database\Migration;

/** External sign-in identities (Google, GitHub, OIDC…) linked to users. */
return new class extends Migration {
    public function up(): void
    {
        $this->schema()->create('user_identities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id');
            $table->string('provider', 32);
            $table->string('subject', 191);                 // the provider's stable user id
            $table->string('email', 255)->nullable();       // as reported at link time (display only)
            $table->bigInteger('created_at');
            $table->bigInteger('last_used_at')->nullable();
            $table->uniqueIndex('provider', 'subject');
            $table->index('user_id');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('CASCADE');
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('user_identities');
    }
};
