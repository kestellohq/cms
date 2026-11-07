<?php

declare(strict_types=1);

namespace Kestello\Cms\Site;

use Kestello\Framework\Settings\Settings;

/**
 * Code snippets: HTML an administrator adds to the head, the start
 * of the body or the end of the body of every public page (analytics,
 * verification tags, chat widgets). HTML only, never PHP: server code
 * belongs in a plugin, so a stolen admin password can't run code on the server.
 *
 * Scripts get the page's CSP nonce, so they run under the strict policy
 * (and may load further scripts through 'strict-dynamic'). Inline event
 * handlers (onclick="…") still don't run. Snippets never reach the admin,
 * the sign-in pages or the installer, where third-party code could read passwords.
 */
final class Snippets
{
    public const array PLACES = ['head' => 'snippet_head', 'body' => 'snippet_body', 'footer' => 'snippet_footer'];

    private const array PRIVATE_PAGES = ['@core/auth/', '@core/installer/', '@core/admin/'];

    /** @param array{component?: string, props?: array<string, mixed>} $data */
    public static function render(string $place, array $data, string $nonce): string
    {
        $component = (string) ($data['component'] ?? '');
        if (isset($data['props']['admin'])) {
            return '';
        }
        foreach (self::PRIVATE_PAGES as $prefix) {
            if (str_starts_with($component, $prefix)) {
                return '';
            }
        }
        $html = trim((string) Settings::getInstance()->get(self::PLACES[$place] ?? '', ''));

        return $html === '' ? '' : self::nonced($html, $nonce) . "\n";
    }

    /** Add the nonce to every <script> tag that doesn't have one (and replace a stale one). */
    public static function nonced(string $html, string $nonce): string
    {
        $nonce = htmlspecialchars($nonce, ENT_QUOTES, 'UTF-8');

        return (string) preg_replace_callback('/<script\b([^>]*)>/i', static function (array $m) use ($nonce): string {
            $attrs = (string) preg_replace('/\snonce\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $m[1]);

            return '<script nonce="' . $nonce . '"' . $attrs . '>';
        }, $html);
    }
}
