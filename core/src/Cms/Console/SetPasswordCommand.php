<?php

declare(strict_types=1);

namespace Kestello\Cms\Console;

use Kestello\Cms\Auth\AccountSecurity;
use Kestello\Cms\Auth\PasswordPolicy;
use Kestello\Cms\Cms;
use Kestello\Framework\Console\Command;
use Kestello\Framework\Database\QueryBuilder;

/**
 * Set someone's password from the server, for when they cannot use the
 * reset email (no mail set up, the only administrator locked out).
 *
 *   php bin/cms user:password ana                 asks twice, without echo
 *   php bin/cms user:password ana --generate      prints a new random password once
 *   printf '%s\n' "$PW" | php bin/cms user:password ana    reads one line when not a terminal
 *
 * Never takes the password as an argument: it would land in shell history
 * and the process list. Same rules as everywhere else (PasswordPolicy), and
 * the same consequences as a reset: every session and access token of that
 * person ends, and the change is audit-logged ("auth.password_changed", via cli).
 */
final class SetPasswordCommand extends Command
{
    protected string $name = 'user:password';
    protected string $description = 'Set a user\'s password: user:password <username|email> [--generate]';

    /** @param resource|null $input where the password is read from (tests pass a stream) */
    public function __construct(private readonly mixed $input = null) {}

    public function handle(array $arguments, array $options): int
    {
        $identifier = $this->argument($arguments, 0);
        if ($identifier === null || isset($arguments[1])) {
            $this->output->error('Usage: user:password <username|email> [--generate]');
            $this->output->muted('  The password is asked for, never passed on the command line.');
            return 1;
        }
        $cms = Cms::instance();
        if ($cms === null) {
            $this->output->error('Kestello is not installed.');
            return 1;
        }

        $user = QueryBuilder::table('users')->whereAny(['username', 'email'], '=', str_contains($identifier, '@') ? mb_strtolower($identifier) : $identifier)->first();
        if ($user === null) {
            $this->output->error("User \"{$identifier}\" not found.");
            return 1;
        }

        $policy = $cms->container->make(PasswordPolicy::class);
        $generated = $this->hasFlag($options, 'generate');
        if ($generated) {
            $password = self::generate();
        } else {
            $password = $this->read('New password for ' . $user['username']);
            if ($password === null) {
                $this->output->error('No password given.');
                return 1;
            }
            if ($this->interactive()) {
                if ($this->read('Repeat it') !== $password) {
                    $this->output->error('The two passwords are different. Nothing was changed.');
                    return 1;
                }
            }
        }

        $errors = $policy->errors($password, $user);
        if ($errors !== []) {
            foreach ($errors as $error) {
                $this->output->error('  ' . $error);
            }
            return 1;
        }

        $changed = $cms->container->make(AccountSecurity::class)->changePassword((int) $user['id'], $password, 'cli');

        $this->output->success("  Password set for {$user['username']}.");
        if ($generated) {
            $this->output->line('  ' . $password);
            $this->output->muted('  Shown once. Ask them to change it after signing in.');
        }
        if ($changed->sessionsRevoked > 0 || $changed->tokensRevoked > 0) {
            $this->output->muted("  Signed out of {$changed->sessionsRevoked} session(s); {$changed->tokensRevoked} access token(s) revoked.");
        }

        return 0;
    }

    /** One line from the terminal without echo, or from piped input. */
    private function read(string $prompt): ?string
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

    private function interactive(): bool
    {
        return $this->input === null && function_exists('stream_isatty') && stream_isatty(STDIN);
    }

    /** 20 characters from an alphabet without look-alikes (0/O, 1/l/I). */
    private static function generate(): string
    {
        $alphabet = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $out = '';
        for ($i = 0; $i < 20; $i++) {
            $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $out;
    }
}
