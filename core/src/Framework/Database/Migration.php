<?php

declare(strict_types=1);

namespace Kestello\Framework\Database;

/**
 * Base class for database migrations.
 */
abstract class Migration
{
    /**
     * Run the migration.
     */
    abstract public function up(): void;

    /**
     * Reverse the migration.
     */
    abstract public function down(): void;

    /**
     * Get a Schema instance for building tables.
     */
    protected function schema(): Schema
    {
        return new Schema(DatabaseManager::getConnection());
    }
}
