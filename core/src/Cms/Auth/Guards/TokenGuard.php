<?php

declare(strict_types=1);

namespace Kestello\Cms\Auth\Guards;

use Kestello\Cms\Auth\AuthRegistry;
use Kestello\Cms\Auth\PersonalAccessTokens;
use Kestello\Contracts\Auth\Guard;
use Kestello\Contracts\Auth\Principal;
use Kestello\Framework\Core\Request;

/**
 * Personal access tokens: Authorization: Bearer kst_pat_...
 */
final class TokenGuard implements Guard
{
    public function __construct(
        private readonly AuthRegistry $registry,
        private readonly PersonalAccessTokens $tokens,
    ) {}

    public function id(): string
    {
        return 'token';
    }

    public function isBearer(): bool
    {
        return true;
    }

    public function authenticate(Request $request): ?Principal
    {
        $plain = $request->bearerToken();
        if ($plain === null || !str_starts_with($plain, PersonalAccessTokens::PREFIX)) {
            return null;
        }

        $found = $this->tokens->verify($plain, $request->ip());
        if ($found === null) {
            return null;
        }

        return $this->registry->principalFor(
            $found['user'],
            method: 'token',
            scopes: PersonalAccessTokens::decodeScopes($found['token']['scopes']),
            credentialId: (string) $found['token']['id'],
        );
    }
}
