<?php

declare(strict_types=1);

use Kestello\Framework\Database\Blueprint;
use Kestello\Framework\Database\Migration;

/**
 * Changes to the site's emails made in Settings → Emails, one per email and
 * language (Kestello\Cms\Mail\EmailEdits). An email without one is sent as
 * its code writes it.
 */
return new class extends Migration {
    public function up(): void
    {
        $this->schema()->create('email_edits', function (Blueprint $table): void {
            $table->id();
            $table->string('email', 120);                   // the named email, such as "auth.password_reset"
            $table->string('locale', 12);                   // the language this edit is for, such as "sr"
            $table->string('subject', 255)->default('');    // '' keeps the email's own
            $table->mediumText('blocks');                   // JSON, the shape EmailEdit checks
            $table->bigInteger('updated_by')->nullable();   // the user who saved it last
            $table->timestamps();
            $table->uniqueIndex('email', 'locale');
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('email_edits');
    }
};
