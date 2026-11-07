<?php

declare(strict_types=1);

namespace Kestello\Contracts\Session;

/**
 * Where PHP sessions live. A standard session handler plus device
 * metadata, so users can list and revoke their sessions.
 *
 * Session ids are never stored in clear: stores key rows by
 * sha256($id) (SessionMetadata::hashId()), so a leaked table can't be replayed.
 *
 * @api
 */
interface SessionStore extends \SessionHandlerInterface, \SessionUpdateTimestampHandlerInterface
{
    /** Short name shown in the admin ("database", "redis"…). */
    public function name(): string;

    /** Whether forUser()/destroyForUser() work (false for plain files). */
    public function tracksDevices(): bool;

    /**
     * @return list<array{id: string, user_id: int|null, ip: string, user_agent: string, created_at: int, last_activity: int}>
     *         id is the hashed session id, newest activity first
     */
    public function forUser(int $userId): array;

    /** Destroy a user's sessions (optionally keeping one). Returns how many. */
    public function destroyForUser(int $userId, ?string $exceptHashedId = null): int;

    /** Destroy one session by its hashed id (device list "sign out"). */
    public function destroyHashed(string $hashedId): bool;
}
