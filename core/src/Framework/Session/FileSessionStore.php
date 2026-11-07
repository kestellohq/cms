<?php

declare(strict_types=1);

namespace Kestello\Framework\Session;

/**
 * Sessions as files in storage/sessions (one per hashed id). Works on any
 * host; no device list (that needs the database or Redis store).
 */
final class FileSessionStore extends AbstractSessionStore
{
    public function __construct(private readonly string $directory, int $lifetime = 7200)
    {
        parent::__construct($lifetime);
    }

    public function name(): string
    {
        return 'file';
    }

    public function tracksDevices(): bool
    {
        return false;
    }

    public function read(string $id): string|false
    {
        $path = $this->path($id);
        if (!is_file($path) || filemtime($path) + $this->lifetime < time()) {
            return '';
        }

        return (string) @file_get_contents($path);
    }

    public function write(string $id, string $data): bool
    {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0770, true) && !is_dir($this->directory)) {
            return false;
        }

        $path = $this->path($id);
        $tmp = $path . '.' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, $data, LOCK_EX) === false) {
            return false;
        }
        @chmod($tmp, 0600);

        return @rename($tmp, $path);
    }

    public function destroy(string $id): bool
    {
        $path = $this->path($id);

        return !is_file($path) || @unlink($path);
    }

    public function gc(int $max_lifetime): int|false
    {
        $removed = 0;
        foreach (glob($this->directory . '/sess_*') ?: [] as $file) {
            if (filemtime($file) + $max_lifetime < time() && @unlink($file)) {
                $removed++;
            }
        }

        return $removed;
    }

    public function validateId(string $id): bool
    {
        $path = $this->path($id);

        return is_file($path) && filemtime($path) + $this->lifetime >= time();
    }

    public function updateTimestamp(string $id, string $data): bool
    {
        $path = $this->path($id);
        if (is_file($path) && filemtime($path) > time() - self::TOUCH_INTERVAL) {
            return true;
        }

        return @touch($path);
    }

    public function forUser(int $userId): array
    {
        return [];
    }

    public function destroyForUser(int $userId, ?string $exceptHashedId = null): int
    {
        return 0;
    }

    public function destroyHashed(string $hashedId): bool
    {
        if (preg_match('/^[a-f0-9]{64}$/', $hashedId) !== 1) {
            return false;
        }
        $path = $this->directory . '/sess_' . $hashedId;

        return !is_file($path) || @unlink($path);
    }

    private function path(string $id): string
    {
        return $this->directory . '/sess_' . self::hash($id);
    }
}
