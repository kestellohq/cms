<?php

declare(strict_types=1);

namespace Kestello\Cms\Mail;

use Kestello\Framework\Log\Log;
use Kestello\Framework\Mail\MailTemplate;
use Kestello\Support\Html;
use Kestello\Support\Translator;

/**
 * Turns an email's blocks into its two parts: html() for mail clients
 * (tables and inline styles, which every client reads, plus a dark-mode
 * stylesheet for the clients that follow it) and text() for the
 * plain-text part. Both say the same thing in the same order. Every block's
 * text is escaped; nothing in a block can add markup.
 *
 * Blocks (MailMessage builds them):
 *
 *   ['type' => 'heading',   'text' => '…']
 *   ['type' => 'paragraph', 'text' => '…']                     line breaks kept
 *   ['type' => 'button',    'label' => '…', 'url' => 'https://…']
 *   ['type' => 'rows',      'rows' => [['label' => '…', 'value' => '…'], …]]
 *   ['type' => 'code',      'text' => '…']                     a one-time code, a command
 *   ['type' => 'note',      'text' => '…']                     small print
 *   ['type' => 'image',     'url' => 'https://…', 'alt' => '…', 'width' => 800, 'height' => 400]
 *   ['type' => 'divider']
 *
 * The first button's address is also written out at the end, for clients
 * that hide buttons.
 *
 * A theme can lay emails out itself (ThemeEmails): its file then wraps the
 * same rendered blocks, and Kestello's layout is used when it fails its check.
 *
 * @internal MailMessage::render() and text() are the public way in.
 */
final class MailLayout
{
    public const array TYPES = ['heading', 'paragraph', 'button', 'rows', 'code', 'note', 'image', 'divider'];
    /** The widest an image is drawn: the card's width inside its padding. */
    public const int IMAGE_WIDTH = 496;

    /** @param list<array<string, mixed>> $blocks */
    public static function html(string $subject, array $blocks, MailBrand $brand, ?ThemeEmail $theme = null): string
    {
        $e = static fn(string $s): string => Html::escape($s);
        $c = $brand->color(...);
        $body = '';
        $named = [];
        foreach ($blocks as $b) {
            $html = self::block($b, $c, $e);
            $body .= $html;
            if (is_string($b['name'] ?? null)) {
                $named[$b['name']] = $html;
            }
        }
        $link = self::firstLink($blocks);
        if ($link !== null) {
            $body .= '<p class="k-muted" style="margin:24px 0 0;font-size:12px;line-height:1.5;color:' . $c('muted') . ';overflow-wrap:anywhere">' . $e(Translator::get('If the button does not work, copy this link into your browser:')) . '<br>' . $e($link) . '</p>';
        }
        $img = '';
        if ($brand->logo !== null) {
            $height = MailBrand::LOGO_HEIGHT;
            $width = max(1, (int) round($brand->logo['width'] * $height / $brand->logo['height']));
            $img = '<img class="k-logo" src="' . $e($brand->logo['url']) . '" alt="' . $e($brand->name) . '" width="' . $width . '" height="' . $height . '" style="display:block;border:0;height:' . $height . 'px;width:' . $width . 'px;max-width:100%">';
            if ($brand->darkLogo !== null) {
                // hidden until the dark-mode CSS swaps the two; mail apps without it keep the logo
                $dw = max(1, (int) round($brand->darkLogo['width'] * $height / $brand->darkLogo['height']));
                $img .= '<img class="k-logo-dark" src="' . $e($brand->darkLogo['url']) . '" alt="' . $e($brand->name) . '" width="' . $dw . '" height="' . $height . '" style="display:none;mso-hide:all;border:0;height:' . $height . 'px;width:' . $dw . 'px;max-width:100%">';
            }
        }
        $footer = implode('<br>', array_map($e, $brand->footer !== '' ? preg_split('/\R/', $brand->footer) ?: [] : []));
        if ($theme !== null) {
            $themed = self::themed($theme, $subject, $body, $named, $img, $footer, $brand, $blocks);
            if ($themed !== null) {
                return $themed;
            }
        }

        return self::own($subject, $body, $img, $footer, $brand);
    }

    /** Kestello's own layout around the rendered blocks. */
    private static function own(string $subject, string $body, string $img, string $footer, MailBrand $brand): string
    {
        $e = static fn(string $s): string => Html::escape($s);
        $c = $brand->color(...);
        $font = $e($brand->font);
        $logo = $img !== '' ? '<tr><td style="padding:0 0 16px;text-align:start">' . $img . '</td></tr>' : '';
        $footer = $footer !== '' ? '<br>' . $footer : '';

        return '<!DOCTYPE html><html lang="' . $e(str_replace('_', '-', Translator::locale())) . '" dir="' . Translator::direction() . '">'
            . '<head><meta charset="utf-8"><meta name="viewport" content="width=device-width"><meta name="color-scheme" content="light dark"><meta name="supported-color-schemes" content="light dark">'
            . '<title>' . $e($subject) . '</title><style>' . self::darkCss($brand) . '</style></head>'
            . '<body class="k-page" style="margin:0;padding:0;background:' . $c('page') . '">'
            . '<table class="k-page" role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:' . $c('page') . '"><tr><td align="center" style="padding:24px 12px">'
            . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="max-width:560px">' . $logo . '<tr>'
            . '<td class="k-card" style="padding:32px;border-radius:14px;background:' . $c('card') . ';font-family:' . $font . ';font-size:16px;color:' . $c('ink') . ';text-align:start">' . $body . '</td>'
            . '</tr><tr><td class="k-muted" style="padding:16px 0 0;font-family:' . $font . ';font-size:12px;line-height:1.5;color:' . $c('muted') . ';text-align:center">' . $e($brand->name) . $footer . '</td></tr></table>'
            . '</td></tr></table></body></html>';
    }

    /**
     * The theme's file around the rendered blocks, or null when it fails its
     * check (logged, so the theme's author can see why).
     *
     * @param array<string, string> $named a named block's HTML by name
     * @param list<array<string, mixed>> $blocks
     */
    private static function themed(ThemeEmail $theme, string $subject, string $body, array $named, string $img, string $footer, MailBrand $brand, array $blocks): ?string
    {
        $names = $theme->id === null ? null : array_keys($named);
        $problems = ThemeEmails::problems($theme->html, $names, $theme->locked);
        if ($problems !== []) {
            Log::warning("{$theme->file} is not used for this email (" . implode('; ', $problems) . "); Kestello's own layout is.");

            return null;
        }
        $brandData = ['font' => $brand->font];
        foreach (array_keys(MailBrand::DEFAULT_COLORS) as $role) {
            $brandData[$role] = $brand->color($role);
        }

        return MailTemplate::render($theme->html, [
            'subject' => $subject,
            'lang' => str_replace('_', '-', Translator::locale()),
            'dir' => Translator::direction(),
            'site' => ['name' => $brand->name],
            'brand' => $brandData,
            'blocks' => $body,
            'block' => $named,
            'logo' => $img,
            'footer' => $footer,
            'dark_css' => self::darkCss($brand),
        ]);
    }

    /**
     * One block's HTML.
     *
     * @param array<string, mixed> $b
     * @param \Closure(string): string $c
     * @param \Closure(string): string $e
     */
    private static function block(array $b, \Closure $c, \Closure $e): string
    {
        return match ($b['type']) {
            'heading' => '<h1 class="k-ink" style="margin:0 0 16px;font-size:20px;line-height:1.3;color:' . $c('ink') . '">' . $e($b['text']) . '</h1>',
            'paragraph' => '<p style="margin:0 0 16px;line-height:1.6">' . nl2br($e($b['text']), false) . '</p>',
            'button' => '<table role="presentation" cellspacing="0" cellpadding="0" border="0" style="margin:24px 0"><tr><td class="k-btn" style="border-radius:8px;background:' . $c('accent') . '">'
                . '<a class="k-btn" href="' . $e($b['url']) . '" style="display:inline-block;padding:12px 20px;border-radius:8px;background:' . $c('accent') . ';color:' . $c('accentInk') . ';font-weight:600;text-decoration:none">' . $e($b['label']) . '</a>'
                . '</td></tr></table>',
            'rows' => self::rows($b['rows'], $c),
            'code' => '<p class="k-code" style="margin:0 0 16px;padding:12px 16px;border-radius:8px;background:' . $c('page') . ';font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:18px;letter-spacing:.04em;overflow-wrap:anywhere">' . $e($b['text']) . '</p>',
            'note' => '<p class="k-muted" style="margin:0 0 16px;font-size:13px;line-height:1.5;color:' . $c('muted') . '">' . nl2br($e($b['text']), false) . '</p>',
            'image' => self::image($b),
            'divider' => '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="margin:8px 0 24px"><tr><td class="k-row" style="border-top:1px solid ' . $c('line') . ';font-size:0;line-height:0">&nbsp;</td></tr></table>',
            default => throw new \InvalidArgumentException("Unknown email block \"{$b['type']}\"."),
        };
    }

    /**
     * The dark-mode colours, for mail apps that read a <style> and follow
     * the reader's setting (Apple Mail, iOS, Outlook for Mac, and others).
     * Elsewhere the inline light colours stay.
     */
    public static function darkCss(MailBrand $brand): string
    {
        $d = $brand->darkColor(...);

        return '@media (prefers-color-scheme: dark){'
            . '.k-page{background:' . $d('page') . '!important}'
            . '.k-card{background:' . $d('card') . '!important;color:' . $d('ink') . '!important}'
            . '.k-ink{color:' . $d('ink') . '!important}'
            . '.k-muted{color:' . $d('muted') . '!important}'
            . '.k-row{border-color:' . $d('line') . '!important}'
            . 'th.k-row{color:' . $d('muted') . '!important}'
            . '.k-code{background:' . $d('page') . '!important}'
            . '.k-btn{background:' . $d('accent') . '!important;color:' . $d('accentInk') . '!important}'
            . ($brand->darkLogo !== null ? '.k-logo{display:none!important}.k-logo-dark{display:block!important}' : '')
            . '}';
    }

    /**
     * The plain-text part: blocks separated by a blank line, a button as its
     * label then its address, rows as "label: value". Lines end in "\n"; the
     * Mailer turns them into CRLF.
     *
     * @param list<array<string, mixed>> $blocks
     */
    public static function text(array $blocks, MailBrand $brand): string
    {
        $parts = [];
        foreach ($blocks as $b) {
            $parts[] = match ($b['type']) {
                'heading', 'paragraph', 'code', 'note' => $b['text'],
                'button' => $b['label'] . "\n" . $b['url'],
                'image' => $b['alt'] !== '' ? '[' . $b['alt'] . ']' : null,
                'divider' => '----',
                'rows' => implode("\n", array_map(static fn(array $r): string => $r['label'] . ': ' . $r['value'], $b['rows'])),
                default => throw new \InvalidArgumentException("Unknown email block \"{$b['type']}\"."),
            };
        }
        $parts = array_values(array_filter($parts, static fn(?string $p): bool => $p !== null));
        $parts[] = $brand->footer !== '' ? $brand->name . "\n" . $brand->footer : $brand->name;

        return implode("\n\n", $parts);
    }

    /** @param array<string, mixed> $b */
    private static function image(array $b): string
    {
        $width = min((int) $b['width'], self::IMAGE_WIDTH);
        $height = max(1, (int) round((int) $b['height'] * $width / max(1, (int) $b['width'])));

        return '<p style="margin:0 0 16px"><img src="' . Html::escape($b['url']) . '" alt="' . Html::escape($b['alt']) . '" width="' . $width . '" height="' . $height . '" style="display:block;border:0;width:100%;max-width:' . $width . 'px;height:auto;border-radius:8px"></p>';
    }

    /** @param list<array<string, mixed>> $blocks */
    private static function firstLink(array $blocks): ?string
    {
        foreach ($blocks as $b) {
            if ($b['type'] === 'button') {
                return $b['url'];
            }
        }

        return null;
    }

    /**
     * @param list<array{label: string, value: string}> $rows
     * @param \Closure(string): string $c
     */
    private static function rows(array $rows, \Closure $c): string
    {
        $out = '';
        foreach ($rows as $r) {
            $out .= '<tr><th class="k-row" align="left" valign="top" scope="row" style="padding:8px 16px 8px 0;border-top:1px solid ' . $c('line') . ';color:' . $c('muted') . ';font-weight:600;text-align:start">' . Html::escape($r['label']) . '</th>'
                . '<td class="k-row" valign="top" style="padding:8px 0;border-top:1px solid ' . $c('line') . ';white-space:pre-wrap;word-break:break-word">' . Html::escape($r['value']) . '</td></tr>';
        }

        return '<table width="100%" cellspacing="0" cellpadding="0" border="0" style="margin:0 0 16px;border-collapse:collapse">' . $out . '</table>';
    }
}
