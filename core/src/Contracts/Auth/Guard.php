<?php

declare(strict_types=1);

namespace Kestello\Contracts\Auth;

use Kestello\Framework\Core\Request;

/**
 * How a request is authenticated (session cookie, personal access token,
 * a plugin's OAuth access token...).
 *
 * Guards that read the Authorization header are "bearer" guards. When a
 * request carries a bearer token, only bearer guards are asked and cookies
 * are ignored entirely; if none accepts the token the request is rejected
 * (it never silently falls back to the session).
 *
 * Build the Principal with $cms->auth->principalFor($userRow, ...), so
 * capabilities come from the user's role like everywhere else.
 *
 * @api
 */
interface Guard
{
    public function id(): string;

    /** True for guards that authenticate the Authorization: Bearer header. */
    public function isBearer(): bool;

    /** Null when this guard does not apply to (or cannot verify) the request. */
    public function authenticate(Request $request): ?Principal;
}
