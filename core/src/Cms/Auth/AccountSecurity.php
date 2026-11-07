<?php

declare(strict_types=1);

namespace Kestello\Cms\Auth;

use Closure;
use Kestello\Cms\Events\PasswordChanged;
use Kestello\Cms\Events\SessionRevoked;
use Kestello\Cms\Events\TokenRevoked;
use Kestello\Contracts\Session\SessionStore;
use Kestello\Framework\Database\QueryBuilder;
use Kestello\Framework\Events\EventDispatcher;
use Kestello\Support\Hash;

/**
 * Password changes and device sessions.
 *
 * Changing a password ends the user's other sessions (on every session
 * store, via the password fingerprint in Auth, and rows are deleted where
 * the store lists devices) and revokes their personal access tokens. Both
 * follow auth.revoke_on_password_change (default on).
 */
final class AccountSecurity
{
    /** @param Closure(): ?SessionStore $sessions resolved lazily (the store exists only once the session started) */
    public function __construct(
        private readonly Closure $sessions,
        private readonly PersonalAccessTokens $tokens,
        private readonly ?EventDispatcher $events = null,
        private readonly bool $revokeOnPasswordChange = true,
    ) {}

    /**
     * @param string      $via         profile, reset, admin, cli
     * @param string|null $keepSession hashed id of a session to keep (the user's current one)
     */
    public function changePassword(int $userId, string $newPassword, string $via, ?string $keepSession = null): PasswordChanged
    {
        QueryBuilder::table('users')->where('id', $userId)->update([
            'password' => Hash::password($newPassword),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        QueryBuilder::table('password_resets')->where('user_id', $userId)->delete();

        $sessions = 0;
        $tokens = 0;
        if ($this->revokeOnPasswordChange) {
            $sessions = $this->store()?->destroyForUser($userId, $keepSession) ?? 0;
            $tokens = $this->tokens->revokeAll($userId);
        }

        $event = new PasswordChanged($userId, $via, $sessions, $tokens);
        $this->events?->dispatch($event);
        if ($tokens > 0) {
            $this->events?->dispatch(new TokenRevoked($userId, null));
        }

        return $event;
    }

    public function revokesOnPasswordChange(): bool
    {
        return $this->revokeOnPasswordChange;
    }

    /**
     * The user's signed-in devices, newest activity first. Empty when the
     * session store can't list devices (file sessions).
     *
     * @return list<array{id: string, ip: string, user_agent: string, created_at: int, last_active_at: int, current: bool}>
     */
    public function sessions(int $userId, ?string $current = null): array
    {
        $rows = $this->store()?->forUser($userId) ?? [];
        $out = array_map(static fn(array $r): array => [
            'id' => (string) $r['id'],
            'ip' => (string) ($r['ip'] ?? ''),
            'user_agent' => (string) ($r['user_agent'] ?? ''),
            'created_at' => (int) ($r['created_at'] ?? 0),
            'last_active_at' => (int) ($r['last_activity'] ?? 0),
            'current' => $current !== null && hash_equals((string) $r['id'], $current),
        ], $rows);
        usort($out, static fn(array $a, array $b) => $b['last_active_at'] <=> $a['last_active_at']);

        return $out;
    }

    public function tracksDevices(): bool
    {
        return $this->store()?->tracksDevices() ?? false;
    }

    /** Sign out one of the user's sessions by its listed (hashed) id. False when it isn't theirs. */
    public function revokeSession(int $userId, string $hashedId): bool
    {
        $mine = array_column($this->store()?->forUser($userId) ?? [], 'id');
        if (!in_array($hashedId, array_map('strval', $mine), true)) {
            return false;
        }

        $this->store()?->destroyHashed($hashedId);
        $this->events?->dispatch(new SessionRevoked($userId, $hashedId));

        return true;
    }

    /** "Sign out everywhere else." Returns how many sessions ended. */
    public function revokeOtherSessions(int $userId, ?string $keep): int
    {
        $count = $this->store()?->destroyForUser($userId, $keep) ?? 0;
        if ($count > 0) {
            $this->events?->dispatch(new SessionRevoked($userId, $keep ?? '', allOthers: true, count: $count));
        }

        return $count;
    }

    private function store(): ?SessionStore
    {
        return ($this->sessions)();
    }
}
