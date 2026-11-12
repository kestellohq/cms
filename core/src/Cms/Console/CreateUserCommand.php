<?php

declare(strict_types=1);

namespace Kestello\Cms\Console;

use Kestello\Cms\Auth\Emails;
use Kestello\Cms\Auth\PasswordPolicy;
use Kestello\Cms\Cms;
use Kestello\Cms\Events\UserRegistered;
use Kestello\Framework\Console\Command;
use Kestello\Framework\Database\QueryBuilder;
use Kestello\Support\Hash;

/**
 * Create an account from the server: the first administrator of a site set up by script (a
 * deploy, a development stack, CI), or anyone when the admin can't be reached. The same rules as
 * People → Add in the admin: a free username and email, the password policy, a role that exists.
 *
 *   php bin/cms user:create ana ana@example.com --role=admin            asks for the password twice
 *   php bin/cms user:create ana ana@example.com --role=admin --generate prints a new password once
 *   printf '%s\n' "$PW" | php bin/cms user:create ana ana@example.com   reads one line when not a terminal
 *
 * The password is never an argument (shell history, the process list). Audit-logged as
 * "users.created" with via "cli"; UserRegistered is dispatched with via "cli".
 */
final class CreateUserCommand extends Command
{
    private const string USAGE = 'Usage: user:create <username> <email> [--role=admin] [--generate]';

    protected string $name = 'user:create';
    protected string $description = 'Create an account: user:create <username> <email> [--role=admin] [--generate]';

    private readonly PasswordPrompt $prompt;

    /** @param resource|null $input where the password is read from (tests pass a stream) */
    public function __construct(mixed $input = null)
    {
        $this->prompt = new PasswordPrompt($input);
    }

    public function handle(array $arguments, array $options): int
    {
        $username = $this->argument($arguments, 0);
        $email = $this->argument($arguments, 1);
        if ($username === null || $email === null || isset($arguments[2])) {
            $this->output->error(self::USAGE);
            $this->output->muted('  The password is asked for, never passed on the command line.');

            return 1;
        }
        $cms = Cms::instance();
        if ($cms === null) {
            $this->output->error('Kestello is not installed.');

            return 1;
        }
        $email = Emails::normalize($email);
        $roles = array_keys($cms->auth->roles->roles());
        $role = (string) ($this->option($options, 'role') ?? 'user');

        $errors = [];
        if (preg_match('/^[A-Za-z0-9_-]{3,50}$/', $username) !== 1) {
            $errors[] = 'The username is 3 to 50 letters, digits, "-" or "_".';
        } elseif (QueryBuilder::table('users')->where('username', $username)->exists()) {
            $errors[] = "The username \"{$username}\" is already taken.";
        }
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($email) > 255) {
            $errors[] = "\"{$email}\" is not an email address.";
        } elseif (QueryBuilder::table('users')->where('email', $email)->exists()) {
            $errors[] = "The email {$email} is already in use.";
        }
        if (!in_array($role, $roles, true)) {
            $errors[] = "There is no role \"{$role}\". Roles: " . implode(', ', $roles) . '.';
        }
        if ($errors !== []) {
            foreach ($errors as $error) {
                $this->output->error('  ' . $error);
            }

            return 1;
        }

        $generated = $this->hasFlag($options, 'generate');
        $password = $generated ? PasswordPrompt::generate() : $this->prompt->read("Password for {$username}");
        if ($password === null) {
            $this->output->error('No password given. Nothing was created.');

            return 1;
        }
        if (!$generated && $this->prompt->interactive() && $this->prompt->read('Repeat it') !== $password) {
            $this->output->error('The two passwords are different. Nothing was created.');

            return 1;
        }
        $problems = $cms->container->make(PasswordPolicy::class)->errors($password, ['username' => $username, 'email' => $email]);
        if ($problems !== []) {
            foreach ($problems as $problem) {
                $this->output->error('  ' . $problem);
            }

            return 1;
        }

        $now = date('Y-m-d H:i:s');
        $id = (int) QueryBuilder::table('users')->insert([
            'username' => $username,
            'email' => $email,
            'password' => Hash::password($password),
            'role' => $role,
            'is_active' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $cms->audit->record('users.created', ['user', $id], ['username' => $username, 'role' => $role, 'via' => 'cli'], null);
        $cms->events->dispatch(new UserRegistered($id, 'cli'));

        $this->output->success("  Created {$username} ({$role}).");
        if ($generated) {
            $this->output->line('  ' . $password);
            $this->output->muted('  Shown once. Ask them to change it after signing in.');
        }

        return 0;
    }
}
