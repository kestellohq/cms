<?php

declare(strict_types=1);

use Kestello\Framework\Database\DatabaseManager;
use Kestello\Framework\Database\Migration;

/**
 * audit_log.request_id holds the request's ID (RequestId), which can be one
 * a proxy sent: up to 128 characters, such as a UUID. It was 32, which
 * MySQL and PostgreSQL refuse to store longer values in. SQLite does not
 * enforce a length, so it has nothing to change.
 */
return new class extends Migration {
    public function up(): void
    {
        $db = DatabaseManager::getConnection();
        match ($db->driver()) {
            'mysql' => $db->raw('ALTER TABLE `audit_log` MODIFY `request_id` VARCHAR(128) NULL'),
            'pgsql' => $db->raw('ALTER TABLE "audit_log" ALTER COLUMN "request_id" TYPE VARCHAR(128)'),
            default => null,
        };
    }

    public function down(): void
    {
        // Narrowing it again could cut IDs already stored; it stays 128.
    }
};
