<?php

declare(strict_types=1);

namespace Kestello\Support;

use RuntimeException;

/**
 * Run an external program without a shell: the command is an argument list,
 * so nothing is ever interpolated or globbed.
 *
 *   $r = Process::run(['jpegoptim', '--strip-all', $path], timeout: 30);
 *   if ($r->ok()) { ... }
 *
 * Hosts that disable proc_open report available() === false; callers skip
 * the optional tool (optimizers, git, ffmpeg) instead of failing.
 *
 * @api
 */
final class Process
{
    public const int MAX_OUTPUT = 4_194_304;

    public static function available(): bool
    {
        if (!function_exists('proc_open')) {
            return false;
        }
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));

        return !in_array('proc_open', $disabled, true);
    }

    /** Absolute path of an executable on PATH, or null. */
    public static function which(string $name): ?string
    {
        if (preg_match('/^[A-Za-z0-9._+-]+$/', $name) !== 1) {
            return null;
        }
        foreach (explode(PATH_SEPARATOR, (string) getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin') as $dir) {
            $candidate = rtrim($dir, '/') . '/' . $name;
            if ($dir !== '' && is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param non-empty-list<string>    $command
     * @param array<string, string>|null $env  null = inherit
     */
    public static function run(array $command, ?string $cwd = null, ?array $env = null, float $timeout = 60.0, ?string $input = null): ProcessResult
    {
        if ($command === [] || !array_is_list($command)) {
            throw new \InvalidArgumentException('The command must be a non-empty list of arguments.');
        }
        foreach ($command as $arg) {
            if (!is_string($arg) || str_contains($arg, "\0")) {
                throw new \InvalidArgumentException('Command arguments must be strings without NUL bytes.');
            }
        }
        if (!self::available()) {
            throw new RuntimeException('Running external programs is disabled on this host (proc_open).');
        }

        $pipes = [];
        $proc = @proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd, $env);
        if (!is_resource($proc)) {
            throw new RuntimeException("Could not start \"{$command[0]}\".");
        }

        if ($input !== null && $input !== '') {
            fwrite($pipes[0], $input);
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $out = $err = '';
        $start = microtime(true);
        $timedOut = false;
        $status = proc_get_status($proc);
        while (true) {
            $read = [];
            foreach ([1, 2] as $i) {
                if (!feof($pipes[$i])) {
                    $read[] = $pipes[$i];
                }
            }
            if ($read === []) {
                break;
            }
            $w = $e = null;
            if (@stream_select($read, $w, $e, 0, 100_000) > 0) {
                foreach ($read as $r) {
                    $chunk = (string) fread($r, 65536);
                    if ($r === $pipes[1]) {
                        $out .= strlen($out) < self::MAX_OUTPUT ? $chunk : '';
                    } else {
                        $err .= strlen($err) < self::MAX_OUTPUT ? $chunk : '';
                    }
                }
            }
            if ($timeout > 0 && microtime(true) - $start > $timeout) {
                $timedOut = true;
                proc_terminate($proc, 15);
                usleep(200_000);
                if (proc_get_status($proc)['running']) {
                    proc_terminate($proc, 9);
                }
                break;
            }
            $status = proc_get_status($proc);
            if (!$status['running'] && feof($pipes[1]) && feof($pipes[2])) {
                break;
            }
        }
        $out .= (string) stream_get_contents($pipes[1]);
        $err .= (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        // proc_get_status reports the exit code only once; proc_close returns -1 after that.
        $code = $status['running'] ? -1 : (int) $status['exitcode'];
        $closed = proc_close($proc);
        if ($code === -1) {
            $code = $closed;
        }

        return new ProcessResult($command, $timedOut ? -1 : $code, substr($out, 0, self::MAX_OUTPUT), substr($err, 0, self::MAX_OUTPUT), $timedOut, microtime(true) - $start);
    }
}
