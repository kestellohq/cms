<?php

declare(strict_types=1);

namespace Kestello\Cms\Extension;

/**
 * Packages suspended by safe mode after crashing the site.
 *
 * Stored as a small JSON file rather than in the database on purpose:
 * it is written from shutdown handlers after fatal errors, when the
 * database may be the very thing that failed.
 */
final class SuspensionList
{
    /** @var array<string, array{reason: string, at: string}>|null */
    private ?array $entries = null;

    public function __construct(
        private readonly string $file,
    ) {}

    /**
     * @return array<string, array{reason: string, at: string}> owner => details
     */
    public function all(): array
    {
        if ($this->entries === null) {
            $data = is_file($this->file) ? json_decode((string) file_get_contents($this->file), true) : [];
            $this->entries = is_array($data) ? $data : [];
        }

        return $this->entries;
    }

    public function isSuspended(string $owner): bool
    {
        return isset($this->all()[$owner]);
    }

    public function reason(string $owner): ?string
    {
        return $this->all()[$owner]['reason'] ?? null;
    }

    /** Forget what was read, so the next read sees other processes' changes. */
    public function refresh(): void
    {
        $this->entries = null;
    }

    public function suspend(string $owner, string $reason): void
    {
        $this->change(static function (array $entries) use ($owner, $reason): array {
            $entries[$owner] = ['reason' => mb_substr($reason, 0, 2000), 'at' => date('c')];
            return $entries;
        });
    }

    /**
     * Undo the suspensions made since $before was read with all() (an update
     * that rolled back: its failure suspended packages the old version runs
     * fine). Changes others made meanwhile, such as a suspension lifted in
     * the admin, are kept.
     *
     * @param array<string, array{reason: string, at: string}> $before
     */
    public function restore(array $before): void
    {
        $this->change(static function (array $entries) use ($before): array {
            foreach ($entries as $owner => $entry) {
                if (!isset($before[$owner])) {
                    unset($entries[$owner]);
                } elseif ($before[$owner] !== $entry) {
                    $entries[$owner] = $before[$owner];
                }
            }
            return $entries;
        });
    }

    public function clear(string $owner): void
    {
        $this->change(static function (array $entries) use ($owner): array {
            unset($entries[$owner]);
            return $entries;
        });
    }

    /**
     * Read, change and write the list under a lock, so two processes (a
     * crash handler and an update) can't each write over the other.
     *
     * @param \Closure(array<string, array{reason: string, at: string}>): array<string, array{reason: string, at: string}> $change
     */
    private function change(\Closure $change): void
    {
        $dir = dirname($this->file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $lock = @fopen($this->file . '.lock', 'c');
        if ($lock !== false) {
            flock($lock, LOCK_EX);
        }
        try {
            $this->refresh();   // start from the file: another process may have changed it
            $before = $this->all();
            $after = $change($before);
            if ($after !== $before) {
                $this->write($after);
            }
        } finally {
            if ($lock !== false) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }

    /**
     * @param array<string, array{reason: string, at: string}> $entries
     */
    private function write(array $entries): void
    {
        $this->entries = $entries;
        $dir = dirname($this->file);

        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $tmp = $this->file . '.' . getmypid() . '.tmp';
        file_put_contents($tmp, json_encode($entries, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
        rename($tmp, $this->file);
    }
}
