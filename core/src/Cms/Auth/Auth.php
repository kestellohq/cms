<?php

declare(strict_types=1);

namespace Kestello\Cms\Auth;

use Kestello\Framework\Database\DatabaseManager;
use Kestello\Framework\Database\QueryBuilder;
use Kestello\Framework\Session\Session;

/**
 * Authentication guard.
 *
 * Provides session-based authentication: login, logout, check, and
 * retrieval of the currently authenticated user.
 */
final class Auth
{
    private const string SESSION_KEY = '_auth_user_id';
    private const string AUTH_AT_KEY = '_auth_at';
    private const string METHOD_KEY = '_auth_method';
    /** Fingerprint of the password hash: changing the password ends every other session, on any session store. */
    private const string PASSWORD_KEY = '_auth_pw';
    private const string PENDING_KEY = '_auth_pending';

    /** How long a password-verified login waits for its second factor. */
    public const int PENDING_TTL = 300;

    private static ?self $instance = null;

    /** Cached authenticated user for the current request. */
    private ?array $user = null;

    /** Whether we've already attempted to load the user this request. */
    private bool $resolved = false;

    public function __construct(
        private readonly Session $session,
        private readonly string $table = 'users',
        private readonly string $primaryKey = 'id',
        private readonly bool $bindToPassword = true,
    ) {}

    /**
     * Get the singleton instance.
     */
    public static function getInstance(): ?self
    {
        return self::$instance;
    }

    /**
     * Set the singleton instance (called during bootstrap).
     */
    public static function setInstance(self $instance): void
    {
        self::$instance = $instance;
    }

    /**
     * Attempt to authenticate a user by credentials.
     *
     * @param string $username Username or email.
     * @param string $password Plain text password.
     * @param string $usernameColumn The column to match against.
     *
     * @return bool True if authentication succeeded.
     */
    public function attempt(string $username, #[\SensitiveParameter] string $password, string $usernameColumn = 'email'): bool
    {
        $user = QueryBuilder::table($this->table)
            ->where($usernameColumn, $usernameColumn === 'email' ? Emails::normalize($username) : $username)
            ->first();

        if ($user === null) {
            \Kestello\Support\Hash::verifyDummy($password);
            return false;
        }

        if (!\Kestello\Support\Hash::verify($password, (string) ($user['password'] ?? ''))) {
            return false;
        }

        if (isset($user['is_active']) && !$user['is_active']) {
            return false;
        }

        $this->login($user);

        return true;
    }

    /**
     * Log in a user directly (set session, cache user).
     */
    public function login(array $user, string $method = 'password'): void
    {
        $this->session->regenerate();
        $this->session->remove(self::PENDING_KEY);
        $this->session->set(self::SESSION_KEY, $user[$this->primaryKey]);
        $this->session->set(self::AUTH_AT_KEY, time());
        $this->session->set(self::METHOD_KEY, $method);
        $this->session->set(self::PASSWORD_KEY, self::fingerprint($user));
        $this->user = $user;
        $this->resolved = true;

        // Update last login timestamp
        if (DatabaseManager::hasConnection()) {
            QueryBuilder::table($this->table)
                ->where($this->primaryKey, $user[$this->primaryKey])
                ->update(['last_login_at' => date('Y-m-d H:i:s')]);
        }
    }

    /**
     * Log out the current user.
     */
    public function logout(): void
    {
        foreach ([self::SESSION_KEY, self::AUTH_AT_KEY, self::METHOD_KEY, self::PASSWORD_KEY, self::PENDING_KEY] as $key) {
            $this->session->remove($key);
        }
        $this->session->regenerate();
        $this->user = null;
        $this->resolved = true;
    }

    /**
     * Check if a user is currently authenticated.
     */
    public function check(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Check if the current request is from a guest (not authenticated).
     */
    public function guest(): bool
    {
        return !$this->check();
    }

    /**
     * Get the currently authenticated user, or null.
     *
     * @return array<string, mixed>|null
     */
    public function user(): ?array
    {
        if ($this->resolved) {
            return $this->user;
        }

        $this->resolved = true;

        $userId = $this->session->get(self::SESSION_KEY);

        if ($userId === null) {
            return null;
        }

        if (!DatabaseManager::hasConnection()) {
            return null;
        }

        $this->user = QueryBuilder::table($this->table)
            ->where($this->primaryKey, $userId)
            ->first();

        // If user no longer exists or is deactivated, clear session
        if ($this->user === null || (isset($this->user['is_active']) && !$this->user['is_active'])) {
            $this->session->remove(self::SESSION_KEY);
            $this->user = null;
            return null;
        }

        // A password change elsewhere ends this session. login() always records the fingerprint,
        // so a session without one was not signed in here.
        if ($this->bindToPassword) {
            $stored = $this->session->get(self::PASSWORD_KEY);
            if (!is_string($stored) || !hash_equals(self::fingerprint($this->user), $stored)) {
                $this->logout();
                return null;
            }
        }

        return $this->user;
    }

    /**
     * When the user last proved their identity (password or provider), as a
     * Unix timestamp. Used for "recent authentication" checks.
     */
    public function authenticatedAt(): ?int
    {
        $at = $this->session->get(self::AUTH_AT_KEY);

        return $this->check() && is_int($at) ? $at : null;
    }

    /**
     * Mark the current session as freshly authenticated (after re-entering the password).
     */
    public function confirm(): void
    {
        if ($this->check()) {
            $this->session->set(self::AUTH_AT_KEY, time());
        }
    }

    /** How the current session signed in ("password", "password+totp", "google"…). */
    public function method(): ?string
    {
        $method = $this->session->get(self::METHOD_KEY);

        return $this->check() && is_string($method) ? $method : null;
    }

    /** Call after the user changed their own password, so this session survives it. */
    public function refreshPassword(array $user): void
    {
        $current = $this->session->get(self::SESSION_KEY);
        if ($current !== null && (string) $current === (string) ($user[$this->primaryKey] ?? '')) {
            $this->session->regenerate();
            $this->session->set(self::PASSWORD_KEY, self::fingerprint($user));
            $this->user = null;
            $this->resolved = false;
        }
    }

    // ── Second factor ───────────────────────────────────────────────

    /**
     * The first factor passed; wait for the second. The session id changes,
     * but nobody is logged in until completeLogin().
     */
    public function beginSecondFactor(int $userId, string $method): void
    {
        $this->logout();
        $user = QueryBuilder::table('users')->where('id', $userId)->first() ?? [];
        // The password fingerprint ties the challenge to the password it was started
        // with: a reset or "sign out everywhere" in the meantime ends it.
        $this->session->set(self::PENDING_KEY, ['user_id' => $userId, 'method' => $method, 'expires' => time() + self::PENDING_TTL, 'attempts' => 0, 'fp' => self::fingerprint($user)]);
    }

    /** @param array<string, mixed> $user the current row of the pending user */
    public function pendingStillValid(array $user): bool
    {
        $pending = $this->pendingSecondFactor();

        return $pending !== null && is_string($pending['fp'] ?? null) && hash_equals(self::fingerprint($user), $pending['fp']);
    }

    /** @return array{user_id: int, method: string, expires: int, attempts: int}|null */
    public function pendingSecondFactor(): ?array
    {
        $pending = $this->session->get(self::PENDING_KEY);
        if (!is_array($pending) || !isset($pending['user_id'], $pending['expires']) || $pending['expires'] < time()) {
            if ($pending !== null) {
                $this->session->remove(self::PENDING_KEY);
            }
            return null;
        }

        return $pending;
    }

    /** Count a wrong code; returns attempts so far. */
    public function failSecondFactor(): int
    {
        $pending = $this->pendingSecondFactor();
        if ($pending === null) {
            return 0;
        }
        $pending['attempts']++;
        $this->session->set(self::PENDING_KEY, $pending);

        return $pending['attempts'];
    }

    public function clearSecondFactor(): void
    {
        $this->session->remove(self::PENDING_KEY);
    }

    /** @param array<string, mixed> $user */
    private static function fingerprint(array $user): string
    {
        return substr(hash('sha256', 'kestello-session:' . (string) ($user['password'] ?? '')), 0, 32);
    }

    /**
     * Get the authenticated user's ID, or null.
     */
    public function id(): int|string|null
    {
        return $this->user()[$this->primaryKey] ?? null;
    }

    /**
     * Destroy the singleton (for testing).
     */
    public static function destroy(): void
    {
        self::$instance = null;
    }
}
