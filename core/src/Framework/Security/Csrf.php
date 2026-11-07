<?php

declare(strict_types=1);

namespace Kestello\Framework\Security;

use Kestello\Framework\Session\Session;

/**
 * CSRF protection helper.
 *
 * Generates, validates, and renders CSRF tokens via the session.
 */
final readonly class Csrf
{
    /**
     * @param Session $session Active session instance.
     */
    public function __construct(
        private Session $session,
    ) {}

    /**
     * Generate (or retrieve) a CSRF token for the current session.
     *
     * @return string 64-character hex token.
     */
    public function generateToken(): string
    {
        return $this->session->token();
    }

    /**
     * Validate a token submitted by the client.
     *
     * Uses a timing-safe comparison internally.
     *
     * @param string $token The token to validate.
     *
     * @return bool True when the token matches the session token.
     */
    public function validateToken(string $token): bool
    {
        return $this->session->validateToken($token);
    }

    /**
     * Return an HTML hidden input element containing the current CSRF token.
     *
     * @return string HTML markup.
     */
    public function tokenField(): string
    {
        $token = htmlspecialchars($this->generateToken(), ENT_QUOTES, 'UTF-8');

        return sprintf(
            '<input type="hidden" name="_csrf_token" value="%s">',
            $token,
        );
    }
}
