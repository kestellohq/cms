<?php

declare(strict_types=1);

namespace Kestello\Cms\Notifications;

use Kestello\Support\Html;
use Kestello\Support\Translator;

/**
 * A simple, accessible transactional email: subject, greeting, lines,
 * one action button, closing lines. Everything is escaped.
 *
 * @api
 */
final class MailMessage
{
    private string $greeting = '';
    /** @var list<string> */
    private array $intro = [];
    /** @var list<string> */
    private array $outro = [];
    private ?array $action = null;
    private ?string $html = null;

    private function __construct(public readonly string $subject) {}

    public static function make(string $subject): self
    {
        return new self($subject);
    }

    public function greeting(string $text): self
    {
        $this->greeting = $text;

        return $this;
    }

    /** A paragraph (before the action button, or after it once an action is set). */
    public function line(string $text): self
    {
        $this->action === null ? $this->intro[] = $text : $this->outro[] = $text;

        return $this;
    }

    public function action(string $label, string $url): self
    {
        if (!Html::safeUrl($url, ['http', 'https'])) {
            throw new \InvalidArgumentException('Action URLs must be http(s).');
        }
        $this->action = ['label' => $label, 'url' => $url];

        return $this;
    }

    /** Full custom HTML body (trusted: the notification's own code). */
    public function html(string $html): self
    {
        $this->html = $html;

        return $this;
    }

    public function render(string $siteName = 'Kestello'): string
    {
        if ($this->html !== null) {
            return $this->html;
        }
        $e = static fn(string $s): string => Html::escape($s);
        $p = static fn(string $s): string => '<p style="margin:0 0 16px;line-height:1.6">' . nl2br($e($s)) . '</p>';
        $body = ($this->greeting !== '' ? '<h1 style="font-size:20px;margin:0 0 16px;color:#111827">' . $e($this->greeting) . '</h1>' : '')
            . implode('', array_map($p, $this->intro));
        if ($this->action !== null) {
            $body .= '<p style="margin:24px 0"><a href="' . $e($this->action['url']) . '" style="display:inline-block;background:#111827;color:#ffffff;text-decoration:none;padding:12px 20px;border-radius:8px;font-weight:600">'
                . $e($this->action['label']) . '</a></p>';
        }
        $body .= implode('', array_map($p, $this->outro));
        if ($this->action !== null) {
            $body .= '<p style="margin:24px 0 0;font-size:12px;color:#64748b">' . $e(Translator::get('If the button does not work, copy this link into your browser:')) . '<br>' . $e($this->action['url']) . '</p>';
        }

        return '<!DOCTYPE html><html lang="' . $e(str_replace('_', '-', Translator::locale())) . '" dir="' . Translator::direction() . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>' . $e($this->subject) . '</title></head>'
            . '<body style="margin:0;padding:24px;background:#f8f7f3;font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:#111827">'
            . '<div style="max-width:560px;margin:0 auto;background:#ffffff;border-radius:14px;padding:32px">' . $body . '</div>'
            . '<p style="max-width:560px;margin:16px auto 0;font-size:12px;color:#64748b;text-align:center">' . $e($siteName) . '</p></body></html>';
    }
}
