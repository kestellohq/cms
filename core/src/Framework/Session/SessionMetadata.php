<?php

declare(strict_types=1);

namespace Kestello\Framework\Session;

/** Who and where a session belongs to, captured when it is written. */
final readonly class SessionMetadata
{
    public function __construct(
        public ?int $userId = null,
        public string $ip = '',
        public string $userAgent = '',
    ) {}

    public static function hashId(string $id): string
    {
        return hash('sha256', $id);
    }
}
