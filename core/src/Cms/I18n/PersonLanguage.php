<?php

declare(strict_types=1);

namespace Kestello\Cms\I18n;

use Kestello\Cms\Auth\Emails;
use Kestello\Cms\Cms;
use Kestello\Framework\Database\QueryBuilder;
use Kestello\Framework\Settings\Settings;
use Kestello\Support\Translator;

/**
 * The language a person reads the site's emails in: users.locale, one of
 * the site's languages (SiteLanguage::choices()), or '' for the site's own
 * language, which then follows the site when it changes.
 *
 *   PersonLanguage::of($user)              "sr", or null for the site's language
 *   PersonLanguage::forAddress($email)     the same for whoever has that address
 *   PersonLanguage::atSignUp()             what a new account starts with
 *
 * @api
 */
final class PersonLanguage
{
    /** The stored value for "the site's language". */
    public const string SITE = '';

    /** @return list<string> what users.locale may hold: '' and the site's languages */
    public static function allowed(): array
    {
        return array_merge([self::SITE], array_keys(SiteLanguage::choices()));
    }

    /** @param array<string, mixed> $user a users row */
    public static function of(array $user): ?string
    {
        $locale = (string) ($user['locale'] ?? '');

        return $locale !== self::SITE && in_array($locale, self::allowed(), true) ? $locale : null;
    }

    /** The language of the account with this address, or null without one (or without a language of its own). */
    public static function forAddress(string $email): ?string
    {
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }
        try {
            $user = QueryBuilder::table('users')->select('locale')->where('email', Emails::normalize($email))->first();
        } catch (\Throwable) {
            return null;    // no database (an early boot, a unit test): the current language stands
        }

        return $user === null ? null : self::of($user);
    }

    /**
     * A new account's language: the page's, when someone signs up in a
     * language other than the site's; otherwise the site's, so it follows
     * the site.
     */
    public static function atSignUp(): string
    {
        $current = Translator::locale();
        $site = SiteLanguage::resolve((string) Settings::getInstance()->get(SiteLanguage::SETTING, ''), (string) (Cms::instance()?->config->get('app.locale', 'en') ?? 'en'));

        return $current !== $site && in_array($current, self::allowed(), true) ? $current : self::SITE;
    }
}
