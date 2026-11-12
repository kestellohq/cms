<?php

declare(strict_types=1);

namespace Kestello\Framework\Core;

/**
 * How many errors each part of the site hit, per day: core, a plugin
 * (plugin:<slug>), a theme (theme:<slug>), or the admin in the browser
 * (admin). With the last message of each, so Site Health can say "the
 * plugin Ledger failed 14 times today: …" without anyone reading logs.
 *
 * One small JSON file (errors.json, next to the logs), written under a lock;
 * the last 7 days are kept. Counting never throws: an error while counting
 * an error must not hide the first one.
 */
final class ErrorTally
{
    public const int DAYS = 7;

    private const int MESSAGE_LENGTH = 300;

    public function __construct(private readonly string $file) {}

    public static function in(string $logPath): self
    {
        return new self(rtrim($logPath, '/') . '/errors.json');
    }

    public function record(string $source, string $message, ?int $at = null): void
    {
        $at ??= time();
        try {
            if (!is_dir(dirname($this->file)) && !@mkdir(dirname($this->file), 0o755, true) && !is_dir(dirname($this->file))) {
                return;
            }
            $handle = @fopen($this->file, 'c+');
            if ($handle === false) {
                return;
            }
            try {
                flock($handle, LOCK_EX);
                $days = self::decode((string) stream_get_contents($handle));
                $day = gmdate('Y-m-d', $at);
                $entry = $days[$day][$source] ?? ['count' => 0];
                $days[$day][$source] = [
                    'count' => (int) $entry['count'] + 1,
                    'message' => mb_substr(trim(preg_replace('/\s+/', ' ', $message) ?? ''), 0, self::MESSAGE_LENGTH),
                    'at' => $at,
                ];
                krsort($days);
                $days = array_slice($days, 0, self::DAYS, true);
                ftruncate($handle, 0);
                rewind($handle);
                fwrite($handle, (string) json_encode($days, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
                fflush($handle);
            } finally {
                flock($handle, LOCK_UN);
                fclose($handle);
            }
        } catch (\Throwable) {
            // see the class comment
        }
    }

    /**
     * One day's errors, the most frequent source first.
     *
     * @return array<string, array{count: int, message: string, at: int}> source => tally
     */
    public function day(?int $at = null): array
    {
        $days = is_file($this->file) ? self::decode((string) @file_get_contents($this->file)) : [];
        $sources = $days[gmdate('Y-m-d', $at ?? time())] ?? [];
        uasort($sources, static fn(array $a, array $b): int => $b['count'] <=> $a['count']);

        return $sources;
    }

    /** @return array<string, array<string, array{count: int, message: string, at: int}>> */
    private static function decode(string $json): array
    {
        $data = json_decode($json, true);
        if (!is_array($data)) {
            return [];
        }
        $days = [];
        foreach ($data as $day => $sources) {
            if (!is_string($day) || !is_array($sources)) {
                continue;
            }
            foreach ($sources as $source => $t) {
                if (is_string($source) && is_array($t)) {
                    $days[$day][$source] = ['count' => (int) ($t['count'] ?? 0), 'message' => (string) ($t['message'] ?? ''), 'at' => (int) ($t['at'] ?? 0)];
                }
            }
        }

        return $days;
    }
}
