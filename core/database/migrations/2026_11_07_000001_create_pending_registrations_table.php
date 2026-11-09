<?php

declare(strict_types=1);

use Kestello\Framework\Database\Blueprint;
use Kestello\Framework\Database\Migration;

/**
 * Email sign-ups waiting for their link to be opened (Kestello\Cms\Auth\Registrations).
 * The account is only created then, so an address nobody proved never becomes one.
 */
return new class extends Migration {
    public function up(): void
    {
        $this->schema()->create('pending_registrations', function (Blueprint $table): void {
            $table->id();
            $table->string('email', 255)->unique();
            $table->char('token_hash', 64)->unique();       // sha256 of the emailed token
            $table->string('ip', 45)->nullable();
            $table->bigInteger('created_at');
            $table->bigInteger('expires_at');
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('pending_registrations');
    }
};
