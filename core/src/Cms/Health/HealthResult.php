<?php

declare(strict_types=1);

namespace Kestello\Cms\Health;

/**
 * Outcome of one Site Health check.
 *
 *   return HealthResult::good('Backups ran 3 hours ago.');
 *   return HealthResult::recommended('No backup in 8 days.', action: ['Open backups', '/admin/backups']);
 *   return HealthResult::critical('Backups have never run.');
 *
 * @api
 */
final readonly class HealthResult
{
    public const string GOOD = 'good';
    public const string RECOMMENDED = 'recommended';
    public const string CRITICAL = 'critical';

    /**
     * @param array<string, scalar|null> $details shown in "more info" and the debug report (never secrets)
     * @param array{0: string, 1: string}|null $action [label, url]
     */
    private function __construct(
        public string $status,
        public string $message,
        public array $details = [],
        public ?array $action = null,
    ) {}

    /** @param array<string, scalar|null> $details */
    public static function good(string $message, array $details = []): self
    {
        return new self(self::GOOD, $message, $details);
    }

    /** @param array<string, scalar|null> $details @param array{0: string, 1: string}|null $action */
    public static function recommended(string $message, array $details = [], ?array $action = null): self
    {
        return new self(self::RECOMMENDED, $message, $details, $action);
    }

    /** @param array<string, scalar|null> $details @param array{0: string, 1: string}|null $action */
    public static function critical(string $message, array $details = [], ?array $action = null): self
    {
        return new self(self::CRITICAL, $message, $details, $action);
    }

    public static function rank(string $status): int
    {
        return match ($status) {
            self::CRITICAL => 2, self::RECOMMENDED => 1, default => 0,
        };
    }
}
