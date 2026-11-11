<?php

declare(strict_types=1);

namespace Kestello\Cms\Mail;

use Kestello\Support\Html;
use Kestello\Support\Translator;

/**
 * Turns an email's blocks into its two parts: html() for mail clients
 * (tables and inline styles, which every client reads) and text() for the
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
 *
 * The first button's address is also written out at the end, for clients
 * that hide buttons.
 *
 * @internal MailMessage::render() and text() are the public way in.
 */
final class MailLayout
{
    public const array TYPES = ['heading', 'paragraph', 'button', 'rows', 'code', 'note'];

    /** @param list<array<string, mixed>> $blocks */
    public static function html(string $subject, array $blocks, MailBrand $brand): string
    {
        $e = static fn(string $s): string => Html::escape($s);
        $c = $brand->color(...);
        $body = '';
        foreach ($blocks as $b) {
            $body .= match ($b['type']) {
                'heading' => '<h1 style="margin:0 0 16px;font-size:20px;line-height:1.3;color:' . $c('ink') . '">' . $e($b['text']) . '</h1>',
                'paragraph' => '<p style="margin:0 0 16px;line-height:1.6">' . nl2br($e($b['text']), false) . '</p>',
                'button' => '<table role="presentation" cellspacing="0" cellpadding="0" border="0" style="margin:24px 0"><tr><td style="border-radius:8px;background:' . $c('accent') . '">'
                    . '<a href="' . $e($b['url']) . '" style="display:inline-block;padding:12px 20px;border-radius:8px;background:' . $c('accent') . ';color:' . $c('accentInk') . ';font-weight:600;text-decoration:none">' . $e($b['label']) . '</a>'
                    . '</td></tr></table>',
                'rows' => self::rows($b['rows'], $c),
                'code' => '<p style="margin:0 0 16px;padding:12px 16px;border-radius:8px;background:' . $c('page') . ';font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:18px;letter-spacing:.04em;overflow-wrap:anywhere">' . $e($b['text']) . '</p>',
                'note' => '<p style="margin:0 0 16px;font-size:13px;line-height:1.5;color:' . $c('muted') . '">' . nl2br($e($b['text']), false) . '</p>',
                default => throw new \InvalidArgumentException("Unknown email block \"{$b['type']}\"."),
            };
        }
        $link = self::firstLink($blocks);
        if ($link !== null) {
            $body .= '<p style="margin:24px 0 0;font-size:12px;line-height:1.5;color:' . $c('muted') . ';overflow-wrap:anywhere">' . $e(Translator::get('If the button does not work, copy this link into your browser:')) . '<br>' . $e($link) . '</p>';
        }

        return '<!DOCTYPE html><html lang="' . $e(str_replace('_', '-', Translator::locale())) . '" dir="' . Translator::direction() . '">'
            . '<head><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>' . $e($subject) . '</title></head>'
            . '<body style="margin:0;padding:0;background:' . $c('page') . '">'
            . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:' . $c('page') . '"><tr><td align="center" style="padding:24px 12px">'
            . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="max-width:560px"><tr>'
            . '<td style="padding:32px;border-radius:14px;background:' . $c('card') . ';font-family:' . $e($brand->font) . ';font-size:16px;color:' . $c('ink') . ';text-align:start">' . $body . '</td>'
            . '</tr><tr><td style="padding:16px 0 0;font-family:' . $e($brand->font) . ';font-size:12px;color:' . $c('muted') . ';text-align:center">' . $e($brand->name) . '</td></tr></table>'
            . '</td></tr></table></body></html>';
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
                'rows' => implode("\n", array_map(static fn(array $r): string => $r['label'] . ': ' . $r['value'], $b['rows'])),
                default => throw new \InvalidArgumentException("Unknown email block \"{$b['type']}\"."),
            };
        }
        $parts[] = $brand->name;

        return implode("\n\n", $parts);
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
            $out .= '<tr><th align="left" valign="top" scope="row" style="padding:8px 16px 8px 0;border-top:1px solid ' . $c('line') . ';color:' . $c('muted') . ';font-weight:600;text-align:start">' . Html::escape($r['label']) . '</th>'
                . '<td valign="top" style="padding:8px 0;border-top:1px solid ' . $c('line') . ';white-space:pre-wrap;word-break:break-word">' . Html::escape($r['value']) . '</td></tr>';
        }

        return '<table width="100%" cellspacing="0" cellpadding="0" border="0" style="margin:0 0 16px;border-collapse:collapse">' . $out . '</table>';
    }
}
