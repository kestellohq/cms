<?php

declare(strict_types=1);

namespace Kestello\Cms\Forms;

use Kestello\Cms\Cms;
use Kestello\Cms\Notifications\MailMessage;
use Kestello\Contracts\Queue\Job;
use Kestello\Framework\Settings\Settings;
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
            $message = self::message(str_replace(["\r", "\n"], ' ', $subject), $form, $data, $site, $base, $this->submissionId, $this->page);
            if ($mail($n['to'], $message->subject, $message->render($site), $headers, $message->text($site)) === false) {
                throw new \RuntimeException('The mail server did not accept the form notification.');
            }
        }
    }

    /**
     * The email: who sent which form, the answers as rows, and a button to
     * the submission in the admin when the site's address is known.
     *
     * @param array<string, mixed> $form
     * @param array<string, mixed> $data
     */
    public static function message(string $subject, array $form, array $data, string $site, string $base, ?int $id, string $page): MailMessage
    {
        $rows = [];
        foreach ($form['definition']['fields'] as $f) {
            if (!array_key_exists($f['name'], $data)) {
                continue;
            }
            $v = $data[$f['name']];
            $rows[] = ['label' => (string) $f['label'], 'value' => match (true) {
                $f['type'] === 'file' => Translator::get('{files} (download them in the admin)', ['files' => implode(', ', array_map(static fn($x) => (string) ($x['name'] ?? ''), is_array($v) ? $v : []))]),
                $f['type'] === 'boolean' => $v ? Translator::get('Yes') : Translator::get('No'),
                is_array($v) => implode(', ', array_map(static fn($x) => (string) ($f['options']['choices'][$x] ?? $x), $v)),
                $f['type'] === 'select' && $v !== null => (string) ($f['options']['choices'][$v] ?? $v),
                default => (string) ($v ?? ''),
            }];
        }
        $title = (string) $form['title'];
        $message = MailMessage::make($subject)
            ->heading(Translator::get('New submission: {form}', ['form' => $title]))
            ->paragraph($page !== ''
                ? Translator::get('Someone sent {form} on {site}, from {page}.', ['form' => $title, 'site' => $site, 'page' => $page])
                : Translator::get('Someone sent {form} on {site}.', ['form' => $title, 'site' => $site]))
            ->rows($rows);
        $url = $base . '/admin/forms/' . $form['id'] . '/submissions?open=' . $id;
        if ($id !== null && MailMessage::isLink($url)) {
            $message->button(Translator::get('Open it in the admin'), $url);
        }

        return $message;
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
