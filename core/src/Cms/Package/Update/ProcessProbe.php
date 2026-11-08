<?php

declare(strict_types=1);

namespace Kestello\Cms\Package\Update;

/**
 * Runs "bin/cms update:probe <owner>" in a new PHP process: no network
 * needed. Unavailable where proc_open is disabled (some shared hosts).
 */
final class ProcessProbe implements HealthProbe
{
    public const int TIMEOUT = 120;

    public function __construct(private readonly string $root, private readonly ?string $php = null, private readonly int $timeout = self::TIMEOUT) {}

    public function available(): bool
    {
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));

        return function_exists('proc_open') && !in_array('proc_open', $disabled, true) && is_file($this->binary()) && is_file($this->root . '/bin/cms');
    }

    public function run(string $owner, string $bypass): array
    {
        $process = @proc_open($this->command($owner), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->root);
        if (!is_resource($process)) {
            return ['probe_error' => 'The check could not start a PHP process.'];
        }
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $out = '';
        $err = '';
        $deadline = microtime(true) + $this->timeout;
        while (true) {
            $out .= (string) stream_get_contents($pipes[1]);
            $err .= (string) stream_get_contents($pipes[2]);
            $status = proc_get_status($process);
            if (!$status['running']) {
                $out .= (string) stream_get_contents($pipes[1]);
                $err .= (string) stream_get_contents($pipes[2]);
                break;
            }
            if (microtime(true) > $deadline) {
                proc_terminate($process);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($process);
                return ['probe_error' => "The check did not finish within {$this->timeout} seconds."];
            }
            usleep(50_000);
        }
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        foreach (array_reverse(explode("\n", trim($out))) as $line) {
            $line = trim((string) preg_replace('/\e\[[0-9;]*m/', '', $line));
            if (str_starts_with($line, '{')) {
                $report = json_decode($line, true);
                if (is_array($report)) {
                    return $report;
                }
            }
        }

        return ['probe_error' => 'The site did not start after the update: ' . mb_substr(trim($err !== '' ? $err : $out), 0, 600)];
    }

    /**
     * The check gets a time limit of its own (PHP's, for time spent running
     * code): when the server stops the update that started it, nobody waits
     * for it any more, and it holds the update lock until it ends.
     *
     * @return list<string>
     */
    public function command(string $owner): array
    {
        return [$this->binary(), '-d', 'max_execution_time=' . $this->timeout, $this->root . '/bin/cms', 'update:probe', $owner];
    }

    private function binary(): string
    {
        if ($this->php !== null && $this->php !== '') {
            return $this->php;
        }

        return PHP_SAPI === 'cli' ? PHP_BINARY : PHP_BINDIR . '/php';
    }
}
