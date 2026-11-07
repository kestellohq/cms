<?php

declare(strict_types=1);

namespace Kestello\Contracts\Auth;

/**
 * A way to prove identity at login (password, Google, SAML…). Register with
 * $cms->auth->authenticator($instance); plugins need "auth": ["authenticator"]
 * in their manifest permissions.
 *
 * @api
 */
interface Authenticator
{
    /** URL-safe id, used in routes: /auth/{id}/redirect. */
    public function id(): string;

    /** Button text: "Continue with Google". */
    public function label(): string;

    /** A Phosphor icon name ("google-logo", see phosphoricons.com) or an asset path ("/assets/plugins/x/g.svg"), or null. */
    public function icon(): ?string;

    /** Whether the provider verifies email addresses (Google: yes). Needed for linking by email. */
    public function trustsEmail(): bool;
}
