<?php

declare(strict_types=1);

namespace Kestello\Cms\Package\Update;

/**
 * What an update is doing, on disk, so a later request can finish or undo
 * it when PHP was stopped halfway (a host's time or memory limit, a server
 * restart). storage/framework/update.json, written before each step:
 *
 *   started    the database backup exists; nothing else changed yet
 *   switching  files are being switched and the new code may change the
 *              database: undoing means the old files and the backup
 *   confirmed  the site was checked and is healthy: finishing means
 *              keeping the new version
 *
 * The update lock tells a stopped update from a running one: while the
 * updater runs it holds the lock, and a process that dies lets go of it.
 */
final class UpdateJournal
{
    public const string STARTED = 'started';
    public const string SWITCHING = 'switching';
    public const string CONFIRMED = 'confirmed';

    /** Automatic tries to put the site back before it waits for someone. */
    public const int MAX_ATTEMPTS = 3;

    /**
     * Seconds after which a running update is unusually long: it checks the
     * site within minutes (ProcessProbe). Most likely the server stopped the
     * updater and its check is still running (it holds the lock until it ends).
     */
    public const int LONG = 900;

    public function __construct(private readonly string $storage) {}

    /**
     * @param array{kind: string, owner: string, from: string, to: string, backup: string, previous?: ?string, target: string, aside?: ?string, keep_target?: bool, suspended?: ?array<string, mixed>} $entry
     */
    public function begin(array $entry, string $step = self::STARTED): void
    {
        $this->write($entry + ['previous' => null, 'aside' => null, 'keep_target' => false, 'suspended' => null] + ['step' => $step, 'attempts' => 0, 'started_at' => time()]);
    }

    public function step(string $step): void
    {
        $entry = $this->current();
        if ($entry !== null) {
            $this->write(['step' => $step] + $entry);
        }
    }

    /** @return array<string, mixed>|null */
    public function current(): ?array
    {
        if (!is_file($this->file())) {
            return null;
        }
        $entry = json_decode((string) @file_get_contents($this->file()), true);
        if (!is_array($entry) || !is_string($entry['owner'] ?? null) || preg_match(UpdateProbe::OWNER, $entry['owner']) !== 1
            || !in_array($entry['step'] ?? null, [self::STARTED, self::SWITCHING, self::CONFIRMED], true)) {
            return null;
        }

        return $entry;
    }

    /** @return array<string, mixed>|null an update that stopped before it finished, with nobody working on it */
    public function stopped(): ?array
    {
        if (!is_file($this->file()) || Files::lockHeld($this->storage)) {
            return null;
        }

        return $this->current();
    }

    /** @return array<string, mixed>|null a stopped update the site still tries to put back by itself */
    public function pending(): ?array
    {
        $entry = $this->stopped();

        return $entry !== null && (int) $entry['attempts'] < self::MAX_ATTEMPTS ? $entry : null;
    }

    /** @return array<string, mixed>|null an update that has held the lock for longer than LONG */
    public function runningLong(?int $now = null): ?array
    {
        if (!is_file($this->file()) || !Files::lockHeld($this->storage)) {
            return null;
        }
        $entry = $this->current();

        return $entry !== null && (int) ($entry['started_at'] ?? 0) < ($now ?? time()) - self::LONG ? $entry : null;
    }

    /** Count one more try; returns how many there were. */
    public function attempt(): int
    {
        $entry = $this->current();
        if ($entry === null) {
            return 0;
        }
        $attempts = (int) $entry['attempts'] + 1;
        $this->write(['attempts' => $attempts] + $entry);

        return $attempts;
    }

    /** No more automatic tries: someone has to look (or choose Try again). */
    public function giveUp(): void
    {
        $entry = $this->current();
        if ($entry !== null) {
            $this->write(['attempts' => self::MAX_ATTEMPTS] + $entry);
        }
    }

    /** Let the next request try again. */
    public function retry(): void
    {
        $entry = $this->current();
        if ($entry !== null) {
            $this->write(['attempts' => 0] + $entry);
        }
    }

    public function clear(): void
    {
        if (is_file($this->file())) {
            @unlink($this->file());
        }
    }

    /** @param array<string, mixed> $entry */
    private function write(array $entry): void
    {
        $dir = dirname($this->file());
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $tmp = $this->file() . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (file_put_contents($tmp, json_encode($entry, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)) === false || !rename($tmp, $this->file())) {
            @unlink($tmp);
            throw new \RuntimeException('Could not write storage/framework/update.json.');
        }
        @chmod($this->file(), 0600);
    }

    private function file(): string
    {
        return $this->storage . '/framework/update.json';
    }
}
