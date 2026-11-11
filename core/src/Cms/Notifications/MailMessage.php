<?php

declare(strict_types=1);

namespace Kestello\Cms\Notifications;

use Kestello\Cms\Mail\MailBrand;
use Kestello\Cms\Mail\MailLayout;
use Kestello\Framework\Mail\PlainText;
use Kestello\Support\Html;

/**
 * An email, built from blocks in the order they're added, and rendered by
 * one layout into its HTML part (render) and plain-text part (text):
 *
 *   MailMessage::make('Your order is on its way')
 *       ->heading('Your order is on its way')
 *       ->paragraph('It left today.')
 *       ->rows(['Order' => '1042', 'Carrier' => 'Post'])
 *       ->button('Track it', $url)
 *       ->note('Questions? Reply to this email.');
 *
 * Every block's text is escaped. Button links must be http(s).
 * greeting(), line() and action() are the older names for heading(),
 * paragraph() and button(), and keep working.
 *
 * @api
 */
final class MailMessage
{
    /** @var list<array<string, mixed>> */
    private array $blocks = [];
    private ?string $html = null;

    private function __construct(public readonly string $subject) {}

    public static function make(string $subject): self
    {
        return new self($subject);
    }

    public function heading(string $text): self
    {
        return $this->add(['type' => 'heading', 'text' => $text]);
    }

    /** A paragraph; its line breaks are kept. */
    public function paragraph(string $text): self
    {
        return $this->add(['type' => 'paragraph', 'text' => $text]);
    }

    /** A button. The first button's address is also written out at the end. */
    public function button(string $label, string $url): self
    {
        if (!Html::safeUrl($url, ['http', 'https'])) {
            throw new \InvalidArgumentException("Email button links must be http(s), got \"{$url}\".");
        }

        return $this->add(['type' => 'button', 'label' => $label, 'url' => $url]);
    }

    /**
     * Labels and values, such as a form's answers. Rows with an empty label
     * are left out.
     *
     * @param array<string, string>|list<array{label: string, value: string}> $rows
     */
    public function rows(array $rows): self
    {
        $list = [];
        foreach ($rows as $key => $row) {
            [$label, $value] = is_array($row) ? [(string) ($row['label'] ?? ''), (string) ($row['value'] ?? '')] : [(string) $key, (string) $row];
            if (trim($label) !== '') {
                $list[] = ['label' => $label, 'value' => $value];
            }
        }

        return $list === [] ? $this : $this->add(['type' => 'rows', 'rows' => $list]);
    }

    /** A one-time code or a short command, set apart in a fixed-width font. */
    public function code(string $text): self
    {
        return $this->add(['type' => 'code', 'text' => $text]);
    }

    /** Small print, such as "If you didn't ask for this, ignore this email." */
    public function note(string $text): self
    {
        return $this->add(['type' => 'note', 'text' => $text]);
    }

    /** The older name for heading(). */
    public function greeting(string $text): self
    {
        return $this->heading($text);
    }

    /** The older name for paragraph(). */
    public function line(string $text): self
    {
        return $this->paragraph($text);
    }

    /** The older name for button(). */
    public function action(string $label, string $url): self
    {
        return $this->button($label, $url);
    }

    /**
     * Name the block just added, so a named email can lock it (the button
     * that carries its link) and a theme's template can place it by name.
     * Names are lowercase and unique within the message.
     */
    public function name(string $key): self
    {
        if (preg_match('/^[a-z][a-z0-9_]{0,40}$/', $key) !== 1) {
            throw new \InvalidArgumentException("An email block name is lowercase letters, digits and _, got \"{$key}\".");
        }
        if ($this->blocks === []) {
            throw new \LogicException("Add a block before naming it \"{$key}\".");
        }
        if ($this->block($key) !== null) {
            throw new \InvalidArgumentException("This email already has a block named \"{$key}\".");
        }
        $this->blocks[array_key_last($this->blocks)]['name'] = $key;

        return $this;
    }

    /** @return array<string, mixed>|null the block with that name */
    public function block(string $key): ?array
    {
        foreach ($this->blocks as $block) {
            if (($block['name'] ?? null) === $key) {
                return $block;
            }
        }

        return null;
    }

    /** Full custom HTML body (trusted: the notification's own code). The blocks are then ignored. */
    public function html(string $html): self
    {
        $this->html = $html;

        return $this;
    }

    /** @return list<array<string, mixed>> the blocks, in order */
    public function blocks(): array
    {
        return $this->blocks;
    }

    /** The HTML part. A string brand is the site's name with Kestello's colours. */
    public function render(MailBrand|string $brand = 'Kestello'): string
    {
        return $this->html ?? MailLayout::html($this->subject, $this->blocks, self::brand($brand));
    }

    /** The plain-text part: the same words in the same order, the site's name last. */
    public function text(MailBrand|string $brand = 'Kestello'): string
    {
        return $this->html !== null ? PlainText::fromHtml($this->html) : MailLayout::text($this->blocks, self::brand($brand));
    }

    /** Whether $url is a whole http(s) address, the kind a mail client can open without a page to resolve it against. */
    public static function isLink(string $url): bool
    {
        return preg_match('#^https?://[^/\s]#i', $url) === 1 && Html::safeUrl($url, ['http', 'https']);
    }

    /** @param array<string, mixed> $block */
    private function add(array $block): self
    {
        $this->blocks[] = $block;

        return $this;
    }

    private static function brand(MailBrand|string $brand): MailBrand
    {
        return $brand instanceof MailBrand ? $brand : MailBrand::named($brand);
    }
}
