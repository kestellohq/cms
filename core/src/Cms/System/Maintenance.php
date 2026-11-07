<?php

declare(strict_types=1);

namespace Kestello\Cms\System;

/**
 * Maintenance mode: a file in storage/framework, checked before the database
 * is touched.
 *
 *   visitors  updates: visitors get a 503 page, signed-in people, the admin
 *             and the API keep working
 *   all       restores: everyone gets the 503 (the database is being rebuilt)
 *
 * Every switch-on has an expiry, so a run that dies half way never leaves
 * the site down for good.
 */
final class Maintenance
{
    public const string VISITORS = 'visitors';
    public const string ALL = 'all';

    public function __construct(private readonly string $storage) {}

    /** @return string a bypass token: requests sending it in X-Kestello-Bypass get through (the update health check) */
    public function on(string $mode = self::VISITORS, string $reason = '', int $minutes = 30): string
    {
        $bypass = bin2hex(random_bytes(16));
        $dir = $this->storage . '/framework';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $data = json_encode([
            'mode' => $mode === self::ALL ? self::ALL : self::VISITORS,
            'reason' => mb_substr($reason, 0, 200),
            'started_at' => time(),
            'expires_at' => time() + max(1, $minutes) * 60,
            'bypass' => hash('sha256', $bypass),
        ], JSON_THROW_ON_ERROR);
        $tmp = $this->file() . '.' . bin2hex(random_bytes(4));
        file_put_contents($tmp, $data);
        @chmod($tmp, 0600);
        rename($tmp, $this->file());

        return $bypass;
    }

    /**
     * Whether background work (cron, queue workers) must wait: the whole site
     * is closed, as during a restore, when the database is being rebuilt.
     */
    public static function pausesBackgroundWork(): bool
    {
        $cms = \Kestello\Cms\Cms::instance();
        if ($cms === null) {
            return false;
        }

        return ((new self($cms->paths->storage()))->current()['mode'] ?? null) === self::ALL;
    }

    /**
     * Keep maintenance on for at least $minutes more, when it is on: long
     * work (a restore of a large site) calls this as it goes, so the site
     * doesn't open halfway through.
     */
    public function extend(int $minutes = 30): void
    {
        $data = is_file($this->file()) ? json_decode((string) @file_get_contents($this->file()), true) : null;
        if (!is_array($data)) {
            return;
        }
        $until = time() + max(1, $minutes) * 60;
        if ((int) ($data['expires_at'] ?? 0) >= $until - 60) {
            return;     // written less than a minute ago
        }
        $data['expires_at'] = $until;
        $tmp = $this->file() . '.' . bin2hex(random_bytes(4));
        file_put_contents($tmp, json_encode($data, JSON_THROW_ON_ERROR));
        @chmod($tmp, 0600);
        rename($tmp, $this->file());
    }

    public function off(): void
    {
        if (is_file($this->file())) {
            @unlink($this->file());
        }
    }

    /** @return array{bypass: string, mode: string, reason: string, started_at: int, expires_at: int}|null */
    public function current(): ?array
    {
        if (!is_file($this->file())) {
            return null;
        }
        $data = json_decode((string) @file_get_contents($this->file()), true);
        if (!is_array($data) || (int) ($data['expires_at'] ?? 0) < time()) {
            return null;
        }

        return [
            'bypass' => (string) ($data['bypass'] ?? ''),
            'mode' => ($data['mode'] ?? '') === self::ALL ? self::ALL : self::VISITORS,
            'reason' => (string) ($data['reason'] ?? ''),
            'started_at' => (int) ($data['started_at'] ?? 0),
            'expires_at' => (int) $data['expires_at'],
        ];
    }

    /** Whether this request gets the 503 page. */
    public function blocks(string $path, bool $hasSessionCookie, string $bypass = ''): bool
    {
        $state = $this->current();
        if ($state === null || str_starts_with($path, '/assets/')) {
            return false;
        }
        if ($bypass !== '' && $state['bypass'] !== '' && hash_equals($state['bypass'], hash('sha256', $bypass))) {
            return false;
        }
        if ($state['mode'] === self::ALL) {
            return true;
        }

        return !$hasSessionCookie && !str_starts_with($path, '/admin') && !str_starts_with($path, '/api/') && $path !== '/login';
    }

    /** The 503 page: self-contained, no database, no theme. */
    public function page(): string
    {
        return <<<'HTML'
        <!DOCTYPE html>
        <html lang="en">
        <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <meta name="robots" content="noindex">
        <title>Back in a moment</title>
        <style>
        :root { color-scheme: light dark; --bg: #f6f5f1; --ink: #1d1d1b; --quiet: #5c5b56; }
        @media (prefers-color-scheme: dark) { :root { --bg: #161615; --ink: #ecebe6; --quiet: #a3a29c; } }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; background: var(--bg); color: var(--ink);
               font: 1rem/1.5 system-ui, -apple-system, "Segoe UI", sans-serif; }
        main { max-width: 32rem; padding: 2rem 1.25rem; }
        h1 { font-size: 1.5rem; margin: 0 0 .5rem; letter-spacing: -.01em; }
        p { margin: 0; color: var(--quiet); }
        </style>
        </head>
        <body>
        <main>
        <h1>Back in a moment</h1>
        <p>The site is being updated. Please try again in a minute.</p>
        </main>
        </body>
        </html>
        HTML;
    }

    private function file(): string
    {
        return $this->storage . '/framework/maintenance.json';
    }
}
