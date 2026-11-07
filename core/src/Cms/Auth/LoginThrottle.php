<?php

declare(strict_types=1);

namespace Kestello\Cms\Auth;

use Kestello\Framework\Http\RateLimiter;

/**
 * Brute-force limits for anything that checks a secret:
 *
 * - per IP (default 5 a minute), whatever account it tries;
 * - per account from one IP (default 10 an hour): stops a guesser without
 *   touching the owner, who signs in from elsewhere;
 * - per account from everywhere (default 100 an hour): stops guessing
 *   spread over many addresses. It is high on purpose, so one attacker
 *   can't lock an account out by failing on purpose.
 *
 * Only failures count, so a busy office behind one IP isn't locked out by
 * its own successful logins; a success clears the account's counters.
 * An attempt the IP limit already refuses never reaches the secret, so it
 * is not counted against the account either.
 *
 * Every attempt is counted before the secret is checked (reserve()), and
 * given back when it succeeds. Checking first and counting after would let
 * parallel requests all pass the limit before any of them is counted.
 *
 * $scope separates login, 2FA, password confirmation and password resets.
 */
final class LoginThrottle
{
    public function __construct(
        private readonly RateLimiter $limiter,
        private readonly int $ipMax = 5,
        private readonly int $ipWindow = 60,
        private readonly int $accountMax = 10,
        private readonly int $accountWindow = 3600,
        private readonly int $accountEverywhereMax = 100,
    ) {}

    /**
     * Count this attempt now, atomically. Returns the seconds to wait when it
     * is over a limit (0 = go ahead and check the secret).
     */
    public function reserve(string $scope, string $ip, string $account): int
    {
        if (!$this->limiter->hit($this->ipKey($scope, $ip), $this->ipMax, $this->ipWindow)->allowed) {
            return self::remaining($this->ipWindow);
        }
        $wait = 0;
        if (!$this->limiter->hit($this->pairKey($scope, $ip, $account), $this->accountMax, $this->accountWindow)->allowed) {
            $wait = self::remaining($this->accountWindow);
        }
        if (!$this->limiter->hit($this->accountKey($scope, $account), $this->accountEverywhereMax, $this->accountWindow)->allowed) {
            $wait = self::remaining($this->accountWindow);
        }

        return $wait;
    }

    /** Seconds until another attempt is allowed (0 = allowed now), without counting one. */
    public function blockedFor(string $scope, string $ip, string $account): int
    {
        if ($this->limiter->attempts($this->ipKey($scope, $ip), $this->ipWindow) >= $this->ipMax) {
            return self::remaining($this->ipWindow);
        }
        $pair = $this->limiter->attempts($this->pairKey($scope, $ip, $account), $this->accountWindow) >= $this->accountMax;
        $everywhere = $this->limiter->attempts($this->accountKey($scope, $account), $this->accountWindow) >= $this->accountEverywhereMax;

        return $pair || $everywhere ? self::remaining($this->accountWindow) : 0;
    }

    /** A reserved attempt succeeded: it does not count against the IP, and the account starts over. */
    public function succeeded(string $scope, string $ip, string $account): void
    {
        $this->limiter->refund($this->ipKey($scope, $ip), $this->ipWindow);
        $this->limiter->clear($this->pairKey($scope, $ip, $account), $this->accountWindow);
        $this->limiter->clear($this->accountKey($scope, $account), $this->accountWindow);
    }

    /** An account key that doesn't reveal whether the account exists. */
    public static function account(?array $user, string $identifier): string
    {
        return $user !== null ? 'user:' . $user['id'] : 'id:' . hash('sha256', Emails::normalize($identifier));
    }

    private function ipKey(string $scope, string $ip): string
    {
        return "auth|{$scope}|ip|{$ip}";
    }

    private function pairKey(string $scope, string $ip, string $account): string
    {
        return "auth|{$scope}|acct-ip|{$account}|{$ip}";
    }

    private function accountKey(string $scope, string $account): string
    {
        return "auth|{$scope}|acct|{$account}";
    }

    private static function remaining(int $window): int
    {
        return max(1, $window - time() % $window);
    }
}
