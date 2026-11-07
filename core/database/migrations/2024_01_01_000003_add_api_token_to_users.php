<?php

declare(strict_types=1);

use Kestello\Framework\Database\Blueprint;
use Kestello\Framework\Database\Migration;

/**
 * Historical: plaintext API tokens on users. Replaced by personal access
 * tokens and dropped again in 2026_11_04_000002.
 */
final class AddApiTokenToUsers extends Migration
{
    public function up(): void
    {
        $this->schema()->table('users', function (Blueprint $table): void {
            $table->string('api_token', 64)->nullable()->unique();
        });
    }

    public function down(): void
    {
        $this->schema()->table('users', function (Blueprint $table): void {
            $table->dropUnique('api_token');
            $table->dropColumn('api_token');
        });
    }
}
