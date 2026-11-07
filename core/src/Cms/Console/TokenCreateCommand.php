<?php

declare(strict_types=1);

namespace Kestello\Cms\Console;

use DateTimeImmutable;
use Kestello\Cms\Auth\PersonalAccessTokens;
use Kestello\Framework\Console\Command;
use Kestello\Framework\Database\QueryBuilder;

/**
 * Create a personal access token for a user (shown once).
 *
 *   php bin/cms token:create admin --name=deploy --scopes=content:read,users:read --days=90
 */
final class TokenCreateCommand extends Command
{
    protected string $name = 'token:create';
    protected string $description = 'Create a personal access token: token:create <user> [--name=] [--scopes=a,b] [--days=]';

    /** @param \Closure(): list<string> $knownScopes */
    public function __construct(private readonly \Closure $knownScopes) {}

    public function handle(array $arguments, array $options): int
    {
        $identifier = $this->argument($arguments, 0);
        if ($identifier === null) {
            $this->output->error('Usage: token:create <username|email> [--name=cli] [--scopes=content:read,...] [--days=90]');
            return 1;
        }

        $user = QueryBuilder::table('users')->whereAny(['username', 'email'], '=', $identifier)->first();
        if ($user === null) {
            $this->output->error("User \"{$identifier}\" not found.");
            return 1;
        }

        $scopes = array_values(array_filter(array_map('trim', explode(',', (string) ($options['scopes'] ?? '')))));
        if ($scopes === []) {
            $this->output->error('Pass at least one scope with --scopes= (e.g. --scopes=content:read).');
            $this->output->muted('  Known scopes: ' . implode(', ', ($this->knownScopes)()));
            return 1;
        }

        $unknown = array_diff($scopes, ($this->knownScopes)());
        if ($unknown !== []) {
            $this->output->error('Unknown scope(s): ' . implode(', ', $unknown));
            return 1;
        }

        $days = isset($options['days']) ? (int) $options['days'] : 90;
        $expires = $days > 0 ? new DateTimeImmutable("+{$days} days") : null;
        $name = substr((string) ($options['name'] ?? 'cli'), 0, 100);

        $created = (new PersonalAccessTokens())->create((int) $user['id'], $name, $scopes, $expires);
        \Kestello\Cms\Cms::instance()?->events->dispatch(new \Kestello\Cms\Events\TokenCreated((int) $user['id'], (int) $created['record']['id'], $name, $scopes));

        $this->output->success("  Token \"{$name}\" created for {$user['username']}" . ($expires ? ' (expires ' . $expires->format('Y-m-d') . ')' : ' (no expiry)'));
        $this->output->newLine();
        $this->output->line('  ' . $created['token']);
        $this->output->newLine();
        $this->output->muted('  Shown once. Use: Authorization: Bearer <token>');

        return 0;
    }
}
