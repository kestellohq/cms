<?php

declare(strict_types=1);

namespace Kestello\Cms\Media\Image;

/**
 * Image formats as people write them, for messages such as Site Health's:
 *
 *   FormatNames::sentence(['webp', 'avif'])   "WebP and AVIF"
 *   FormatNames::list(['webp', 'avif'])       "WebP, AVIF"
 *
 * A format without a known name is shown in capitals, as file types usually are.
 *
 * @internal
 */
final class FormatNames
{
    private const array NAMES = ['webp' => 'WebP', 'avif' => 'AVIF', 'jpeg' => 'JPEG', 'jpg' => 'JPEG', 'png' => 'PNG', 'gif' => 'GIF'];

    public static function name(string $format): string
    {
        return self::NAMES[strtolower($format)] ?? strtoupper($format);
    }

    /** @param list<string> $formats */
    public static function list(array $formats): string
    {
        return implode(', ', array_map(self::name(...), $formats));
    }

    /** @param list<string> $formats */
    public static function sentence(array $formats): string
    {
        $names = array_map(self::name(...), $formats);
        $last = array_pop($names);

        return $names === [] ? (string) $last : implode(', ', $names) . ' and ' . $last;
    }
}
