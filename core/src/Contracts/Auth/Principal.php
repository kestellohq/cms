<?php

declare(strict_types=1);

namespace Kestello\Contracts\Auth;

/**
 * The authenticated actor of a request: who (user), how (method), and what
 * they may do (capabilities from the role, narrowed by token scopes).
 *
 * Effective permission = capabilities ∩ scopes: a token can narrow what its
 * user may do, never widen it.
 *
 * @api
 */
final readonly class Principal
{
    /**
     * @param array<string, mixed> $user        public user fields (id, username, email, role)
     * @param list<string>          $capabilities resolved from the role ("*" = all)
     * @param list<string>|null     $scopes       null = unrestricted (interactive session)
     */
    public function __construct(
        public ?int $userId,
        public array $user,
        public string $method,
        public array $capabilities,
        public ?array $scopes = null,
        public ?string $credentialId = null,
        public ?int $authenticatedAt = null,
    ) {}

    public static function guest(): self
    {
        return new self(null, [], 'guest', []);
    }

    public function isGuest(): bool
    {
        return $this->userId === null;
    }

    public function can(string $capability): bool
    {
        return !$this->isGuest()
            && (in_array('*', $this->capabilities, true) || in_array($capability, $this->capabilities, true));
    }

    public function hasScope(string $scope): bool
    {
        return $this->scopes === null
            || in_array($scope, $this->scopes, true)
            || in_array('admin', $this->scopes, true);
    }

    /** @param list<string> $scopes all of these are required */
    public function hasScopes(array $scopes): bool
    {
        foreach ($scopes as $scope) {
            if (!$this->hasScope($scope)) {
                return false;
            }
        }

        return true;
    }

    /** Authenticated (by password or provider) within the last $seconds. */
    public function recentlyAuthenticated(int $seconds = 600): bool
    {
        return $this->authenticatedAt !== null && time() - $this->authenticatedAt <= $seconds;
    }
}
