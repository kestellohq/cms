<?php

declare(strict_types=1);

namespace Kestello\Cms\Hooks;

use Kestello\Cms\Events;
use Kestello\Cms\Platform\PlatformChanged;
use Kestello\Framework\Database\Orm\Events as Orm;

/**
 * The events and filters core offers, with the version each appeared in.
 * HooksTest fails when core dispatches or applies one that is missing
 * here. To rename or remove a hook, deprecate it first (see the hook
 * reference's deprecation policy) and keep it working until its removal
 * version.
 *
 * @internal Kernel
 */
final class CoreHooks
{
    public static function declare(HookRegistry $hooks): void
    {
        $accounts = 'Accounts and sign-in';
        foreach ([Events\UserRegistered::class, Events\UserLoggedIn::class, Events\UserLoggedOut::class, Events\LoginFailed::class,
            Events\PasswordChanged::class, Events\PasswordResetRequested::class, Events\TwoFactorEnabled::class, Events\TwoFactorDisabled::class,
            Events\TokenCreated::class, Events\TokenRevoked::class, Events\SessionRevoked::class, Events\IdentityLinked::class, Events\IdentityUnlinked::class] as $class) {
            $hooks->event($class, '0.1.0', $accounts);
        }
        $hooks->event(Events\EntryPublished::class, '0.1.0', 'Content');
        $hooks->event(Events\EntrySaved::class, '0.1.0', 'Content');
        $hooks->event(Events\EntryTrashed::class, '0.1.0', 'Content');
        $hooks->event(Events\EntrySubmitted::class, '0.1.0', 'Content');
        $hooks->event(Events\EntryReviewed::class, '0.1.0', 'Content');
        $hooks->event(Events\EntryCommented::class, '0.1.0', 'Content');
        $hooks->event(Events\EntryAssigned::class, '0.1.0', 'Content');
        $hooks->event(Events\FormSubmitted::class, '0.1.0', 'Forms');
        $hooks->event(Events\NotificationSent::class, '0.1.0', 'Notifications');
        $hooks->event(Events\NotificationFailed::class, '0.1.0', 'Notifications');
        $hooks->event(Events\JobFailed::class, '0.1.0', 'Background jobs');
        $hooks->event(Events\KernelBooted::class, '0.1.0', 'System');
        $hooks->event(PlatformChanged::class, '0.1.0', 'System');
        $hooks->event(Events\RegistryRefreshed::class, '0.1.0', 'Plugins and themes');
        foreach ([Orm\Saving::class, Orm\Creating::class, Orm\Updating::class, Orm\Created::class, Orm\Updated::class, Orm\Saved::class,
            Orm\Deleting::class, Orm\Deleted::class, Orm\Restoring::class, Orm\Restored::class] as $class) {
            $hooks->event($class, '0.1.0', 'Models');
        }

        $page = 'Pages';
        $hooks->filter('page.head', '0.1.0', 'string', 'HTML added to the <head> of public pages. Use the page nonce for inline scripts.', ['array $data' => 'The page props.'], $page);
        $hooks->filter('page.icons', '0.1.0', 'string', 'The favicon and theme colour tags of every page.', [], $page);
        $hooks->filter('page.body_open', '0.1.0', 'string', 'HTML right after <body> on public pages.', ['array $data' => 'The page props.'], $page);
        $hooks->filter('page.footer', '0.1.0', 'string', 'HTML right before </body> on public pages.', ['array $data' => 'The page props.'], $page);
        $hooks->filter('page.shared', '0.1.0', 'array', 'The data shared with every Vue page. It is sent to the browser, so never add secrets.', [], $page);
        $hooks->filter('seo.head', '0.1.0', 'list', 'The search and sharing tags of a page, as [\'meta\' or \'link\', attributes] pairs. Kestello escapes the attributes.', ['array $data' => 'The page props.'], $page);
        $hooks->filter('seo.schema', '0.1.0', 'list', 'The schema.org nodes of a page\'s JSON-LD @graph (WebSite, Organization or Person, WebPage, Article, BreadcrumbList). Add, change or remove nodes; each needs an @type. Pages that are not listed in search get none.', ['array $data' => 'The page: component, props and url.'], $page);
        $hooks->filter('seo.alternates', '0.1.0', 'list', 'The same page in other languages, as [\'hreflang\' => \'de\', \'href\' => \'/de/page\'] pairs, written as hreflang links. Invalid language tags and addresses are left out. Empty by default.', ['array $data' => 'The page: component, props and url.'], $page);
        $hooks->filter('sitemap.files', '0.1.0', 'array', 'The files listed in /sitemap.xml, name => last change (DateTimeInterface or null). Add "<name>-<n>" to serve /sitemaps/<name>-<n>.xml and fill it with sitemap.urls.', [], $page);
        $hooks->filter('sitemap.urls', '0.1.0', 'list', 'The addresses in one sitemap file, as [\'loc\' => \'/path\', \'lastmod\' => ?DateTimeInterface, \'images\' => [urls]]. Only addresses on this site are kept.', ['string $file' => 'The file name, such as post-1.'], $page);
        $hooks->filter('robots.txt', '0.1.0', 'string', 'The generated robots.txt.', [], $page);
        $hooks->filter('pagecache.query', '0.1.0', 'list', 'Query parameters that get their own page cache entry. The default is [\'page\'].', [], $page);

        $hooks->filter('security.csp', '0.1.0', 'array', 'The Content Security Policy, directive name => value. Names are lowercase; values may not contain ; or line breaks.', [], 'Security');

        $auth = 'Accounts and sign-in';
        $hooks->filter('auth.password.errors', '0.1.0', 'list', 'Errors for a new password. Add your own rules; core\'s errors cannot be removed.', ['string $password' => 'The new password.', '?array $user' => 'The user, when known (username, email).'], $auth);
        $who = [
            'string $email' => 'The address the account would use. Empty when a provider shared none.',
            '?ExternalIdentity $identity' => 'Who is signing in with a provider, or null for email sign-up.',
        ];
        $hooks->filter('auth.can_register', '0.1.0', 'bool', 'Whether someone may create an account, by email sign-up or by signing in with a provider.', $who, $auth);
        $hooks->filter('auth.default_role', '0.1.0', 'string', 'The role of an account someone creates for themselves. admin, roles with a sensitive capability and unknown roles become user.', $who, $auth);
        $hooks->filter('auth.redirect_after_login', '0.1.0', 'string', 'Where to go after signing in, with a password or a provider, or finishing email sign-up. The result must stay on this site.', ['array $user' => 'The user who signed in.'], $auth);
        $hooks->filter('auth.account_url', '0.1.0', 'string', 'Where someone manages their own account, such as after confirming a new email address. The default is the admin profile for people who may use the admin and the front page for everyone else. The result must stay on this site.', ['array $user' => 'The account.'], $auth);

        $hooks->filter('media.scan', '0.1.0', '?string', 'Check an uploaded file, for example with a virus scanner. Return a message to reject the upload.', ['array $file' => 'filename, mime, size, path, disk and key.'], 'Media');
        $hooks->filter('backup.skip_tables', '0.1.0', 'list', 'Tables to leave out of backups, such as a large log table.', [], 'Backups');
        $hooks->filter('api.response.{resource}', '0.1.0', 'array', 'The fields of one API resource row (users, entries, media...). Add or redact fields; a result that removes keys is ignored and logged.', ['array $row' => 'The row being shown.', 'Principal $principal' => 'Who is asking.'], 'API');
    }
}
