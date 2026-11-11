<?php

declare(strict_types=1);

namespace Kestello\Cms\Auth;

use Closure;
use Kestello\Cms\I18n\PersonLanguage;
use Kestello\Framework\Database\QueryBuilder;
use Kestello\Framework\Http\RateLimiter;
use Kestello\Support\Url;

/**
 * Changing your own email address, by emailed link.
 *
 * The account keeps its old address until a link sent to the new one is
 * opened, so nobody can put an address they don't own on their account (and,
 * with linking by verified email, receive its owner's sign-in later). The
 * old address is told a change was asked for.
 *
 * - Tokens are 256-bit random, stored as SHA-256, single-use, valid a day;
 *   a new request replaces the waiting one.
 * - Confirming ends any password reset links: they went to the old address.
 */
final class EmailChanges
{
    public const string TABLE = 'email_changes';
    public const int TTL = 86400;

    /**
     * @param Closure(string $to, string $subject, string $html, string $text): bool $mail
     */
    public function __construct(
        private readonly RateLimiter $limiter,
        private readonly Closure $mail,
        private readonly string $baseUrl,
        private readonly string $siteName,
    ) {}

    /**
     * Send a confirmation link to the new address.
     *
     * @param array<string, mixed> $user
     * @return string|null why it was not sent (too many requests, the address is in use), or null
     */
    public function request(array $user, string $email): ?string
    {
        $email = Emails::normalize($email);
        $id = (int) $user['id'];
        if (!Emails::isValid($email)) {
            return 'Enter a valid email address.';
        }
        if (!$this->limiter->hit("auth|email-change|user|{$id}", 5, 3600)->allowed) {
            return 'Too many changes asked for. Try again later.';
        }
        if (self::inUse($email, $id)) {
            return 'This email is already in use.';
        }

        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        QueryBuilder::table(self::TABLE)->where('user_id', $id)->delete();
        QueryBuilder::table(self::TABLE)->insert([
            'user_id' => $id,
            'email' => $email,
            'token_hash' => hash('sha256', $token),
            'created_at' => time(),
            'expires_at' => time() + self::TTL,
        ]);

        $link = Url::withQuery(rtrim($this->baseUrl, '/') . '/confirm-email', ['token' => $token]);
        // the new address isn't on the account yet, so its language comes from the account
        $this->send($email, 'auth.email_change_confirm', ['site' => $this->siteName, 'name' => (string) ($user['username'] ?? ''), 'link' => $link, 'hours' => intdiv(self::TTL, 3600)], $id, PersonLanguage::of($user));
        $this->send((string) $user['email'], 'auth.email_change_notice', ['site' => $this->siteName, 'name' => (string) ($user['username'] ?? ''), 'email' => $email], $id);

        return null;
    }

    /** The address waiting for confirmation, or null. */
    public function pending(int $userId): ?string
    {
        $row = QueryBuilder::table(self::TABLE)->where('user_id', $userId)->where('expires_at', '>=', time())->first();

        return $row === null ? null : (string) $row['email'];
    }

    /** Stop waiting: the change was cancelled, or an administrator set the address. */
    public function cancel(int $userId): void
    {
        QueryBuilder::table(self::TABLE)->where('user_id', $userId)->delete();
    }

    /**
     * Apply the change a token stands for.
     *
     * @return array{user_id: int, email: string}|string the change, or why it could not be made
     */
    public function confirm(string $token, string $ip): array|string
    {
        if (!$this->limiter->hit("auth|email-confirm|ip|{$ip}", 20, 3600)->allowed) {
            return 'Too many attempts. Try again later.';
        }
        $invalid = 'This link is invalid or has expired. Change your email again from your profile.';
        if (preg_match('/^[A-Za-z0-9_-]{43}$/', $token) !== 1) {
            return $invalid;
        }
        $hash = hash('sha256', $token);
        $row = QueryBuilder::table(self::TABLE)->where('token_hash', $hash)->first();
        if ($row === null || !hash_equals((string) $row['token_hash'], $hash) || (int) $row['expires_at'] < time()) {
            return $invalid;
        }
        // single use: whoever deletes the row first wins
        if (QueryBuilder::table(self::TABLE)->where('token_hash', $hash)->delete() !== 1) {
            return $invalid;
        }
        $id = (int) $row['user_id'];
        $email = (string) $row['email'];
        $user = QueryBuilder::table('users')->where('id', $id)->first();
        if ($user === null || !(bool) ($user['is_active'] ?? true)) {
            return $invalid;
        }
        if (self::inUse($email, $id)) {
            return 'This email is now used by another account.';
        }
        QueryBuilder::table('users')->where('id', $id)->update(['email' => $email, 'updated_at' => date('Y-m-d H:i:s')]);
        self::addressChanged($id);

        return ['user_id' => $id, 'email' => $email];
    }

    /**
     * An account's address changed (confirmed here, or set by an administrator):
     * reset links mailed to the old address stop working, and a waiting change is dropped.
     */
    public static function addressChanged(int $userId): void
    {
        QueryBuilder::table(PasswordResets::TABLE)->where('user_id', $userId)->delete();
        QueryBuilder::table(self::TABLE)->where('user_id', $userId)->delete();
    }

    /** Delete expired changes. */
    public function prune(): int
    {
        return QueryBuilder::table(self::TABLE)->where('expires_at', '<', time())->delete();
    }

    private static function inUse(string $email, int $except): bool
    {
        return QueryBuilder::table('users')->where('email', $email)->where('id', '!=', $except)->exists();
    }

    /** @param array<string, mixed> $data */
    private function send(string $to, string $name, array $data, int $id, ?string $locale = null): void
    {
        AccountMail::send($this->mail, $name, $to, $data, $this->siteName, 'email change message for user #' . $id . ' could not be sent', $locale);
    }
}
