<?php

declare(strict_types=1);

namespace Kestello\Framework\Http;

use Kestello\Framework\Core\Request;

/**
 * The ID of the request being answered: on its response (X-Request-Id), its
 * log lines and its audit entries, so one failure can be followed from the
 * browser to the log.
 *
 * A proxy's or client's own ID is kept when it is safe to write in a log:
 * X-Request-Id (8 to 128 letters, digits and . _ : -), or else the trace ID
 * of a W3C traceparent. Otherwise a new one is made (32 hex characters, the
 * shape of a trace ID).
 *
 * One request per PHP process (PHP-FPM, the built-in server): the Kernel
 * begins it first thing, and Application::handle() for a request it is
 * handed alone; the console has none.
 */
final class RequestId
{
    private static ?string $current = null;

    /** Start answering a request: its ID, kept for current(). */
    public static function begin(Request $request): string
    {
        return self::$current = self::of($request);
    }

    /**
     * Start answering the request PHP was given, from its server variables:
     * before anything is booted, so even a plugin failing to boot is logged with it.
     *
     * @param array<string, mixed> $server $_SERVER
     */
    public static function beginFromServer(array $server): string
    {
        return self::$current = self::choose((string) ($server['HTTP_X_REQUEST_ID'] ?? ''), (string) ($server['HTTP_TRACEPARENT'] ?? ''));
    }

    /** The ID of the request being answered; null outside one (the console, a queue worker). */
    public static function current(): ?string
    {
        return self::$current;
    }

    /** Forget it: for tests, and long-running servers between requests. */
    public static function end(): void
    {
        self::$current = null;
    }

    /** The request's ID: the one it brought when that is safe, or a new one. */
    public static function of(Request $request): string
    {
        return self::choose($request->header('X-Request-Id'), $request->header('traceparent'));
    }

    private static function choose(string $requestId, string $traceparent): string
    {
        return self::accepted($requestId) ?? self::fromTraceparent($traceparent) ?? bin2hex(random_bytes(16));
    }

    /** An incoming X-Request-Id, when it can be written in a log as it is. */
    public static function accepted(string $id): ?string
    {
        return preg_match('/\A[A-Za-z0-9._:\-]{8,128}\z/', $id) === 1 ? $id : null;
    }

    /** The trace ID of a W3C traceparent ("00-<32 hex>-<16 hex>-<2 hex>"), unless it is invalid. */
    public static function fromTraceparent(string $header): ?string
    {
        if (preg_match('/\A([0-9a-f]{2})-([0-9a-f]{32})-([0-9a-f]{16})-([0-9a-f]{2})(?:-.*)?\z/', strtolower(trim($header)), $m) !== 1) {
            return null;
        }
        // version ff is invalid, and so are all-zero IDs; version 00 has nothing after the flags
        if ($m[1] === 'ff' || $m[2] === str_repeat('0', 32) || $m[3] === str_repeat('0', 16) || ($m[1] === '00' && strlen(trim($header)) !== 55)) {
            return null;
        }

        return $m[2];
    }
}
