<?php

declare(strict_types=1);

namespace Kestello\Cms\Content;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Kestello\Cms\Cms;
use Kestello\Framework\Settings\Settings;

/**
 * Address patterns for dated types: /blog/{slug},
 * /blog/{year}/{slug}, /blog/{year}/{month}/{slug} or
 * /blog/{year}/{month}/{day}/{slug}. Dates use the site's timezone.
 *
 * An entry is always found by its slug alone (slugs are unique per type);
 * the date parts only have to look like dates. Any other shape of the
 * address answers with a 301 to the current one, so changing the pattern
 * or an entry's date never breaks a link and needs no redirect rules.
 */
final class Permalinks
{
    /** @var array<string, string> pattern => example */
    public const array PATTERNS = [
        '{slug}' => 'sample-post',
        '{year}/{slug}' => '2026/sample-post',
        '{year}/{month}/{slug}' => '2026/11/sample-post',
        '{year}/{month}/{day}/{slug}' => '2026/11/05/sample-post',
    ];

    /** Types with an archive, flat addresses and a path prefix: the ones whose entries are dated. */
    public static function supports(ContentType $type): bool
    {
        return $type->hasArchive() && !$type->isHierarchical();
    }

    /** The pattern in use: Settings → Reading, then the type's own default. */
    public static function pattern(ContentType $type): string
    {
        if (!self::supports($type)) {
            return '{slug}';
        }
        $chosen = Settings::getInstance()->get('permalink_' . $type->name);

        return is_string($chosen) && isset(self::PATTERNS[$chosen]) ? $chosen : $type->getPermalink();
    }

    /** "2026/11/hello" for a slug and a publication date (drafts use today). */
    public static function segments(ContentType $type, string $slug, mixed $publishedAt): string
    {
        $pattern = self::pattern($type);
        if ($pattern === '{slug}') {
            return $slug;
        }
        $date = (self::date($publishedAt) ?? new DateTimeImmutable())->setTimezone(self::timezone());

        return strtr($pattern, ['{year}' => $date->format('Y'), '{month}' => $date->format('m'), '{day}' => $date->format('d'), '{slug}' => $slug]);
    }

    /** Whether the segments before a slug could be date parts (2026, 2026/11, 2026/11/05). */
    public static function looksDated(array $prefix): bool
    {
        $shapes = ['/^\d{4}$/', '/^\d{2}$/', '/^\d{2}$/'];
        if (count($prefix) > 3) {
            return false;
        }
        foreach (array_values($prefix) as $i => $segment) {
            if (preg_match($shapes[$i], (string) $segment) !== 1) {
                return false;
            }
        }

        return true;
    }

    /** "Sample: /blog/2026/11/sample-post" choices for the settings form. @return array<string, string> */
    public static function choices(ContentType $type): array
    {
        $out = [];
        foreach (self::PATTERNS as $pattern => $example) {
            $out[$pattern] = $type->getPath() . '/' . $example;
        }

        return $out;
    }

    public static function timezone(): DateTimeZone
    {
        $name = (string) (Settings::getInstance()->get('timezone') ?: Cms::instance()?->config->get('app.timezone', 'UTC') ?: 'UTC');
        try {
            return new DateTimeZone($name);
        } catch (\Exception) {
            return new DateTimeZone('UTC');
        }
    }

    public static function date(mixed $value): ?DateTimeImmutable
    {
        if ($value instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value);
        }
        if (!is_string($value) || $value === '') {
            return null;
        }
        try {
            return new DateTimeImmutable($value, new DateTimeZone('UTC'));
        } catch (\Exception) {
            return null;
        }
    }
}
