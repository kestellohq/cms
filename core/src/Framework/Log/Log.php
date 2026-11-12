<?php

declare(strict_types=1);

namespace Kestello\Framework\Log;

/**
 * Core's log, from anywhere: Log::warning('the theme could not be read', ['theme' => $slug]).
 * Each line says what it came from (the plugin running now) and which
 * request it happened in, so plugins log the same way core does.
 *
 * The Application points it at the site's Logger. Before that (early in a
 * request, a bare test), lines go to PHP's error log, in the same format.
 */
final class Log
{
    private static ?Logger $logger = null;

    public static function use(?Logger $logger): void
    {
        self::$logger = $logger;
    }

    public static function logger(): ?Logger
    {
        return self::$logger;
    }

    /**
     * @param array<string, mixed> $context
     * @param string|null          $owner   what it came from, when that isn't the code running now
     */
    public static function write(LogLevel $level, string $message, array $context = [], ?string $owner = null): void
    {
        if (self::$logger !== null) {
            self::$logger->log($level, $message, $context, $owner);

            return;
        }
        try {
            error_log(Logger::line($level, $message, $context, $owner ?? \Kestello\Framework\Core\ExecutionContext::current(), \Kestello\Framework\Http\RequestId::current(), time()));
        } catch (\Throwable) {
            error_log("[kestello] {$level->value}: {$message}");
        }
    }

    /** @param array<string, mixed> $context */
    public static function debug(string $message, array $context = []): void
    {
        self::write(LogLevel::DEBUG, $message, $context);
    }

    /** @param array<string, mixed> $context */
    public static function info(string $message, array $context = []): void
    {
        self::write(LogLevel::INFO, $message, $context);
    }

    /** @param array<string, mixed> $context */
    public static function warning(string $message, array $context = []): void
    {
        self::write(LogLevel::WARNING, $message, $context);
    }

    /** @param array<string, mixed> $context */
    public static function error(string $message, array $context = []): void
    {
        self::write(LogLevel::ERROR, $message, $context);
    }

    /** @param array<string, mixed> $context */
    public static function critical(string $message, array $context = []): void
    {
        self::write(LogLevel::CRITICAL, $message, $context);
    }
}
