<?php

declare(strict_types=1);

namespace Kestello\Cms\Mail;

use Closure;
use Kestello\Cms\Notifications\MailMessage;
use Kestello\Support\Translator;

/**
 * An edit of a named email (Settings → Emails): a subject and blocks, saved
 * per language by EmailEdits. This class only checks an edit against the
 * email it changes and builds the edited message; it reads nothing.
 *
 *   ['subject' => 'Welcome to {site}',      '' keeps the email's own
 *    'blocks' => [
 *       ['type' => 'heading',   'text' => 'Hi {name},'],
 *       ['type' => 'paragraph', 'text' => '…'],              also 'note'
 *       ['type' => 'button',    'label' => 'Our shop', 'url' => 'https://…'],
 *       ['type' => 'image',     'media' => 12, 'alt' => '…'],
 *       ['type' => 'divider'],
 *       ['type' => 'original',  'name' => 'link', 'label' => 'Choose a password'],
 *    ]]
 *
 * "original" keeps one of the email's named blocks as its code builds it,
 * optionally with new words (text for a heading, paragraph or note; label
 * for a button). Its locked blocks must each be kept once. Text may use
 * the email's values as {name} (placeholders()).
 *
 * @internal
 */
final class EmailEdit
{
    public const array TYPES = ['heading', 'paragraph', 'note', 'button', 'image', 'divider', 'original'];
    public const int MAX_BLOCKS = 40;
    public const array LIMITS = ['subject' => 200, 'text' => 5000, 'label' => 80, 'alt' => 200];
    /** Block types whose words an edit writes itself. */
    private const array TEXT = ['heading', 'paragraph', 'note'];

    /**
     * The values an edit can write as {name}: those that are text or numbers.
     *
     * @param array<string, mixed> $values
     * @return list<string>
     */
    public static function placeholders(array $values): array
    {
        return array_values(array_filter(array_map('strval', array_keys($values)), static fn(string $k): bool => is_scalar($values[$k])));
    }

    /**
     * {name} replaced by its value; a name without a value stays as written.
     *
     * @param array<string, mixed> $values
     */
    public static function fill(string $text, array $values): string
    {
        return (string) preg_replace_callback(
            '/\{([a-z_][a-z0-9_]*)\}/',
            static fn(array $m): string => array_key_exists($m[1], $values) && is_scalar($values[$m[1]]) ? (string) $values[$m[1]] : $m[0],
            $text,
        );
    }

    /**
     * What is wrong with an edit, by field ("subject", "blocks", "blocks.2.url"),
     * checked against the email built with its sample values. Empty when it
     * can be saved.
     *
     * @param array<string, mixed> $edit
     * @param list<string> $locked
     * @param array<string, mixed> $values the email's sample values
     * @param Closure(int): (array{url: string, width: int, height: int}|null) $media
     * @return array<string, list<string>>
     */
    public static function validate(array $edit, MailMessage $original, array $locked, array $values, Closure $media): array
    {
        $errors = [];
        $known = self::placeholders($values);
        $text = static function (string $field, mixed $value, int $max, bool $required) use (&$errors, $known): void {
            if (!is_string($value) || ($required && trim($value) === '')) {
                $errors[$field][] = $required ? Translator::get('Write something here, or remove the block.') : Translator::get('This must be text.');
            } elseif (mb_strlen($value) > $max) {
                $errors[$field][] = Translator::get('Keep it under {max} characters.', ['max' => $max]);
            } elseif (($unknown = self::unknown($value, $known)) !== []) {
                $errors[$field][] = $known === []
                    ? Translator::get('This email has no value called {name}. It has none.', ['name' => '{' . $unknown[0] . '}'])
                    : Translator::get('This email has no value called {name}. It has: {known}.', ['name' => '{' . $unknown[0] . '}', 'known' => '{' . implode('}, {', $known) . '}']);
            }
        };

        $text('subject', $edit['subject'] ?? '', self::LIMITS['subject'], false);
        $blocks = $edit['blocks'] ?? null;
        if (!is_array($blocks) || !array_is_list($blocks) || $blocks === []) {
            return $errors + ['blocks' => [Translator::get('An email needs at least one block.')]];
        }
        if (count($blocks) > self::MAX_BLOCKS) {
            $errors['blocks'][] = Translator::get('An email can have up to {max} blocks.', ['max' => self::MAX_BLOCKS]);
        }

        $kept = [];
        foreach ($blocks as $i => $block) {
            $at = "blocks.{$i}";
            $type = is_array($block) ? ($block['type'] ?? null) : null;
            if (!is_array($block) || !in_array($type, self::TYPES, true)) {
                $errors["{$at}.type"][] = Translator::get('A block is one of: {types}.', ['types' => implode(', ', self::TYPES)]);
                continue;
            }
            if (in_array($type, self::TEXT, true)) {
                $text("{$at}.text", $block['text'] ?? null, self::LIMITS['text'], true);
            } elseif ($type === 'button') {
                $text("{$at}.label", $block['label'] ?? null, self::LIMITS['label'], true);
                if (!is_string($block['url'] ?? null) || !MailMessage::isLink(self::fill($block['url'], $values))) {
                    $errors["{$at}.url"][] = Translator::get('A button needs a whole address starting with https://, or one of the email’s links such as {example}.', ['example' => '{link}']);
                }
            } elseif ($type === 'image') {
                if (!is_int($block['media'] ?? null) || $media($block['media']) === null) {
                    $errors["{$at}.media"][] = Translator::get('Choose an image from the media library. It must still be there, and the site needs its address (Settings → General).');
                }
                $text("{$at}.alt", $block['alt'] ?? '', self::LIMITS['alt'], false);
            } elseif ($type === 'original') {
                $name = is_string($block['name'] ?? null) ? $block['name'] : '';
                $source = $name !== '' ? $original->block($name) : null;
                if ($source === null) {
                    $errors["{$at}.name"][] = Translator::get('This email has no block named “{name}”.', ['name' => $name]);
                    continue;
                }
                if (isset($kept[$name])) {
                    $errors["{$at}.name"][] = Translator::get('The block “{name}” is already in this email once.', ['name' => $name]);
                }
                $kept[$name] = true;
                foreach (['text' => in_array($source['type'], self::TEXT, true), 'label' => $source['type'] === 'button'] as $field => $allowed) {
                    if (!array_key_exists($field, $block)) {
                        continue;
                    }
                    if (!$allowed) {
                        $errors["{$at}.{$field}"][] = Translator::get('The words of the block “{name}” can’t be changed.', ['name' => $name]);
                    } else {
                        $text("{$at}.{$field}", $block[$field], self::LIMITS[$field], true);
                    }
                }
            }
        }
        foreach ($locked as $name) {
            if (!isset($kept[$name])) {
                $errors['blocks'][] = Translator::get('Keep the block “{name}”: the email doesn’t work without it.', ['name' => $name]);
            }
        }

        return $errors;
    }

    /**
     * The edited message, from the email as its code built it for this
     * send and the values it was built with. Blocks that can't be drawn
     * this time (a link that isn't one, an image that's gone) are left out.
     *
     * @param array<string, mixed> $edit a valid edit (validate())
     * @param array<string, mixed> $values
     * @param Closure(int): (array{url: string, width: int, height: int}|null) $media
     */
    public static function apply(array $edit, MailMessage $original, array $values, Closure $media): MailMessage
    {
        $subject = trim((string) ($edit['subject'] ?? ''));
        $message = MailMessage::make($subject !== '' ? self::fill($subject, $values) : $original->subject);
        foreach ((array) $edit['blocks'] as $block) {
            $fill = static fn(string $field): string => self::fill((string) ($block[$field] ?? ''), $values);
            match ($block['type']) {
                'heading' => $message->heading($fill('text')),
                'paragraph' => $message->paragraph($fill('text')),
                'note' => $message->note($fill('text')),
                'divider' => $message->divider(),
                'button' => MailMessage::isLink($fill('url')) ? $message->button($fill('label'), $fill('url')) : null,
                'image' => ($file = $media((int) $block['media'])) !== null ? $message->image($file['url'], $fill('alt'), $file['width'], $file['height']) : null,
                'original' => ($source = $original->block((string) $block['name'])) !== null
                    ? $message->keep(array_replace($source, array_map(static fn(mixed $v): string => self::fill((string) $v, $values), array_intersect_key($block, ['text' => 1, 'label' => 1]))))
                    : null,
                default => null,
            };
        }

        return $message;
    }

    /**
     * The values to build an email with for the editor to start from: text
     * becomes its {name}, so the words show where each value goes. Links
     * and numbers keep their sample (a button needs a link, a plural a number).
     *
     * @param array<string, mixed> $sample
     * @return array<string, mixed>
     */
    public static function templateValues(array $sample): array
    {
        $values = $sample;
        foreach ($sample as $key => $value) {
            if (is_string($value) && !MailMessage::isLink($value)) {
                $values[$key] = '{' . $key . '}';
            }
        }

        return $values;
    }

    /**
     * An edit that changes nothing: the email built with templateValues(),
     * its named blocks kept as they are and the others as words to change.
     * Unnamed images, rows and code can't be written as an edit, so they
     * are left out; name them in the email's code to keep them.
     *
     * @param array<string, mixed> $sample
     * @return array{subject: string, blocks: list<array<string, mixed>>}
     */
    public static function template(MailMessage $built, array $sample): array
    {
        $links = [];
        foreach ($sample as $key => $value) {
            if (is_string($value) && MailMessage::isLink($value)) {
                $links[$value] = '{' . $key . '}';
            }
        }
        uksort($links, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));   // the longest first, so one link inside another isn't split
        $words = static fn(string $text): string => strtr($text, $links);

        $blocks = [];
        foreach ($built->blocks() as $block) {
            $blocks[] = match (true) {
                isset($block['name']) => ['type' => 'original', 'name' => $block['name']],
                in_array($block['type'], self::TEXT, true) => ['type' => $block['type'], 'text' => $words($block['text'])],
                $block['type'] === 'button' => ['type' => 'button', 'label' => $words($block['label']), 'url' => $words($block['url'])],
                $block['type'] === 'divider' => ['type' => 'divider'],
                default => null,
            };
        }

        return ['subject' => $words($built->subject), 'blocks' => array_values(array_filter($blocks))];
    }

    /**
     * @param list<string> $known
     * @return list<string>
     */
    private static function unknown(string $text, array $known): array
    {
        preg_match_all('/\{([a-z_][a-z0-9_]*)\}/', $text, $m);

        return array_values(array_diff(array_unique($m[1]), $known));
    }
}
