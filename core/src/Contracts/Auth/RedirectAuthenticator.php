<?php

declare(strict_types=1);

namespace Kestello\Contracts\Auth;

use Kestello\Framework\Core\Request;

/**
 * Providers that send the browser away and back (OAuth 2, OIDC, SAML).
 * Core creates the AuthAttempt (state, nonce, PKCE, return path), stores it
 * server-side bound to the session, validates and consumes it on callback,
 * and only then calls complete(). Extend Kestello\Cms\Auth\External\OAuth2Authenticator
 * or OidcAuthenticator instead of implementing this from scratch.
 *
 * @api
 */
interface RedirectAuthenticator extends Authenticator
{
    /** The provider URL to send the browser to (must be https). */
    public function authorizationUrl(AuthAttempt $attempt): string;

    /**
     * Finish on callback: exchange the code, verify, return who this is.
     *
     * @throws AuthenticationFailed with a message safe to show the user
     */
    public function complete(Request $request, AuthAttempt $attempt): ExternalIdentity;
}
