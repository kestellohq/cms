<?php

declare(strict_types=1);

namespace Kestello\Cms\Auth;

use Closure;
use Kestello\Cms\Events\PasswordResetRequested;
use Kestello\Framework\Database\QueryBuilder;
use Kestello\Framework\Events\EventDispatcher;
use Kestello\Framework\Http\RateLimiter;
use Kestello\Support\Url;

/**
 * Password reset by emailed link.
 *
 * - request() behaves identically whether or not the account exists (no
 *   enumeration) and is rate limited per IP and per address.
 * - Tokens are 256-bit random, stored as SHA-256, single-use, valid for an
 *   hour; a new request replaces older links.
 * - reset() never logs the user in: an account with 2FA still has to pass it.
 */
final class PasswordResets
{
    public const string TABLE = 'password_resets';
    public const int TTL = 3600;

    /**
     * @param Closure(string $to, string $subject, string $html, string $text): bool $mail
     */
    public function __construct(
        private readonly RateLimiter $limiter,
        private readonly AccountSecurity $accounts,
        private readonly PasswordPolicy $policy,
        private readonly Closure $mail,
        private readonly string $baseUrl,
        private readonly string $siteName,
        private readonly ?EventDispatcher $events = null,
    ) {}

    /** Email a reset link if an active account has this address. */
    public function request(string $email, string $ip): void
    {
        $email = Emails::normalize($email);
        // Per IP; per address from one IP (3 an hour); per address in all (10 an hour, to
        // spare the mailbox). Someone asking for your address elsewhere doesn't silence yours.
        $address = hash('sha256', $email);
        if (
            !$this->limiter->hit("auth|reset|ip|{$ip}", 10, 3600)->allowed
            || !$this->limiter->hit("auth|reset|email-ip|{$address}|{$ip}", 3, 3600)->allowed
            || !$this->limiter->hit("auth|reset|email|{$address}", 10, 3600)->allowed
            || !Emails::isValid($email)
        ) {
            return;
        }

        $user = QueryBuilder::table('users')->where('email', $email)->first();
        if ($user === null || !(bool) ($user['is_active'] ?? true)) {
            return;
        }

        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        QueryBuilder::table(self::TABLE)->where('user_id', $user['id'])->delete();
        QueryBuilder::table(self::TABLE)->insert([
            'user_id' => $user['id'],
            'token_hash' => hash('sha256', $token),
            'ip' => $ip,
            'created_at' => time(),
            'expires_at' => time() + self::TTL,
        ]);

        $link = Url::withQuery(rtrim($this->baseUrl, '/') . '/reset-password', ['token' => $token]);
        AccountMail::send($this->mail, 'auth.password_reset', (string) $user['email'], ['site' => $this->siteName, 'name' => (string) ($user['username'] ?? ''), 'link' => $link, 'minutes' => intdiv(self::TTL, 60)], $this->siteName, 'password reset email to user #' . (int) $user['id'] . ' could not be sent');

        $this->events?->dispatch(new PasswordResetRequested((int) $user['id'], $ip));
    }

    /**
     * The account a token belongs to, when the token is valid.
     *
     * @return array<string, mixed>|null
     */
    public function find(#[\SensitiveParameter] string $token): ?array
    {
        if (preg_match('/^[A-Za-z0-9_-]{43}$/', $token) !== 1) {
            return null;
        }

        $hash = hash('sha256', $token);
        $row = QueryBuilder::table(self::TABLE)->where('token_hash', $hash)->first();
        if ($row === null || !hash_equals((string) $row['token_hash'], $hash) || (int) $row['expires_at'] < time()) {
            return null;
        }

        $user = QueryBuilder::table('users')->where('id', $row['user_id'])->first();

        return $user !== null && (bool) ($user['is_active'] ?? true) ? $user : null;
    }

    /**
     * Set a new password with a token.
     *
     * @return array<string, list<string>> errors by field (token, password); empty on success
     */
    public function reset(#[\SensitiveParameter] string $token, #[\SensitiveParameter] string $password, string $ip): array
    {
        if (!$this->limiter->hit("auth|reset-use|ip|{$ip}", 20, 3600)->allowed) {
            return ['token' => ['Too many attempts. Try again later.']];
        }

        $user = $this->find($token);
        if ($user === null) {
            return ['token' => ['This reset link is invalid or has expired. Request a new one.']];
        }

        $errors = $this->policy->errors($password, $user);
        if ($errors !== []) {
            return ['password' => $errors];
        }

        // Single use: whoever deletes the row first wins.
        if (QueryBuilder::table(self::TABLE)->where('token_hash', hash('sha256', $token))->delete() !== 1) {
            return ['token' => ['This reset link is invalid or has expired. Request a new one.']];
        }

        $this->accounts->changePassword((int) $user['id'], $password, 'reset');

        return [];
    }

    /** Delete expired tokens. */
    public function prune(): int
    {
        return QueryBuilder::table(self::TABLE)->where('expires_at', '<', time())->delete();
    }
}
