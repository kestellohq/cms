<?php

declare(strict_types=1);

namespace Kestello\Cms\Auth\Guards;

use Kestello\Cms\Auth\Auth;
use Kestello\Cms\Auth\AuthRegistry;
use Kestello\Contracts\Auth\Guard;
use Kestello\Contracts\Auth\Principal;
use Kestello\Framework\Core\Request;

/**
 * The browser session (cookie). Unsafe methods additionally need the CSRF
 * token, which the API dispatcher checks for principals of this guard.
 */
final class SessionGuard implements Guard
{
    public function __construct(
        private readonly AuthRegistry $registry,
        private readonly \Closure $auth,
    ) {}

    public function id(): string
    {
        return 'session';
    }

    public function isBearer(): bool
    {
        return false;
    }

    public function authenticate(Request $request): ?Principal
    {
        /** @var Auth|null $auth */
        $auth = ($this->auth)();
        $user = $auth?->user();

        if ($auth === null || $user === null) {
            return null;
        }

        $sessionId = session_id();

        return $this->registry->principalFor(
            $user,
            method: 'session',
            scopes: null,
            credentialId: $sessionId !== '' && $sessionId !== false ? hash('sha256', $sessionId) : null,
            authenticatedAt: $auth->authenticatedAt(),
        );
    }
}
