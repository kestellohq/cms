<?php

declare(strict_types=1);

namespace Kestello\Cms\Package\Update;

/**
 * The updates that finished, and the ways back from them:
 * storage/framework/updates.json, newest first. Switching back needs the
 * backup taken before the update and the old code still on disk (the
 * previous core release, or the old package folder).
 */
final class UpdateHistory
{
    private const int KEEP = 20;

    public function __construct(private readonly string $storage) {}

    /**
     * @param array{owner: string, from: string, to: string, backup: string, previous?: ?string, aside?: ?string, event?: string, actor?: ?int} $entry
     */
    public function add(array $entry): void
    {
        $all = $this->all();
        array_unshift($all, $entry + ['event' => 'updated', 'previous' => null, 'aside' => null, 'actor' => null, 'at' => time()]);
        $this->write(array_slice($all, 0, self::KEEP));
    }

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        $file = $this->file();
        $list = is_file($file) ? json_decode((string) @file_get_contents($file), true) : null;

        return is_array($list) ? array_values(array_filter($list, static fn($e): bool => is_array($e) && is_string($e['owner'] ?? null) && is_string($e['to'] ?? null))) : [];
    }

    /**
     * The update that brought $owner to the version it runs now, if that is
     * the newest thing that happened to it (switching back twice is not a thing).
     *
     * @return array<string, mixed>|null
     */
    public function lastUpdate(string $owner, string $running): ?array
    {
        foreach ($this->all() as $entry) {
            if ($entry['owner'] !== $owner) {
                continue;
            }

            return ($entry['event'] ?? 'updated') === 'updated' && $entry['to'] === $running ? $entry : null;
        }

        return null;
    }

    /** @param list<array<string, mixed>> $all */
    private function write(array $all): void
    {
        $dir = dirname($this->file());
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $tmp = $this->file() . '.' . bin2hex(random_bytes(4)) . '.tmp';
        file_put_contents($tmp, json_encode($all, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        @chmod($tmp, 0600);
        rename($tmp, $this->file());
    }

    private function file(): string
    {
        return $this->storage . '/framework/updates.json';
    }
}
