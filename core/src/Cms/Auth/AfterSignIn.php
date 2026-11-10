<?php

declare(strict_types=1);

namespace Kestello\Cms\Auth;

use Kestello\Cms\Auth\External\ReturnPath;
use Kestello\Framework\Events\Filters;

/**
 * Where people go after they sign in, and where their own account lives. One rule for every way in:
 * password, provider and email sign-up.
 *
 *   AfterSignIn::path($user, $returnTo, $roles, $filters)   after signing in
 *   AfterSignIn::account($user, $roles, $filters)           their account settings
 *
 * People who may use the admin go to where they were headed, or /admin and their admin profile;
 * everyone else goes to the site's front page. A site with an account area of its own sends them
 * there with the filters auth.redirect_after_login and auth.account_url. Results stay on this site.
 */
final class AfterSignIn
{
    /** @param array<string, mixed> $user */
    public static function path(array $user, ?string $returnTo, Roles $roles, ?Filters $filters): string
    {
        $default = ReturnPath::sanitize($returnTo) ?? (self::admin($user, $roles) ? '/admin' : '/');

        return ReturnPath::sanitize($filters?->apply('auth.redirect_after_login', $default, $user) ?? $default) ?? $default;
    }

    /** @param array<string, mixed> $user */
    public static function account(array $user, Roles $roles, ?Filters $filters): string
    {
        $default = self::admin($user, $roles) ? '/admin/profile' : '/';

        return ReturnPath::sanitize($filters?->apply('auth.account_url', $default, $user) ?? $default) ?? $default;
    }

    /** @param array<string, mixed> $user */
    private static function admin(array $user, Roles $roles): bool
    {
        $caps = $roles->capabilitiesFor((string) ($user['role'] ?? ''));

        return in_array('*', $caps, true) || in_array('admin.access', $caps, true);
    }
}
