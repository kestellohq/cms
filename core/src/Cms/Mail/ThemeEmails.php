<?php

declare(strict_types=1);

namespace Kestello\Cms\Mail;

use Kestello\Cms\Cms;
use Kestello\Cms\Extension\Package;

/**
 * A theme's own email markup, kept in its code:
 *
 *   emails/layout.html             how every email the site sends is laid out
 *   emails/<email id>.html         how one email is laid out, such as emails/auth.password_reset.html
 *
 * A child theme's file wins over its parent's, and an email's own file over
 * the layout. Either is written with MailTemplate placeholders:
 *
 *   {{ subject }} {{ lang }} {{ dir }} {{ site.name }}
 *   {{ brand.page }} {{ brand.card }} {{ brand.ink }} {{ brand.muted }} {{ brand.line }}
 *   {{ brand.accent }} {{ brand.accentInk }} {{ brand.font }}      the colours MailBrand worked out
 *   {!! blocks !!}          the email's blocks, rendered (a layout must have it)
 *   {!! block.<name> !!}    one named block, in an email's own file
 *   {!! logo !!} {!! footer !!} {!! dark_css !!}                   the logo's <img>, the footer's lines, the dark-mode CSS
 *
 * Only these are written raw: everything else is escaped. The files are
 * checked (problems()) when the theme is activated, by plugin:check, and
 * again before each email is rendered; an email whose file fails the check
 * is laid out by Kestello's own layout instead, and the reason is logged.
 *
 * @internal MailMessage::render() with an email's id uses it.
 */
final class ThemeEmails
{
    public const string FOLDER = 'emails';
    public const string LAYOUT = 'layout';
    /** A file larger than this is refused: an email's markup, not a page's. */
    public const int MAX_BYTES = 200_000;

    /** Placeholders that are escaped: {{ … }}. */
    public const array TEXT = [
        'subject', 'lang', 'dir', 'site.name',
        'brand.page', 'brand.card', 'brand.ink', 'brand.muted', 'brand.line', 'brand.accent', 'brand.accentInk', 'brand.font',
    ];

    /** Placeholders written raw: {!! … !!}, plus block.<name>. Kestello builds each from escaped parts. */
    public const array RAW = ['blocks', 'logo', 'footer', 'dark_css'];

    /** Markup an email cannot carry, and why. */
    private const array FORBIDDEN = [
        '/<script\b/i' => 'scripts (<script>)',
        '/<form\b/i' => 'forms (<form>)',
        '/<(iframe|frame|object|embed|applet)\b/i' => 'embedded frames or objects',
        '/<base\b/i' => 'a <base> tag',
        '/<meta[^>]+http-equiv/i' => 'a <meta http-equiv> tag',
        '/<[a-z][^>]*[\s\/"\']on[a-z]+\s*=/i' => 'event handlers (onclick=, onload=, …)',
        '/javascript\s*:/i' => 'javascript: links',
    ];

    private const string PLACEHOLDER = '/\{!!\s*([A-Za-z0-9_.]+)\s*!!\}|\{\{\s*([A-Za-z0-9_.]+)\s*\}\}/';

    /** @var array<string, ThemeEmail|null> per theme chain and email */
    private static array $found = [];

    /**
     * The active theme's markup for an email: its own file, else the
     * layout, else null (Kestello's layout). Null too before extensions boot.
     */
    public static function find(string $id): ?ThemeEmail
    {
        $cms = Cms::instance();
        if ($cms === null) {
            return null;
        }
        try {
            $chain = $cms->extensions()->themeChain();
        } catch (\LogicException) {
            return null;
        }
        $key = implode('<', array_map(static fn(Package $p): string => $p->path(), $chain)) . '|' . $id;
        if (array_key_exists($key, self::$found)) {
            return self::$found[$key];
        }
        $names = preg_match('/^' . EmailRegistry::ID . '$/', $id) === 1 ? [$id, self::LAYOUT] : [self::LAYOUT];
        foreach ($names as $name) {
            foreach ($chain as $theme) {
                $file = $theme->path(self::FOLDER . '/' . $name . '.html');
                if (is_file($file)) {
                    $own = $name !== self::LAYOUT;
                    $locked = $own && $cms->emails->has($id) ? $cms->emails->get($id)['locked'] : [];

                    return self::$found[$key] = new ThemeEmail($own ? $id : null, $file, (string) file_get_contents($file), $locked);
                }
            }
        }

        return self::$found[$key] = null;
    }

    /** Forget what find() read, after a theme switch or in tests. */
    public static function forget(): void
    {
        self::$found = [];
    }

    /**
     * What is wrong with a layout ($names null) or with one email's file
     * ($names: that email's block names, or [] when they aren't known, as
     * for plugin:check). Empty when it can be used.
     *
     * @param list<string>|null $names
     * @param list<string> $locked blocks the email must show: its own link, a code
     * @return list<string>
     */
    public static function problems(string $html, ?array $names = null, array $locked = []): array
    {
        if (strlen($html) > self::MAX_BYTES) {
            return ['it is larger than ' . (self::MAX_BYTES / 1000) . ' KB'];
        }
        $problems = [];
        // entities decoded too, so java&#115;cript: is found as what a mail app reads
        $decoded = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        foreach (self::FORBIDDEN as $pattern => $what) {
            if (preg_match($pattern, $html) === 1 || ($what === 'javascript: links' && preg_match($pattern, $decoded) === 1)) {
                $problems[] = "it has {$what}, which emails cannot carry";
            }
        }
        preg_match_all(self::PLACEHOLDER, $html, $matches, PREG_SET_ORDER);
        $raw = [];
        foreach ($matches as $m) {
            if (($m[1] ?? '') !== '') {
                $key = $m[1];
                $block = str_starts_with($key, 'block.') ? substr($key, 6) : null;
                if ($block !== null && $names === null) {
                    $problems[] = "{!! {$key} !!} names one email's block; a layout places them all with {!! blocks !!}";
                } elseif ($block !== null && $names !== [] && !in_array($block, $names, true)) {
                    $problems[] = "the email has no block named \"{$block}\"; it has " . implode(', ', $names);
                } elseif ($block === null && !in_array($key, self::RAW, true)) {
                    $problems[] = in_array($key, self::TEXT, true)
                        ? "{!! {$key} !!} would not be escaped: write {{ {$key} }}"
                        : "there is no placeholder \"{$key}\"; raw ones are " . implode(', ', self::RAW) . ' and block.<name>';
                }
                $raw[] = $key;
            } elseif (!in_array($m[2], self::TEXT, true)) {
                $problems[] = "there is no placeholder \"{$m[2]}\"" . (in_array($m[2], self::RAW, true) || str_starts_with($m[2], 'block.') ? "; write {!! {$m[2]} !!}" : '; known ones are ' . implode(', ', self::TEXT));
            }
        }
        if ($names === null && !in_array('blocks', $raw, true)) {
            $problems[] = 'a layout must place the email with {!! blocks !!}';
        }
        if ($names !== null && !in_array('blocks', $raw, true)) {
            foreach ($locked as $name) {
                if (!in_array('block.' . $name, $raw, true)) {
                    $problems[] = "it must show the block \"{$name}\" ({!! block.{$name} !!}) or all of them ({!! blocks !!}): the email does not work without it";
                }
            }
        }

        return array_values(array_unique($problems));
    }

    /**
     * Every email file a theme brings, with its parent's, checked: what
     * stops it being activated. A file for an email no package registers
     * is logged and left alone, since the plugin it is for may come later.
     *
     * @return list<string> "emails/<file>: <problem>"
     */
    public static function check(Package $theme, ?Package $parent, ?EmailRegistry $registry): array
    {
        $problems = [];
        foreach (array_filter([$theme, $parent]) as $package) {
            foreach (glob($package->path(self::FOLDER . '/*.html')) ?: [] as $file) {
                $name = basename($file, '.html');
                $html = (string) file_get_contents($file);
                if ($name === self::LAYOUT) {
                    $found = self::problems($html);
                } elseif ($registry !== null && $registry->has($name)) {
                    $blocks = array_values(array_filter(array_map(static fn(array $b): ?string => $b['name'] ?? null, $registry->original($name, $registry->get($name)['sample'])->blocks())));
                    $found = self::problems($html, $blocks, $registry->get($name)['locked']);
                } else {
                    error_log("[kestello] {$package->slug()}/" . self::FOLDER . "/{$name}.html is for an email no installed package sends (\"{$name}\"); it is used once one does.");
                    $found = self::problems($html, []);
                }
                foreach ($found as $problem) {
                    $problems[] = self::FOLDER . "/{$name}.html: {$problem}";
                }
            }
        }

        return $problems;
    }
}
