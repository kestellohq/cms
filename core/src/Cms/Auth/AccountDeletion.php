<?php

declare(strict_types=1);

namespace Kestello\Cms\Auth;

use Kestello\Cms\Cms;
use Kestello\Cms\Events\UserDeleted;
use Kestello\Cms\Events\UserDeleting;
use Kestello\Contracts\Session\SessionStore;
use Kestello\Framework\Database\Connection;
use Kestello\Framework\Database\DatabaseManager;
use Kestello\Framework\Database\QueryBuilder;

/**
 * Deleting an account, the same from People in the admin (DELETE /users/{id}), from
 * user:delete and by the person themselves (DELETE /me). refusal() says why an account may not
 * go: the last administrator stays, and a plugin may refuse with UserDeleting. delete() ends
 * every session and access token, removes the rows that only mean something for that person,
 * then dispatches UserDeleted. The callers check who may delete whom and write the audit entry.
 *
 * Most per-person tables have a foreign key that cascades (two-factor methods, recovery codes,
 * password resets, identities, sessions, access tokens, uploads, autosaves, assignments).
 * UNLINKED lists the ones without one, which this removes itself. Content, files and comments
 * stay on the site without the person as author.
 *
 * @internal
 */
final class AccountDeletion
{
    /** Per-person tables with a user_id column but no foreign key to users. */
    public const array UNLINKED = ['email_changes', 'notifications', 'notification_preferences'];

    public const string LAST_ADMINISTRATOR = 'This is the last administrator. Make someone else an administrator first.';

    /**
     * Why this account may not be deleted, or null when it may. Asks the plugins (UserDeleting).
     *
     * @param array<string, mixed> $user the users row
     * @param string $via self, admin or cli
     */
    public static function refusal(Cms $cms, array $user, string $via, ?int $byUserId): ?string
    {
        $id = (int) $user['id'];
        $supers = array_keys(array_filter($cms->auth->roles->roles(), static fn(array $r): bool => in_array('*', $r['capabilities'], true)));
        if (in_array((string) $user['role'], $supers, true)
            && QueryBuilder::table('users')->whereIn('role', $supers)->where('is_active', 1)->where('id', '!=', $id)->count() === 0) {
            return self::LAST_ADMINISTRATOR;
        }
        $event = new UserDeleting($id, $via, $byUserId);
        $cms->events->dispatch($event);

        return $event->reason();
    }

    /** @param string $via self, admin or cli */
    public static function delete(Cms $cms, int $userId, string $via = 'admin', ?int $byUserId = null): void
    {
        $user = QueryBuilder::table('users')->where('id', $userId)->first();

        if ($cms->container->has(SessionStore::class)) {
            $cms->container->make(SessionStore::class)->destroyForUser($userId);
        }
        $cms->container->make(PersonalAccessTokens::class)->revokeAll($userId);

        DatabaseManager::getConnection()->transaction(static function (Connection $db) use ($userId): void {
            foreach (self::UNLINKED as $table) {
                $db->table($table)->where('user_id', $userId)->delete();
            }
            $db->table('users')->where('id', $userId)->delete();
        });
        if ($user !== null) {
            $cms->events->dispatch(new UserDeleted($userId, (string) $user['username'], (string) $user['email'], $via, $byUserId));
        }
    }
}
