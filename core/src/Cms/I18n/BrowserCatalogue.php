<?php

declare(strict_types=1);

namespace Kestello\Cms\I18n;

use Kestello\Framework\Core\Response;
use Kestello\Support\Translator;

/**
 * The translations the browser needs: every domain's messages for one
 * locale ("core", then each active plugin and theme). Site pages get only
 * the plugins' and theme's (site: true): core's text is the admin's, and
 * core's site blocks bring theirs translated with their props.
 *
 * The admin boot data carries url() (null when the locale has no packs,
 * as for English, so nothing is fetched); the file behind it is versioned
 * by the packs' size and time, so browsers keep it until a pack changes.
 *
 * @internal
 */
final class BrowserCatalogue
{
    public const string PATH = '/admin/translations/';

    /** @return array<string, array<string, string>> domain => source text => translation */
    public static function messages(string $locale, bool $site = false): array
    {
        $out = [];
        foreach (Translator::domains() as $domain) {
            if ($site && $domain === 'core') {
                continue;
            }
            $messages = Translator::catalogue($domain, $locale);
            if ($messages !== []) {
                ksort($messages);
                $out[$domain] = $messages;
            }
        }

        return $out;
    }

    /** Changes whenever a pack for this locale does; null when there is none. */
    public static function version(string $locale): ?string
    {
        $files = Translator::packFiles($locale);
        if ($files === []) {
            return null;
        }
        $stamp = '';
        foreach ($files as $file) {
            $stamp .= $file . '|' . (string) @filesize($file) . '|' . (string) @filemtime($file) . "\n";
        }

        return substr(hash('sha256', $locale . "\n" . $stamp), 0, 16);
    }

    /** Null when there is nothing to download (English; on the site, no plugin or theme pack). */
    public static function url(string $locale, bool $site = false): ?string
    {
        $version = self::version($locale);
        if ($version === null || ($site && !self::siteHasMessages($locale, $version))) {
            return null;
        }

        return self::PATH . rawurlencode($locale) . '?v=' . $version . ($site ? '&site=1' : '');
    }

    /** @var array<string, bool> "locale|version" => whether plugins or the theme translate it */
    private static array $siteHas = [];

    /** Asked on every site page: read the packs once per version. */
    private static function siteHasMessages(string $locale, string $version): bool
    {
        return self::$siteHas["{$locale}|{$version}"] ??= self::messages($locale, site: true) !== [];
    }

    /**
     * GET /admin/translations/{locale}?v=: the catalogue as JSON. Public, as the
     * admin's own text is. A request with the current version may be kept for a
     * year (the URL changes with the packs); any other is checked again each time.
     */
    public static function respond(string $locale, mixed $requestedVersion, bool $site = false): Response
    {
        if (!Translator::isLocale($locale)) {
            return Response::json(['message' => 'Not a locale.'], 404)->withHeader('Cache-Control', 'no-cache');
        }
        $version = self::version($locale);
        if ($version === null) {
            return Response::json(['message' => "There are no translations for {$locale}."], 404)->withHeader('Cache-Control', 'no-cache');
        }
        $response = Response::json((object) self::messages($locale, $site))->withHeader('X-Content-Type-Options', 'nosniff');

        return $requestedVersion === $version
            ? $response->withHeader('Cache-Control', 'public, max-age=31536000, immutable')
            : $response->withHeader('Cache-Control', 'no-cache');
    }
}
