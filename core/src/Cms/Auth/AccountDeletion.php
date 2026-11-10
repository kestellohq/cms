<?php

declare(strict_types=1);

namespace Kestello\Cms\Auth;

use Kestello\Cms\Cms;
use Kestello\Contracts\Session\SessionStore;
use Kestello\Framework\Database\Connection;
use Kestello\Framework\Database\DatabaseManager;

/**
 * Deleting an account, the same from People in the admin (DELETE /users/{id}) and from
 * user:delete. Every session and access token ends, and the rows that only mean something for
 * that person go with the account. The callers check who may delete whom and write the audit
 * entry.
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

    public static function delete(Cms $cms, int $userId): void
    {
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
    }
}
