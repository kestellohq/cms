<?php

declare(strict_types=1);

namespace Kestello\Framework\Log;

/**
 * Simple file-based logger.
 *
 * Writes log entries to date-stamped files inside the configured log directory
 * using the format: [YYYY-MM-DD HH:MM:SS] LEVEL: Message {context}
 */
final class Logger
{
    /**
     * @param string $logDirectory Absolute path to the log storage directory (e.g. storage/logs).
     */
    public function __construct(
        private readonly string $logDirectory,
    ) {
        if (!is_dir($this->logDirectory)) {
            mkdir($this->logDirectory, 0775, true);
        }
    }

    /**
     * Write a log entry.
     *
     * @param LogLevel             $level   Severity level.
     * @param string               $message Human-readable message.
     * @param array<string, mixed> $context Additional context data.
     *
     * @return void
     */
    public function log(LogLevel $level, string $message, array $context = []): void
    {
        $timestamp = date('Y-m-d H:i:s');
        $entry     = sprintf('[%s] %s: %s', $timestamp, $level->value, $message);

        if ($context !== []) {
            $entry .= ' ' . json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        $entry .= PHP_EOL;

        $filename = date('Y-m-d') . '.log';
        $path     = $this->logDirectory . DIRECTORY_SEPARATOR . $filename;

        file_put_contents($path, $entry, FILE_APPEND | LOCK_EX);
    }

    /**
     * Log a DEBUG level message.
     *
     * @param string               $message Log message.
     * @param array<string, mixed> $context Additional context.
     *
     * @return void
     */
    public function debug(string $message, array $context = []): void
    {
        $this->log(LogLevel::DEBUG, $message, $context);
    }

    /**
     * Log an INFO level message.
     *
     * @param string               $message Log message.
     * @param array<string, mixed> $context Additional context.
     *
     * @return void
     */
    public function info(string $message, array $context = []): void
    {
        $this->log(LogLevel::INFO, $message, $context);
    }

    /**
     * Log a WARNING level message.
     *
     * @param string               $message Log message.
     * @param array<string, mixed> $context Additional context.
     *
     * @return void
     */
    public function warning(string $message, array $context = []): void
    {
        $this->log(LogLevel::WARNING, $message, $context);
    }

    /**
     * Log an ERROR level message.
     *
     * @param string               $message Log message.
     * @param array<string, mixed> $context Additional context.
     *
     * @return void
     */
    public function error(string $message, array $context = []): void
    {
        $this->log(LogLevel::ERROR, $message, $context);
    }

    /**
     * Log a CRITICAL level message.
     *
     * @param string               $message Log message.
     * @param array<string, mixed> $context Additional context.
     *
     * @return void
     */
    public function critical(string $message, array $context = []): void
    {
        $this->log(LogLevel::CRITICAL, $message, $context);
    }
}
