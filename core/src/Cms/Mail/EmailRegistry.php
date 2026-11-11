<?php

declare(strict_types=1);

namespace Kestello\Cms\Mail;

use Closure;
use InvalidArgumentException;
use Kestello\Cms\Auth\Roles;
use Kestello\Cms\Cms;
use Kestello\Cms\Events\MailSending;
use Kestello\Cms\Events\MailSent;
use Kestello\Cms\I18n\PersonLanguage;
use Kestello\Cms\Notifications\MailMessage;
use Kestello\Framework\Core\ExecutionContext;
use Kestello\Support\Translator;

/**
 * Every email the site can send, by name ($cms->emails), so each one can
 * be listed, previewed with sample values and changed in one place.
 *
 *   $cms->emails->register('shop.order_shipped', label: 'Order shipped',
 *       build: fn(array $d) => MailMessage::make("Order {$d['order']} is on its way")
 *           ->paragraph('It left today.')
 *           ->button('Track it', $d['url'])->name('link'),
 *       sample: ['order' => '1042', 'url' => 'https://example.com/t/1042'],
 *       group: 'Shop',
 *       locked: ['link'],
 *   );
 *   $cms->emails->send('shop.order_shipped', $to, $data, $transport);
 *
 * build gets the values the sender passes, and the sample values for a
 * preview, so both must have the same keys. locked names the blocks that
 * make the email work (its link, a code): they must be in every message it
 * builds, and editing (Settings → Emails) can reword them but not remove
 * them. A plugin's ids start with its slug and a dot. Pass label: and
 * description: by name, so bin/cms i18n:extract finds them.
 *
 * Sending runs, in order: build, the edit saved in Settings → Emails for
 * the reader's language (EmailEdit), the filter mail.message, the event
 * MailSending (which can stop it), the filter mail.branding, the layout,
 * the transport, and MailSent once the server accepted it.
 *
 * @api
 */
final class EmailRegistry
{
    /** An email id: a lowercase group, a dot and a name ("shop.order_shipped"); it fits email_edits.email. */
    public const string ID = '[a-z][a-z0-9_-]*\.[a-z0-9_.-]{1,100}';

    /** @var array<string, array{id: string, label: string, group: string, description: string, owner: string, sample: array<string, mixed>, locked: list<string>, build: Closure(array<string, mixed>): MailMessage}> */
    private array $emails = [];

    /** Core's own emails are always there (CoreEmails). */
    public function __construct(private ?EmailEdits $edits = null)
    {
        CoreEmails::register($this);
    }

    /** The saved edits (Settings → Emails). */
    public function edits(): EmailEdits
    {
        return $this->edits ??= new EmailEdits();
    }

    /**
     * @param Closure(array<string, mixed>): MailMessage $build
     * @param array<string, mixed> $sample values for a preview, with the same keys senders pass
     * @param list<string> $locked names of blocks every message must have
     */
    public function register(string $id, string $label, Closure $build, array $sample, string $group = 'Other', string $description = '', array $locked = []): void
    {
        $owner = ExecutionContext::current();
        if (preg_match('/^' . self::ID . '$/', $id) !== 1 || strlen($id) > 120) {
            throw new InvalidArgumentException("An email id is a lowercase group and name, such as \"shop.order_shipped\", got \"{$id}\".");
        }
        Roles::assertOwnedName($id, $owner, '.');
        if (isset($this->emails[$id]) && $this->emails[$id]['owner'] !== $owner) {
            throw new InvalidArgumentException("The email \"{$id}\" is already registered by {$this->emails[$id]['owner']}.");
        }
        $this->emails[$id] = [
            'id' => $id,
            'label' => $label,
            'group' => $group,
            'description' => $description,
            'owner' => $owner,
            'sample' => $sample,
            'locked' => array_values(array_unique($locked)),
            'build' => $build,
        ];
    }

    public function has(string $id): bool
    {
        return isset($this->emails[$id]);
    }

    /** @return array{id: string, label: string, group: string, description: string, owner: string, sample: array<string, mixed>, locked: list<string>, build: Closure(array<string, mixed>): MailMessage} */
    public function get(string $id): array
    {
        return $this->emails[$id] ?? throw new InvalidArgumentException("No email is registered as \"{$id}\"." . self::suggest($id, array_keys($this->emails)));
    }

    /** @return array<string, array{id: string, label: string, group: string, description: string, owner: string, sample: array<string, mixed>, locked: list<string>, build: Closure(array<string, mixed>): MailMessage}> by id, in the order registered */
    public function all(): array
    {
        return $this->emails;
    }

    /**
     * One email as its code builds it from these values, with no edit and
     * no filter: what an edit starts from and keeps blocks of.
     *
     * @param array<string, mixed> $data
     */
    public function original(string $id, array $data): MailMessage
    {
        return ($this->get($id)['build'])($data);
    }

    /**
     * Build one email from the sender's values, with the edit saved for the
     * current language (or $edit, a draft being previewed), through the
     * filter mail.message. Its locked blocks must be there. A saved edit
     * that no longer fits the email is logged and left out, so the email
     * still goes out.
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed>|null $edit
     */
    public function message(string $id, array $data, string $to = '', ?array $edit = null): MailMessage
    {
        $email = $this->get($id);
        $message = $this->edited($id, $this->original($id, $data), $data, $edit);
        $filtered = Cms::instance()?->filters->apply('mail.message', $message, $id, $to, $data) ?? $message;
        if (!$filtered instanceof MailMessage) {
            error_log("[kestello] mail.message for \"{$id}\" did not return a MailMessage; the email is sent as built.");
            $filtered = $message;
        }
        foreach ($email['locked'] as $name) {
            if ($filtered->block($name) === null) {
                throw new \LogicException("The email \"{$id}\" must have a block named \"{$name}\" (it is locked), and this one has none.");
            }
        }

        return $filtered;
    }

    /**
     * The email with its sample values, for a preview: with the edit saved
     * for the current language, or with $edit, a draft.
     *
     * @param array<string, mixed>|null $edit
     */
    public function sample(string $id, ?array $edit = null): MailMessage
    {
        return $this->message($id, $this->get($id)['sample'], '', $edit);
    }

    /**
     * The edit that changes nothing, for the editor to start from, in the
     * current language (EmailEdit::template()). Null for an email whose code
     * writes its own HTML, which can't be edited block by block.
     *
     * @return array{subject: string, blocks: list<array<string, mixed>>}|null
     */
    public function template(string $id): ?array
    {
        $sample = $this->get($id)['sample'];
        $built = $this->original($id, EmailEdit::templateValues($sample));

        return $built->custom() ? null : EmailEdit::template($built, $sample);
    }

    /**
     * What is wrong with an edit of this email, by field; empty when it can
     * be saved (EmailEdit::validate(), against the sample values).
     *
     * @param array<string, mixed> $edit
     * @return array<string, list<string>>
     */
    public function check(string $id, array $edit): array
    {
        $email = $this->get($id);
        $original = $this->original($id, $email['sample']);
        if ($original->custom()) {
            return ['blocks' => [Translator::get('This email is written in HTML by its code and can’t be edited here.')]];
        }

        return EmailEdit::validate($edit, $original, $email['locked'], $email['sample'], self::media(...));
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed>|null $edit
     */
    private function edited(string $id, MailMessage $original, array $data, ?array $edit): MailMessage
    {
        if ($original->custom() || ($edit === null && Cms::instance() === null)) {
            return $original;
        }
        $edit ??= $this->edits()->find($id, Translator::locale());
        if ($edit === null) {
            return $original;
        }
        $errors = EmailEdit::validate($edit, $original, $this->get($id)['locked'], $data, self::media(...));
        if ($errors !== []) {
            $first = array_key_first($errors);
            error_log("[kestello] the edit of \"{$id}\" (" . Translator::locale() . ") no longer fits the email ({$first}: {$errors[$first][0]}); it is sent as its code writes it.");

            return $original;
        }

        return EmailEdit::apply($edit, $original, $data, self::media(...));
    }

    /** @return array{url: string, width: int, height: int}|null */
    private static function media(int $id): ?array
    {
        return MailMedia::image($id);
    }

    /**
     * Build and render a named email for one address, in its reader's
     * language: $locale when given, else the language of the account with
     * that address (PersonLanguage), else the current one (the page's, or
     * the site's). Null when a MailSending listener stopped it.
     *
     * @param array<string, mixed> $data
     */
    public function prepare(string $id, string $to, array $data, MailBrand|string|null $brand = null, ?string $locale = null): ?PreparedEmail
    {
        $build = fn(): ?PreparedEmail => $this->prepareMessage($id, $to, $this->message($id, $data, $to), $brand);
        $locale ??= PersonLanguage::forAddress($to);

        return $locale !== null && $locale !== '' ? Translator::withLocale($locale, $build) : $build();
    }

    /**
     * The same for a message built elsewhere, such as a notification's
     * ("notification.<type>"): the event, the brand and the layout.
     */
    public function prepareMessage(string $id, string $to, MailMessage $message, MailBrand|string|null $brand = null): ?PreparedEmail
    {
        $cms = Cms::instance();
        $event = new MailSending($id, $to, $message);
        $cms?->events->dispatch($event);
        if ($event->cancelled()) {
            error_log("[kestello] the email \"{$id}\" was stopped: {$event->reason()}");

            return null;
        }
        $brand = $brand instanceof MailBrand ? $brand : MailBrand::site($brand);
        $branded = $cms?->filters->apply('mail.branding', $brand, $id, $to) ?? $brand;
        $brand = $branded instanceof MailBrand ? $branded : $brand;
        $message = $event->message;

        return new PreparedEmail($id, $to, $message->subject, $message->render($brand, $id), $message->text($brand));
    }

    /**
     * Hand a prepared email to a transport: fn(to, subject, html, text): bool.
     * MailSent follows when it returns true.
     *
     * @param Closure(string, string, string, string): bool $transport
     */
    public function deliver(PreparedEmail $email, Closure $transport): bool
    {
        if (!$transport($email->to, $email->subject, $email->html, $email->text)) {
            return false;
        }
        Cms::instance()?->events->dispatch(new MailSent($email->id, $email->to, $email->subject));

        return true;
    }

    /**
     * prepare() and deliver() together. True when the server took it or a
     * listener stopped it on purpose; false only when sending failed.
     *
     * @param array<string, mixed> $data
     * @param Closure(string, string, string, string): bool $transport
     */
    public function send(string $id, string $to, array $data, Closure $transport, MailBrand|string|null $brand = null, ?string $locale = null): bool
    {
        $email = $this->prepare($id, $to, $data, $brand, $locale);

        return $email === null || $this->deliver($email, $transport);
    }

    /** @param list<string> $known */
    private static function suggest(string $id, array $known): string
    {
        $best = null;
        foreach ($known as $k) {
            $d = levenshtein($id, $k);
            if ($d <= 3 && ($best === null || $d < $best[1])) {
                $best = [$k, $d];
            }
        }

        return $best !== null ? " Did you mean \"{$best[0]}\"?" : '';
    }
}
