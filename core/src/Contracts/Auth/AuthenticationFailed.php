<?php

declare(strict_types=1);

namespace Kestello\Contracts\Auth;

/**
 * An external sign-in did not succeed. getMessage() is shown to the user,
 * so keep details (tokens, provider error bodies) in $detail, which is only logged.
 *
 * @api
 */
final class AuthenticationFailed extends \RuntimeException
{
    public function __construct(string $message = 'Sign-in failed. Please try again.', public readonly ?string $detail = null, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
