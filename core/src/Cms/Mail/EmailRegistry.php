<?php

declare(strict_types=1);

namespace Kestello\Cms\Mail;

use Closure;
use InvalidArgumentException;
use Kestello\Cms\Auth\Roles;
use Kestello\Cms\Cms;
use Kestello\Cms\Events\MailSending;
use Kestello\Cms\Events\MailSent;
use Kestello\Cms\Notifications\MailMessage;
use Kestello\Framework\Core\ExecutionContext;

/**
 * Every email the site can send, by name ($cms->emails), so each one can
 * be listed, previewed with sample values and changed in one place.
 *
 *   $cms->emails->register('shop.order_shipped', 'Order shipped',
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
 * them. A plugin's ids start with its slug and a dot.
 *
 * Sending runs, in order: build, the filter mail.message, the event
 * MailSending (which can stop it), the filter mail.branding, the layout,
 * the transport, and MailSent once the server accepted it.
 *
 * @api
 */
final class EmailRegistry
{
    /** @var array<string, array{id: string, label: string, group: string, description: string, owner: string, sample: array<string, mixed>, locked: list<string>, build: Closure(array<string, mixed>): MailMessage}> */
    private array $emails = [];

    /** Core's own emails are always there (CoreEmails). */
    public function __construct()
    {
        CoreEmails::register($this);
    }

    /**
     * @param Closure(array<string, mixed>): MailMessage $build
     * @param array<string, mixed> $sample values for a preview, with the same keys senders pass
     * @param list<string> $locked names of blocks every message must have
     */
    public function register(string $id, string $label, Closure $build, array $sample, string $group = 'Other', string $description = '', array $locked = []): void
    {
        $owner = ExecutionContext::current();
        if (preg_match('/^[a-z][a-z0-9_-]*\.[a-z0-9_.-]+$/', $id) !== 1) {
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
     * Build one email from the sender's values, through the filter
     * mail.message. Its locked blocks must be there.
     *
     * @param array<string, mixed> $data
     */
    public function message(string $id, array $data, string $to = ''): MailMessage
    {
        $email = $this->get($id);
        $message = ($email['build'])($data);
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

    /** The email with its sample values, for a preview. */
    public function sample(string $id): MailMessage
    {
        return $this->message($id, $this->get($id)['sample']);
    }

    /**
     * Build and render a named email for one address. Null when a
     * MailSending listener stopped it.
     *
     * @param array<string, mixed> $data
     */
    public function prepare(string $id, string $to, array $data, MailBrand|string|null $brand = null): ?PreparedEmail
    {
        return $this->prepareMessage($id, $to, $this->message($id, $data, $to), $brand);
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

        return new PreparedEmail($id, $to, $message->subject, $message->render($brand), $message->text($brand));
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
    public function send(string $id, string $to, array $data, Closure $transport, MailBrand|string|null $brand = null): bool
    {
        $email = $this->prepare($id, $to, $data, $brand);

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
