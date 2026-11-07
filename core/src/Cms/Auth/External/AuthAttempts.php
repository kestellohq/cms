<?php

declare(strict_types=1);

namespace Kestello\Cms\Auth\External;

use Kestello\Contracts\Auth\AuthAttempt;
use Kestello\Framework\Session\Session;

/**
 * Pending redirect sign-ins, kept in the server-side session (so they are
 * bound to the browser that started them) and consumed on first use.
 * Only the hash of each state is a key.
 *
 * @internal
 */
final class AuthAttempts
{
    private const string KEY = '_auth_attempts';
    private const int MAX = 5;

    public function __construct(private readonly Session $session) {}

    public function put(AuthAttempt $attempt): void
    {
        $all = array_filter($this->all(), static fn(array $a) => ($a['expiresAt'] ?? 0) >= time());
        $all[hash('sha256', $attempt->state)] = $attempt->toArray();
        $this->session->set(self::KEY, array_slice($all, -self::MAX, null, true));
    }

    /** Take the attempt for $state; null when unknown, used or expired. */
    public function pull(string $state): ?AuthAttempt
    {
        if ($state === '' || strlen($state) > 128) {
            return null;
        }
        $all = $this->all();
        $key = hash('sha256', $state);
        $data = $all[$key] ?? null;
        unset($all[$key]);
        $this->session->set(self::KEY, $all);

        if (!is_array($data)) {
            return null;
        }
        $attempt = AuthAttempt::fromArray($data);
        if (!hash_equals($attempt->state, $state) || $attempt->expired()) {
            return null;
        }

        return $attempt;
    }

    /** @return array<string, array<string, mixed>> */
    private function all(): array
    {
        $all = $this->session->get(self::KEY, []);

        return is_array($all) ? $all : [];
    }
}
