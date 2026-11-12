<?php

declare(strict_types=1);

namespace Kestello\Framework\Log;

use Kestello\Framework\Core\ExecutionContext;
use Kestello\Framework\Http\RequestId;
use Throwable;

/**
 * Core's log: one JSON object per line, with the time, the level, the
 * message, what it came from (core, plugin:<slug>, theme:<slug>), the
 * request it happened in and any context:
 *
 *   {"time":"2026-11-12T10:00:00+00:00","level":"error","message":"SMTP send failed","owner":"core","request_id":"4bf9…","context":{"host":"mail.example.com"}}
 *
 * Written to storage/logs/<date>.log, or to stderr (KESTELLO_LOG=stderr, the
 * production Docker image's default), where the container collects it.
 * Context keys that look like secrets are redacted, and an exception in the
 * context becomes its class, message, file, line and trace.
 *
 * Logging never throws: when the line can't be written, it goes to PHP's own
 * error log instead.
 *
 * Code logs through Log (Log::warning(…)), which the Application points at
 * the site's Logger.
 */
final class Logger
{
    public const string STDERR = 'stderr';

    /** Keys whose values are never written. */
    private const string SECRET_KEYS = '/pass(word)?|token|secret|authorization|cookie|api_?key|private_?key/i';

    private const int MAX_DEPTH = 4;

    /** PHP-FPM splits a worker's output line at log_limit (8192 bytes in the Docker image). */
    public const int STDERR_LINE = 8000;

    /** @param string $target a folder for daily files, or self::STDERR */
    public function __construct(
        private readonly string $target,
        private readonly LogLevel $level = LogLevel::DEBUG,
    ) {}

    /**
     * The site's logger from its environment: KESTELLO_LOG (a folder is
     * the default; "stderr"), KESTELLO_LOG_LEVEL (debug with app.debug on,
     * info otherwise).
     *
     * @param array<string, string> $env usually getenv()
     */
    public static function fromEnvironment(string $folder, array $env, bool $debug): self
    {
        $level = LogLevel::tryFrom(strtolower(trim((string) ($env['KESTELLO_LOG_LEVEL'] ?? '')))) ?? ($debug ? LogLevel::DEBUG : LogLevel::INFO);

        return new self(strtolower(trim((string) ($env['KESTELLO_LOG'] ?? ''))) === self::STDERR ? self::STDERR : $folder, $level);
    }

    public function target(): string
    {
        return $this->target;
    }

    public function level(): LogLevel
    {
        return $this->level;
    }

    /**
     * @param array<string, mixed> $context
     * @param string|null          $owner   what it came from; the code running now when null
     */
    public function log(LogLevel $level, string $message, array $context = [], ?string $owner = null): void
    {
        if (!$level->atLeast($this->level)) {
            return;
        }
        $line = '';
        try {
            $line = self::line($level, $message, $context, $owner ?? ExecutionContext::current(), RequestId::current(), time(), $this->target === self::STDERR ? self::STDERR_LINE : null);
            if ($this->target === self::STDERR) {
                $written = @file_put_contents('php://stderr', $line . "\n");
            } else {
                if (!is_dir($this->target)) {
                    @mkdir($this->target, 0o755, true);
                }
                $written = @file_put_contents($this->target . '/' . date('Y-m-d') . '.log', $line . "\n", FILE_APPEND | LOCK_EX);
            }
            if ($written === false) {
                error_log($line);
            }
        } catch (Throwable $e) {
            error_log($line !== '' ? $line : "[kestello] {$level->value}: {$message} (the log could not write it: {$e->getMessage()})");
        }
    }

    /** @param array<string, mixed> $context */
    public function debug(string $message, array $context = []): void
    {
        $this->log(LogLevel::DEBUG, $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function info(string $message, array $context = []): void
    {
        $this->log(LogLevel::INFO, $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function warning(string $message, array $context = []): void
    {
        $this->log(LogLevel::WARNING, $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function error(string $message, array $context = []): void
    {
        $this->log(LogLevel::ERROR, $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function critical(string $message, array $context = []): void
    {
        $this->log(LogLevel::CRITICAL, $message, $context);
    }

    /**
     * One log line, without the line break. With $max, long strings in the
     * context (a stack trace) are shortened until the line fits, so a reader
     * of the output never gets half a JSON object.
     *
     * @param array<string, mixed> $context
     */
    public static function line(LogLevel $level, string $message, array $context, string $owner, ?string $requestId, int $time, ?int $max = null): string
    {
        $line = self::encode($level, $message, $context, $owner, $requestId, $time);
        if ($max === null || strlen($line) <= $max) {
            return $line;
        }
        $context = self::clean($context, 0);
        foreach ([2000, 500, 100] as $keep) {
            $line = self::encode($level, mb_strcut($message, 0, 2000), self::shorten($context, $keep), $owner, $requestId, $time);
            if (strlen($line) <= $max) {
                return $line;
            }
        }

        return self::encode($level, mb_strcut($message, 0, 1000), ['context' => '[too long for one line]'], $owner, $requestId, $time);
    }

    /**
     * @param array<array-key, mixed> $values
     * @return array<array-key, mixed>
     */
    private static function shorten(array $values, int $keep): array
    {
        foreach ($values as $key => $value) {
            if (is_array($value)) {
                $values[$key] = self::shorten($value, $keep);
            } elseif (is_string($value) && strlen($value) > $keep) {
                $values[$key] = mb_strcut($value, 0, $keep) . '…';
            }
        }

        return $values;
    }

    /** @param array<string, mixed> $context */
    private static function encode(LogLevel $level, string $message, array $context, string $owner, ?string $requestId, int $time): string
    {
        $entry = [
            'time' => gmdate('Y-m-d\TH:i:s\+00:00', $time),
            'level' => $level->value,
            'message' => $message,
            'owner' => $owner,
        ];
        if ($requestId !== null) {
            $entry['request_id'] = $requestId;
        }
        if ($context !== []) {
            $entry['context'] = self::clean($context, 0);
        }

        return json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR) ?: '{}';
    }

    /**
     * Delete daily log files older than $days. Returns how many went.
     */
    public static function prune(string $folder, int $days, ?int $now = null): int
    {
        $cutoff = gmdate('Y-m-d', ($now ?? time()) - max(1, $days) * 86400);
        $deleted = 0;
        foreach (glob(rtrim($folder, '/') . '/*.log') ?: [] as $file) {
            $day = basename($file, '.log');
            if (preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $day) === 1 && $day < $cutoff && @unlink($file)) {
                $deleted++;
            }
        }

        return $deleted;
    }

    /**
     * @param array<array-key, mixed> $values
     * @return array<array-key, mixed>
     */
    private static function clean(array $values, int $depth): array
    {
        $clean = [];
        foreach ($values as $key => $value) {
            if (is_string($key) && preg_match(self::SECRET_KEYS, $key) === 1 && $value !== null && $value !== '') {
                $clean[$key] = '[redacted]';
                continue;
            }
            $clean[$key] = match (true) {
                $value instanceof Throwable => self::exception($value),
                is_array($value) => $depth >= self::MAX_DEPTH ? '[array]' : self::clean($value, $depth + 1),
                $value instanceof \UnitEnum => $value instanceof \BackedEnum ? $value->value : $value->name,
                $value instanceof \DateTimeInterface => $value->format(DATE_ATOM),
                $value instanceof \Stringable => (string) $value,
                is_object($value) => '[' . $value::class . ']',
                is_resource($value) => '[resource]',
                default => $value,
            };
        }

        return $clean;
    }

    /** @return array<string, mixed> */
    private static function exception(Throwable $e): array
    {
        $data = ['class' => $e::class, 'message' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine(), 'trace' => $e->getTraceAsString()];
        if ($e->getPrevious() !== null) {
            $data['previous'] = $e->getPrevious()::class . ': ' . $e->getPrevious()->getMessage();
        }

        return $data;
    }
}
