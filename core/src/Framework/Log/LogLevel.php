<?php

declare(strict_types=1);

namespace Kestello\Framework\Log;

/**
 * Enumeration of supported log levels, ordered by severity.
 */
enum LogLevel: string
{
    case DEBUG    = 'DEBUG';
    case INFO     = 'INFO';
    case WARNING  = 'WARNING';
    case ERROR    = 'ERROR';
    case CRITICAL = 'CRITICAL';
}
