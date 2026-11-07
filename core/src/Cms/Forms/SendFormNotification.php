<?php

declare(strict_types=1);

namespace Kestello\Cms\Forms;

use Kestello\Cms\Cms;
use Kestello\Contracts\Queue\Job;
use Kestello\Framework\Settings\Settings;
use Kestello\Support\Html;
use Kestello\Support\Translator;

/**
 * Email a submission to the form's notification addresses (queued, so the
 * visitor never waits on the mail server). Kept submissions are read back
 * by id, so the queue never holds personal data; $data carries the values
 * only for forms that keep nothing. Written in the site's language (the
 * worker boots with it, as a request does).
 */
final class SendFormNotification implements Job
{
    /** @param array<string, mixed>|null $data */
    public function __construct(
        public readonly int $formId,
        public readonly ?int $submissionId,
        public readonly ?array $data,
        public readonly string $page = '',
    ) {}

    public function handle(): void
    {
        $cms = Cms::instance() ?? throw new \RuntimeException('Not booted.');
        $forms = $cms->container->make(Forms::class);
        try {
            $form = $forms->find($this->formId);
            $data = $this->submissionId !== null ? $forms->submission($this->submissionId)['data'] : ($this->data ?? []);
        } catch (\OutOfBoundsException) {
            return;      // deleted since: nothing to send
        }
        $mail = $cms->container->make('forms.mail');
        $site = (string) Settings::getInstance()->get('site_name', 'Kestello');
        $base = rtrim((string) (Settings::getInstance()->get('site_url', '') ?: ''), '/');
        foreach ($form['definition']['notifications'] as $n) {
            $headers = [];
            $reply = $n['reply_to'] !== null ? (string) ($data[$n['reply_to']] ?? '') : '';
            if ($reply !== '' && filter_var($reply, FILTER_VALIDATE_EMAIL) !== false && !preg_match('/[\r\n]/', $reply)) {
                $headers['Reply-To'] = $reply;
            }
            $subject = $n['subject'] !== '' ? $n['subject'] : Translator::get('New submission: {form}', ['form' => (string) $form['title']]);
            // A refused message fails the job, so the queue retries it instead of losing it.
            if ($mail($n['to'], str_replace(["\r", "\n"], ' ', $subject), self::body($form, $data, $site, $base, $this->submissionId, $this->page), $headers) === false) {
                throw new \RuntimeException('The mail server did not accept the form notification.');
            }
        }
    }

    private static function body(array $form, array $data, string $site, string $base, ?int $id, string $page): string
    {
        $rows = '';
        foreach ($form['definition']['fields'] as $f) {
            if (!array_key_exists($f['name'], $data)) {
                continue;
            }
            $v = $data[$f['name']];
            $text = match (true) {
                $f['type'] === 'file' => Translator::get('{files} (download them in the admin)', ['files' => implode(', ', array_map(static fn($x) => (string) ($x['name'] ?? ''), is_array($v) ? $v : []))]),
                $f['type'] === 'boolean' => $v ? Translator::get('Yes') : Translator::get('No'),
                is_array($v) => implode(', ', array_map(static fn($x) => (string) ($f['options']['choices'][$x] ?? $x), $v)),
                $f['type'] === 'select' && $v !== null => (string) ($f['options']['choices'][$v] ?? $v),
                default => (string) ($v ?? ''),
            };
            $rows .= '<tr><th align="left" valign="top" style="padding:6px 12px 6px 0;color:#4a5468;font-weight:600">' . Html::escape($f['label'])
                . '</th><td style="padding:6px 0;white-space:pre-wrap">' . Html::escape($text) . '</td></tr>';
        }
        $link = $id !== null && $base !== ''
            ? '<p><a href="' . Html::escape($base . '/admin/forms/' . $form['id'] . '/submissions?open=' . $id) . '">' . Html::escape(Translator::get('Open it in the admin')) . '</a></p>'
            : '';
        // the form's name goes in bold: a mark stands in for it while the sentence is translated and escaped
        $mark = "\u{E000}";
        $intro = $page !== ''
            ? Translator::get('Someone sent {form} on {site}, from {page}.', ['form' => $mark, 'site' => $site, 'page' => $page])
            : Translator::get('Someone sent {form} on {site}.', ['form' => $mark, 'site' => $site]);
        $intro = str_replace($mark, '<strong>' . Html::escape((string) $form['title']) . '</strong>', Html::escape($intro));

        return '<div style="font-family:-apple-system,Segoe UI,Roboto,Arial,sans-serif;font-size:15px;color:#111827">'
            . '<p>' . $intro . '</p>'
            . '<table cellspacing="0" cellpadding="0">' . $rows . '</table>' . $link . '</div>';
    }

    public function retries(): int
    {
        return 3;
    }
    public function timeout(): int
    {
        return 60;
    }
    public function uniqueKey(): ?string
    {
        return $this->submissionId !== null ? 'form-mail:' . $this->submissionId : null;
    }
}
