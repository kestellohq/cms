<?php

declare(strict_types=1);

namespace Kestello\Framework\Log;

/** How serious a log line is, least to most (the PSR-3 names core uses). */
enum LogLevel: string
{
    case DEBUG = 'debug';
    case INFO = 'info';
    case WARNING = 'warning';
    case ERROR = 'error';
    case CRITICAL = 'critical';

    public function rank(): int
    {
        return match ($this) {
            self::DEBUG => 0,
            self::INFO => 1,
            self::WARNING => 2,
            self::ERROR => 3,
            self::CRITICAL => 4,
        };
    }

    public function atLeast(self $other): bool
    {
        return $this->rank() >= $other->rank();
    }
}
