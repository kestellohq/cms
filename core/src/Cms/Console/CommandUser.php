<?php

declare(strict_types=1);

namespace Kestello\Cms\Console;

use Kestello\Framework\Database\QueryBuilder;

/**
 * The account a command acts as: --as=<username>, else the site's only
 * administrator. Shared by the commands that write content (import:wordpress,
 * site:seed), so they ask for it the same way.
 *
 *   [$user, $error] = CommandUser::find($this->option($options, 'as'), 'import sites', 'imports it');
 */
final class CommandUser
{
    /**
     * @param string $doing what only administrators may do, for the messages ("import sites")
     * @param string $runs who does it, when --as is needed ("imports it")
     * @return array{0: array<string, mixed>|null, 1: ?string} the user row, or null and why
     */
    public static function find(?string $username, string $doing, string $runs, bool $adminOnly = true): array
    {
        if ($username !== null) {
            $user = QueryBuilder::table('users')->where('username', $username)->where('is_active', true)->first();
            if ($user === null) {
                return [null, "No active user \"{$username}\"."];
            }
            if ($adminOnly && $user['role'] !== 'admin') {
                return [null, "{$username} is not an administrator; only administrators {$doing}."];
            }

            return [$user, null];
        }
        $admins = QueryBuilder::table('users')->where('role', 'admin')->where('is_active', true)->orderBy('id')->limit(2)->get();
        if (count($admins) !== 1) {
            return [null, count($admins) === 0 ? 'This site has no active administrator.' : "Say who {$runs} with --as=username (an administrator)."];
        }

        return [$admins[0], null];
    }
}
