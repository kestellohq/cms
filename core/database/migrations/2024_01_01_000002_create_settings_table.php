<?php

declare(strict_types=1);

use Kestello\Framework\Database\Blueprint;
use Kestello\Framework\Database\Migration;

/**
 * Create the settings table for key-value site configuration.
 */
final class CreateSettingsTable extends Migration
{
    public function up(): void
    {
        $this->schema()->create('settings', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 100)->unique();
            $table->text('value')->nullable();
            $table->string('group', 50)->default('general');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('settings');
    }
}
