<?php

declare(strict_types=1);

namespace Kestello\Cms\Console;

/**
 * Reading a password on the command line: from the terminal without echo, or one line of piped
 * input. Never from an argument, which would land in shell history and the process list.
 *
 *   $prompt = new PasswordPrompt($stream);   // null: STDIN
 *   $prompt->read('New password')            // null when nothing was given
 *   $prompt->interactive()                   // a person at a terminal (ask twice)
 *   PasswordPrompt::generate()               // 20 characters without look-alikes
 *
 * @internal
 */
final readonly class PasswordPrompt
{
    /** @param resource|null $input tests pass a stream */
    public function __construct(private mixed $input = null) {}

    public function read(string $prompt): ?string
    {
        $stream = $this->input ?? STDIN;
        $tty = $this->interactive();
        if ($tty) {
            fwrite(STDOUT, $prompt . ': ');
            shell_exec('stty -echo 2>/dev/null');
        }
        try {
            $line = fgets($stream);
        } finally {
            if ($tty) {
                shell_exec('stty echo 2>/dev/null');
                fwrite(STDOUT, PHP_EOL);
            }
        }
        $line = $line === false ? '' : rtrim($line, "\r\n");

        return $line === '' ? null : $line;
    }

    public function interactive(): bool
    {
        return $this->input === null && function_exists('stream_isatty') && stream_isatty(STDIN);
    }

    /** 20 characters from an alphabet without look-alikes (0/O, 1/l/I). */
    public static function generate(): string
    {
        $alphabet = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $out = '';
        for ($i = 0; $i < 20; $i++) {
            $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $out;
    }
}
