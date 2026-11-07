<?php

declare(strict_types=1);

namespace Kestello\Cms\I18n;

use Kestello\Support\Translator;

/**
 * Translating what extensions register (block names, field labels, menu
 * entries, settings) when it is sent to the admin, in the domain of whoever
 * registered it: "core" for core, the slug for "plugin:<slug>" and
 * "theme:<slug>". Text written in the admin by people (a content type built
 * there) has no translation and comes back as it is.
 *
 * Extract these strings with `bin/cms i18n:extract`: it finds ->label(),
 * ->help(), ->description() and the label/title/description arguments.
 *
 * @api
 */
final class Labels
{
    /** "core", or the slug of a "plugin:<slug>" or "theme:<slug>" owner. */
    public static function domain(string $owner): string
    {
        return str_contains($owner, ':') ? explode(':', $owner, 2)[1] : ($owner === '' ? 'core' : $owner);
    }

    /** The translation in that domain (else core's), or the text itself. */
    public static function text(string $text, string $domain): string
    {
        if ($text === '') {
            return '';
        }
        if ($domain !== 'core' && !Translator::has($text, $domain) && Translator::has($text)) {
            return Translator::get($text);
        }

        return Translator::get($text, domain: $domain);
    }

    /**
     * Choice labels (value => label); values stay as they are.
     *
     * @param array<array-key, mixed> $choices
     * @return array<array-key, mixed>
     */
    public static function choices(array $choices, string $domain): array
    {
        if (array_is_list($choices)) {
            return $choices; // bare values are data, not text
        }

        return array_map(static fn(mixed $label) => is_string($label) ? self::text($label, $domain) : $label, $choices);
    }

    /**
     * An OpenAPI schema with its descriptions and value labels translated
     * (settings forms are drawn from it).
     *
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    public static function schema(array $schema, string $domain): array
    {
        foreach (['description', 'title'] as $key) {
            if (isset($schema[$key]) && is_string($schema[$key])) {
                $schema[$key] = self::text($schema[$key], $domain);
            }
        }
        if (isset($schema['x-enum-labels']) && is_array($schema['x-enum-labels'])) {
            $schema['x-enum-labels'] = self::choices($schema['x-enum-labels'], $domain);
        }
        foreach (['properties', 'items'] as $nested) {
            if (!isset($schema[$nested]) || !is_array($schema[$nested])) {
                continue;
            }
            $schema[$nested] = $nested === 'items'
                ? self::schema($schema[$nested], $domain)
                : array_map(static fn(mixed $s) => is_array($s) ? self::schema($s, $domain) : $s, $schema[$nested]);
        }

        return $schema;
    }
}
