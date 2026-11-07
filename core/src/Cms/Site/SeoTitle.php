<?php

declare(strict_types=1);

namespace Kestello\Cms\Site;

/**
 * A page title from the site's title pattern (Settings → Search and
 * sharing): "{title} {sep} {site}". The same rule runs in the browser
 * (app/document-title.js), so both write the same <title>.
 *
 * Placeholders are replaced in one pass, so a title that contains
 * "{site}" stays as written. A placeholder left empty takes its separator
 * with it: "{site} {sep} {tagline}" without a tagline is "Bakery".
 */
final class SeoTitle
{
    public const string PAGE = '{title} {sep} {site}';
    public const string HOME = '{site}';
    public const string SEPARATOR = '-';

    /** The separators people can choose, as value => label. */
    public const array SEPARATORS = ['-' => '-', '·' => '·', '|' => '|', '/' => '/', '»' => '»', '•' => '•'];

    public static function format(string $pattern, string $title, string $site, string $sep = self::SEPARATOR, string $tagline = ''): string
    {
        $out = strtr($pattern, ['{title}' => $title, '{site}' => $site, '{sep}' => $sep, '{tagline}' => $tagline]);
        $out = trim((string) preg_replace('/\s+/u', ' ', $out));
        if ($sep !== '') {
            $q = preg_quote($sep, '/');
            do {
                $before = $out;
                $out = trim((string) preg_replace(["/^{$q}\\s*/u", "/\\s*{$q}$/u", "/{$q}\\s*{$q}/u"], ['', '', $sep], $out));
            } while ($out !== $before);
        }

        return $out !== '' ? $out : ($title !== '' ? $title : $site);
    }
}
