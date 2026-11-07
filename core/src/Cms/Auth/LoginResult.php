<?php

declare(strict_types=1);

namespace Kestello\Cms\Auth;

/**
 * Outcome of a login step. Failures are deliberately not specific: the
 * caller shows one generic message whether the account exists or not.
 *
 * @api
 */
final readonly class LoginResult
{
    public const string SUCCESS = 'success';
    public const string TWO_FACTOR = 'two_factor';
    public const string FAILED = 'failed';
    public const string THROTTLED = 'throttled';
    /** No pending second-factor challenge (expired, or too many wrong codes). */
    public const string EXPIRED = 'expired';

    /** @param array<string, mixed>|null $user public fields only */
    private function __construct(
        public string $status,
        public ?array $user = null,
        public int $retryAfter = 0,
        public ?string $method = null,
        public ?int $recoveryCodesLeft = null,
    ) {}

    /** @param array<string, mixed> $user */
    public static function success(array $user, string $method, ?int $recoveryCodesLeft = null): self
    {
        return new self(self::SUCCESS, self::publicUser($user), method: $method, recoveryCodesLeft: $recoveryCodesLeft);
    }

    public static function twoFactor(): self
    {
        return new self(self::TWO_FACTOR);
    }
    public static function failed(): self
    {
        return new self(self::FAILED);
    }
    public static function expired(): self
    {
        return new self(self::EXPIRED);
    }
    public static function throttled(int $seconds): self
    {
        return new self(self::THROTTLED, retryAfter: $seconds);
    }

    public function ok(): bool
    {
        return $this->status === self::SUCCESS;
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public static function publicUser(array $user): array
    {
        return [
            'id' => (int) $user['id'],
            'username' => (string) ($user['username'] ?? ''),
            'email' => (string) ($user['email'] ?? ''),
            'role' => (string) ($user['role'] ?? ''),
        ];
    }
}
