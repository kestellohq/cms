<?php

declare(strict_types=1);

use Kestello\Framework\Database\Blueprint;
use Kestello\Framework\Database\Migration;

/**
 * Password resets, two-factor methods and recovery codes, and the audit log.
 * Also lowercases stored emails (lookups are by normalized email from now on).
 */
return new class extends Migration {
    public function up(): void
    {
        $this->normalizeEmails();

        $this->schema()->create('password_resets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id');
            $table->char('token_hash', 64)->unique();      // sha256 of the emailed token
            $table->string('ip', 45)->nullable();
            $table->bigInteger('created_at');
            $table->bigInteger('expires_at');
            $table->index('user_id');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('CASCADE');
        });

        $this->schema()->create('mfa_methods', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id');
            $table->string('type', 20);                     // totp (passkeys etc. later, also from plugins)
            $table->text('secret');                         // sealed with the app key, bound to the user
            $table->string('label', 100)->nullable();
            $table->bigInteger('created_at');
            $table->bigInteger('confirmed_at')->nullable(); // null = enrollment not finished
            $table->bigInteger('last_used_at')->nullable();
            $table->bigInteger('last_used_step')->nullable(); // TOTP replay protection
            $table->index('user_id');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('CASCADE');
        });

        $this->schema()->create('mfa_recovery_codes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id');
            $table->char('code_hash', 64);                  // HMAC with an app subkey
            $table->bigInteger('used_at')->nullable();
            $table->index('user_id');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('CASCADE');
        });

        // No foreign key on actor/subject: entries outlive deleted users.
        $this->schema()->create('audit_log', function (Blueprint $table): void {
            $table->id();
            $table->bigInteger('occurred_at');
            $table->string('event', 100);
            $table->bigInteger('actor_id')->nullable();
            $table->string('subject_type', 50)->nullable();
            $table->string('subject_id', 100)->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->string('request_id', 32)->nullable();
            $table->string('owner', 100);                   // core, plugin:<slug>
            $table->text('data')->nullable();               // JSON, secrets redacted
            $table->index('occurred_at');
            $table->index('event');
            $table->index('actor_id');
            $table->index('subject_type', 'subject_id');
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('audit_log');
        $this->schema()->dropIfExists('mfa_recovery_codes');
        $this->schema()->dropIfExists('mfa_methods');
        $this->schema()->dropIfExists('password_resets');
    }

    private function normalizeEmails(): void
    {
        $db = \Kestello\Framework\Database\DatabaseManager::getConnection();
        $seen = [];
        foreach ($db->table('users')->select('id', 'email')->orderBy('id')->get() as $user) {
            $normalized = mb_strtolower(trim((string) $user['email']));
            if (isset($seen[$normalized])) {
                throw new RuntimeException("Users #{$seen[$normalized]} and #{$user['id']} have the same email ignoring case ({$normalized}). Change one of them, then migrate again.");
            }
            $seen[$normalized] = $user['id'];
            if ($normalized !== $user['email']) {
                $db->table('users')->where('id', $user['id'])->update(['email' => $normalized]);
            }
        }
    }
};
