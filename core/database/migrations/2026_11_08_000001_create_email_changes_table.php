<?php

declare(strict_types=1);

use Kestello\Framework\Database\Blueprint;
use Kestello\Framework\Database\Migration;

/**
 * A new email address waiting for its link to be opened (Kestello\Cms\Auth\EmailChanges).
 * The account keeps its old address until then, so an address nobody proved is never an account's.
 */
return new class extends Migration {
    public function up(): void
    {
        $this->schema()->create('email_changes', function (Blueprint $table): void {
            $table->id();
            $table->bigInteger('user_id')->unique();         // one waiting change per account: a new request replaces it
            $table->string('email', 255);
            $table->char('token_hash', 64)->unique();        // sha256 of the emailed token
            $table->bigInteger('created_at');
            $table->bigInteger('expires_at');
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('email_changes');
    }
};
