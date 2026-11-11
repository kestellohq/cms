<?php

declare(strict_types=1);

namespace Kestello\Cms\Auth;

use Closure;
use Kestello\Cms\Events\UserRegistered;
use Kestello\Cms\Notifications\MailMessage;
use Kestello\Framework\Database\QueryBuilder;
use Kestello\Framework\Events\EventDispatcher;
use Kestello\Framework\Http\RateLimiter;
use Kestello\Support\Hash;
use Kestello\Support\Translator;
use Kestello\Support\Url;

/**
 * Email sign-up, in two steps:
 *
 * 1. request(): the visitor gives an address. A link to finish is emailed,
 *    and nothing else is stored than a pending row. The answer is the same
 *    whether or not the address has an account; an existing owner gets an
 *    email saying so instead.
 * 2. complete(): whoever opened the link chooses the password, and only
 *    then is the account created. Asking for the password after the link,
 *    never before, means nobody can set up an account for someone else's
 *    address with a password they know.
 *
 * Tokens are 256-bit random, stored as SHA-256, single-use and valid for a
 * day; a new request for an address replaces its older link. Who may sign
 * up, and with which role, is AccountPolicy's and NewAccounts' call.
 */
final class Registrations
{
    public const string TABLE = 'pending_registrations';
    public const int TTL = 86400;
    public const string SENT = 'Check your inbox: we sent a link to finish creating your account. It can take a minute to arrive.';

    /**
     * @param Closure(string $to, string $subject, string $html, string $text): bool $mail
     */
    public function __construct(
        private readonly RateLimiter $limiter,
        private readonly NewAccounts $accounts,
        private readonly PasswordPolicy $policy,
        private readonly Closure $mail,
        private readonly string $baseUrl,
        private readonly string $siteName,
        private readonly ?EventDispatcher $events = null,
    ) {}

    /** Whether the site takes sign-ups at all. */
    public function isOpen(): bool
    {
        return $this->accounts->policy()->isOpen();
    }

    /**
     * The domains sign-up is limited to, or [] when any address may sign up.
     *
     * @return list<string>
     */
    public function domains(): array
    {
        $policy = $this->accounts->policy();

        return $policy->registration === 'domains' ? $policy->domains : [];
    }

    /**
     * Email a link to finish signing up. The errors are about the address
     * itself (invalid, or not accepted here), never about whether it has an
     * account; an empty answer means "check your inbox".
     *
     * @return array<string, list<string>> errors by field (email)
     */
    public function request(string $email, string $ip): array
    {
        $email = Emails::normalize($email);
        if (!Emails::isValid($email)) {
            return ['email' => ['Enter a valid email address.']];
        }
        if (!$this->accounts->mayRegister($email)) {
            $domains = $this->domains();

            return ['email' => [$domains !== [] && $this->isOpen()
                ? 'Use an address at ' . implode(', ', $domains) . '.'
                : 'This address can\'t be used to create an account here.']];
        }

        // Per IP; per address from one IP (3 an hour); per address in all (10 an hour, to spare
        // the mailbox). Over a limit the visitor sees the usual answer, and no email goes out.
        $address = hash('sha256', $email);
        if (
            !$this->limiter->hit("auth|register|ip|{$ip}", 10, 3600)->allowed
            || !$this->limiter->hit("auth|register|email-ip|{$address}|{$ip}", 3, 3600)->allowed
            || !$this->limiter->hit("auth|register|email|{$address}", 10, 3600)->allowed
        ) {
            return [];
        }

        $user = QueryBuilder::table('users')->where('email', $email)->first();
        if ($user !== null) {
            if ((bool) ($user['is_active'] ?? true)) {
                $this->send($email, $this->existingMessage($user), 'existing account');
            }

            return [];
        }

        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        QueryBuilder::table(self::TABLE)->where('email', $email)->delete();
        QueryBuilder::table(self::TABLE)->insert([
            'email' => $email,
            'token_hash' => hash('sha256', $token),
            'ip' => $ip,
            'created_at' => time(),
            'expires_at' => time() + self::TTL,
        ]);

        $link = Url::withQuery($this->url('/register/complete'), ['token' => $token]);
        $this->send($email, $this->linkMessage($link), 'sign-up link');

        return [];
    }

    /**
     * The address a token was sent to, when the token is valid.
     */
    public function find(string $token): ?string
    {
        if (preg_match('/^[A-Za-z0-9_-]{43}$/', $token) !== 1) {
            return null;
        }

        $hash = hash('sha256', $token);
        $row = QueryBuilder::table(self::TABLE)->where('token_hash', $hash)->first();
        if ($row === null || !hash_equals((string) $row['token_hash'], $hash) || (int) $row['expires_at'] < time()) {
            return null;
        }

        return (string) $row['email'];
    }

    /**
     * Create the account with the chosen password.
     *
     * @return array{errors: array<string, list<string>>, user: array<string, mixed>|null} errors by field (token, password), or the new user
     */
    public function complete(string $token, string $password, string $ip): array
    {
        $fail = static fn(string $field, string ...$messages): array => ['errors' => [$field => array_values($messages)], 'user' => null];
        $expired = 'This link is invalid or has expired. Sign up again to get a new one.';

        if (!$this->limiter->hit("auth|register-use|ip|{$ip}", 20, 3600)->allowed) {
            return $fail('token', 'Too many attempts. Try again later.');
        }

        $email = $this->find($token);
        if ($email === null) {
            return $fail('token', $expired);
        }
        // The policy may have changed since the link was sent.
        if (!$this->accounts->mayRegister($email)) {
            return $fail('token', 'This site no longer accepts sign-ups for this address.');
        }

        $errors = $this->policy->errors($password, ['email' => $email, 'username' => (string) strstr($email, '@', true)]);
        if ($errors !== []) {
            return $fail('password', ...$errors);
        }

        // Single use: whoever deletes the row first wins.
        if (QueryBuilder::table(self::TABLE)->where('token_hash', hash('sha256', $token))->delete() !== 1) {
            return $fail('token', $expired);
        }

        $taken = 'This address already has an account. Sign in, or reset your password if you forgot it.';
        if (QueryBuilder::table('users')->where('email', $email)->exists()) {
            return $fail('token', $taken);
        }
        try {
            $user = $this->accounts->create($email, Hash::password($password));
        } catch (\PDOException) {
            return $fail('token', $taken); // created a moment ago by another sign-in (the unique index)
        }

        $this->events?->dispatch(new UserRegistered((int) $user['id'], 'email'));

        return ['errors' => [], 'user' => $user];
    }

    private function url(string $path): string
    {
        return rtrim($this->baseUrl, '/') . $path;
    }

    private function send(string $to, MailMessage $message, string $what): void
    {
        AccountMail::send($this->mail, $to, $message, $this->siteName, "the {$what} email to " . Emails::mask($to) . ' could not be sent');
    }

    /** The sign-up link, for an address without an account. */
    private function linkMessage(string $link): MailMessage
    {
        $site = ['site' => $this->siteName];

        return MailMessage::make(Translator::get('Finish creating your {site} account', $site))
            ->greeting(Translator::get('Hello,'))
            ->line(Translator::get('Someone asked to create a {site} account with this email address. If it was you, choose your password with the button below.', $site))
            ->action(Translator::get('Finish creating my account'), $link)
            ->line(Translator::get('{hours, plural, one {The link works once and expires in # hour.} other {The link works once and expires in # hours.}}', ['hours' => intdiv(self::TTL, 3600)]))
            ->line(Translator::get('If you didn’t ask for this, ignore this email: no account is created.'));
    }

    /**
     * The answer to a sign-up for an address that already has an account.
     *
     * @param array<string, mixed> $user
     */
    private function existingMessage(array $user): MailMessage
    {
        $site = ['site' => $this->siteName];

        return MailMessage::make(Translator::get('You already have a {site} account', $site))
            ->greeting(Translator::get('Hi {name},', ['name' => (string) ($user['username'] ?? '')]))
            ->line(Translator::get('Someone tried to create a {site} account with this email address, but you already have one.', $site))
            ->action(Translator::get('Sign in'), $this->url('/login'))
            ->line(Translator::get('If you forgot your password, reset it here: {url}', ['url' => $this->url('/forgot-password')]))
            ->line(Translator::get('If it wasn’t you, ignore this email: nothing about your account changed.'));
    }
}
