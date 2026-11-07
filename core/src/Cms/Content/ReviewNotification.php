<?php

declare(strict_types=1);

namespace Kestello\Cms\Content;

use Kestello\Cms\Notifications\MailMessage;
use Kestello\Cms\Notifications\Notification;
use Kestello\Cms\Notifications\Recipient;
use Kestello\Support\Translator;

/**
 * Review steps on an entry: it was submitted to you, a reviewer decided on
 * yours, or it was assigned to you. In the inbox, and by email unless the
 * person turned that off.
 */
class ReviewNotification extends Notification
{
    /**
     * @param string $kind submitted, approved, changes_requested, assigned or comment
     * @param string $editUrl admin path of the entry ("/admin/content/post/12")
     */
    public function __construct(
        public readonly string $kind,
        public readonly int $entryId,
        public readonly string $singular,
        public readonly string $title,
        public readonly string $actor,
        public readonly ?string $note,
        public readonly string $editUrl,
        public readonly string $siteUrl,
    ) {}

    public function type(): string
    {
        return 'content-review';
    }
    public function via(Recipient $to): array
    {
        return ['database', 'mail'];
    }
    public function queue(): ?string
    {
        return 'mail';
    }

    public function headline(): string
    {
        $vars = ['who' => $this->actor, 'item' => mb_strtolower($this->singular), 'title' => $this->title !== '' ? $this->title : Translator::get('(untitled)')];

        return match ($this->kind) {
            'submitted' => Translator::get('{who} submitted the {item} "{title}" for review', $vars),
            'approved' => Translator::get('{who} approved your {item} "{title}"', $vars),
            'changes_requested' => Translator::get('{who} asked for changes to your {item} "{title}"', $vars),
            'assigned' => Translator::get('{who} assigned the {item} "{title}" to you', $vars),
            default => Translator::get('{who} commented on the {item} "{title}"', $vars),
        };
    }

    public function toDatabase(Recipient $to): array
    {
        return ['title' => $this->headline(), 'body' => $this->note === null ? '' : mb_strimwidth($this->note, 0, 300, '…'), 'url' => $this->editUrl, 'icon' => $this->kind === 'comment' ? 'chat-circle' : 'check-circle'];
    }

    public function toMail(Recipient $to): MailMessage
    {
        $mail = MailMessage::make($this->headline())->greeting($this->headline());
        if ($this->note !== null && $this->note !== '') {
            foreach (preg_split('/\R{2,}/', $this->note) ?: [] as $paragraph) {
                $mail->line($paragraph);
            }
        }
        if (str_starts_with($this->siteUrl, 'https://') || str_starts_with($this->siteUrl, 'http://')) {
            $mail->action(Translator::get('Open it in the editor'), rtrim($this->siteUrl, '/') . $this->editUrl);
        }

        return $mail;
    }
}
