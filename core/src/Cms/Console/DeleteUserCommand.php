<?php

declare(strict_types=1);

namespace Kestello\Cms\Console;

use Kestello\Cms\Auth\PersonalAccessTokens;
use Kestello\Cms\Cms;
use Kestello\Contracts\Session\SessionStore;
use Kestello\Framework\Console\Command;
use Kestello\Framework\Database\QueryBuilder;

/**
 * Delete an account from the server, such as a test account a script made with user:create.
 * The same rules as People → Delete in the admin: the last active administrator stays, every
 * session and access token of that person ends, and their pages, files and comments stay on the
 * site without them as author.
 *
 *   php bin/cms user:delete shots          asks first
 *   php bin/cms user:delete shots --yes    for scripts
 *
 * Audit-logged as "users.deleted" with via "cli".
 */
final class DeleteUserCommand extends Command
{
    private const string USAGE = 'Usage: user:delete <username|email> [--yes]';

    protected string $name = 'user:delete';
    protected string $description = 'Delete an account: user:delete <username|email> [--yes]';

    public function handle(array $arguments, array $options): int
    {
        $identifier = $this->argument($arguments, 0);
        if ($identifier === null || isset($arguments[1])) {
            $this->output->error(self::USAGE);

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
        $id = (int) $user['id'];

        $superRoles = array_keys(array_filter($cms->auth->roles->roles(), static fn(array $r): bool => in_array('*', $r['capabilities'], true)));
        if (in_array($user['role'], $superRoles, true)
            && QueryBuilder::table('users')->whereIn('role', $superRoles)->where('is_active', 1)->where('id', '!=', $id)->count() === 0) {
            $this->output->error("{$user['username']} is the last administrator. Make someone else an administrator first.");

            return 1;
        }

        if (!$this->hasFlag($options, 'yes')
            && !$this->output->confirm("  Delete {$user['username']} ({$user['email']})? Their content stays, without them as author.")) {
            $this->output->muted('  Nothing was deleted.');

            return 1;
        }

        if ($cms->container->has(SessionStore::class)) {
            $cms->container->make(SessionStore::class)->destroyForUser($id);
        }
        $cms->container->make(PersonalAccessTokens::class)->revokeAll($id);
        QueryBuilder::table('users')->where('id', $id)->delete();
        $cms->audit->record('users.deleted', ['user', $id], ['username' => $user['username'], 'email' => $user['email'], 'via' => 'cli'], null);

        $this->output->success("  Deleted {$user['username']}.");

        return 0;
    }
}
