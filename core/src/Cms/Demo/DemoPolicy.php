<?php

declare(strict_types=1);

namespace Kestello\Cms\Demo;

/**
 * What a public demo lets visitors change through the API.
 *
 * Reading is allowed, except what would show one visitor another's
 * traces: everyone signs in to the same account, so its sessions and the
 * audit log hold other visitors' addresses and browsers. Backups (the
 * whole database) and files sent with forms (anyone could leave a file
 * for anyone) stay closed too, and so does the server's debug info.
 *
 * A change is allowed only when it is on the
 * list below: everything else, including endpoints added later by core or
 * plugins, is refused. So the demo stays closed by default.
 *
 * Open: content, its taxonomies, comments and previews; the design
 * (styles and templates); menus; forms and their submissions; images in
 * the media library; signing in and out; the person's own notifications.
 *
 * Closed, among others: the account itself (password, email, two-factor,
 * tokens), people and roles, plugins, themes, updates, backups, settings,
 * imports, webhooks, redirects (they could point anywhere), fonts (they
 * are fetched from other servers), and anything that needs a recent
 * sign-in: that is how the API marks what is sensitive.
 */
final class DemoPolicy
{
    public const string REFUSED = 'This is turned off in the demo.';

    /** Capabilities whose changes the demo allows. */
    private const array OPEN_CAPABILITIES = ['content.access', 'theme.design', 'menus.manage', 'forms.manage', 'media.upload'];

    /** Changes allowed regardless of capability: "METHOD /path/template". */
    private const array OPEN_ROUTES = [
        'POST /auth/session',
        'DELETE /auth/session',
        'POST /auth/2fa/verify',
        'POST /auth/confirm',
        'POST /forms/{id}/submissions',
        'PATCH /media/{id}',
        'DELETE /media/{id}',
        'POST /media/{id}/edit',
        'POST /media/{id}/restore',
        'POST /me/notifications/read',
        'PUT /me/notification-preferences',
    ];

    /** Reads refused in the demo. */
    private const array CLOSED_READS = [
        'GET /audit',
        'GET /me/sessions',
        'GET /security',
        'GET /security/sessions',
        'GET /security/tokens',
        'GET /backups/{name}/download',
        'GET /system/info',
        'GET /form-submissions/{id}/files/{field}/{index}',
    ];

    /** Changes refused although their capability is open. */
    private const array CLOSED_ROUTES = [
        'POST /themes/fonts',                                   // downloads font files from another server
        'DELETE /themes/fonts/{slug}',                          // files on disk, not in the database the demo resets
        'POST /design/templates/{kind}/{key}/theme-update',     // writes into the theme's folder
    ];

    /**
     * Whether the demo lets this request through.
     *
     * @param string $path the route's template, as registered ("/media/{id}"), without the API prefix
     */
    public static function allows(string $method, string $path, ?string $capability, bool $recentAuth): bool
    {
        $method = strtoupper($method);
        $route = $method . ' ' . $path;
        if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            return !in_array('GET ' . $path, self::CLOSED_READS, true);
        }
        if (in_array($route, self::CLOSED_ROUTES, true)) {
            return false;
        }
        if (in_array($route, self::OPEN_ROUTES, true)) {
            return true;
        }

        return !$recentAuth && $capability !== null && in_array($capability, self::OPEN_CAPABILITIES, true);
    }
}
