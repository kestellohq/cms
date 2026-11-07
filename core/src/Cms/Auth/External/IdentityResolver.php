<?php

declare(strict_types=1);

namespace Kestello\Cms\Auth\External;

use Closure;
use Kestello\Cms\Auth\Emails;
use Kestello\Cms\Auth\Roles;
use Kestello\Cms\Events\IdentityLinked;
use Kestello\Cms\Events\UserRegistered;
use Kestello\Contracts\Auth\Authenticator;
use Kestello\Contracts\Auth\ExternalIdentity;
use Kestello\Framework\Database\QueryBuilder;
use Kestello\Framework\Events\EventDispatcher;
use Kestello\Framework\Events\Filters;

/**
 * Maps an external identity to a local user, the same way for every
 * provider (auth.md §4):
 *
 * 1. (provider, subject) is linked → that user.
 * 2. Same email as an existing account → link only when the policy allows
 *    it AND the provider verified the email AND trustsEmail(); otherwise
 *    refuse (stops takeover through providers that don't verify emails).
 * 3. No account → register if the policy allows, else refuse.
 *
 * Linking from the profile goes through link() instead.
 *
 * Filters: auth.can_register (bool, ExternalIdentity), auth.default_role (string, ExternalIdentity).
 *
 * @api
 */
final class IdentityResolver
{
    public const string UNUSABLE_PASSWORD_PREFIX = '!';

    /** @param Closure(): ExternalAuthPolicy $policy */
    public function __construct(
        private readonly Identities $identities,
        private readonly Roles $roles,
        private readonly Closure $policy,
        private readonly ?Filters $filters = null,
        private readonly ?EventDispatcher $events = null,
    ) {}

    public function resolve(ExternalIdentity $identity, Authenticator $authenticator): Resolution
    {
        $policy = ($this->policy)();

        $linked = $this->identities->find($identity->provider, $identity->subject);
        if ($linked !== null) {
            $user = QueryBuilder::table('users')->where('id', $linked['user_id'])->first();
            if ($user === null) {
                return Resolution::refused('This account no longer exists.');
            }
            $this->identities->touch((int) $linked['id']);

            return Resolution::of(Resolution::LOGIN, $user);
        }

        $email = $identity->email !== null && Emails::isValid($identity->email) ? Emails::normalize($identity->email) : null;
        $verified = $email !== null && $identity->emailVerified && $authenticator->trustsEmail();

        if ($email !== null) {
            $existing = QueryBuilder::table('users')->where('email', $email)->first();
            if ($existing !== null) {
                if (!$policy->linkByVerifiedEmail || !$verified) {
                    return Resolution::refused('An account with this email already exists. Sign in with your password, then connect ' . $authenticator->label() . ' from your profile.');
                }
                $this->linkTo((int) $existing['id'], $identity, 'email');

                return Resolution::of(Resolution::LINKED, $existing);
            }
        }

        if (!$this->mayRegister($policy, $identity, $email, $verified)) {
            return Resolution::refused($policy->registration === 'closed'
                ? 'There is no account for this sign-in, and registration is closed.'
                : 'There is no account for this sign-in, and it can\'t be used to register.');
        }
        if ($email === null) {
            return Resolution::refused($authenticator->label() . ' did not share an email address, so an account can\'t be created.');
        }

        $user = $this->register($identity, $email, $policy);
        $this->linkTo((int) $user['id'], $identity, 'registration');
        $this->events?->dispatch(new UserRegistered((int) $user['id'], 'external:' . $identity->provider));

        return Resolution::of(Resolution::REGISTERED, $user);
    }

    /** Connect an identity to a signed-in user (profile → Connected accounts). */
    public function link(int $userId, ExternalIdentity $identity): Resolution
    {
        $user = QueryBuilder::table('users')->where('id', $userId)->first();
        if ($user === null) {
            return Resolution::refused('This account no longer exists.');
        }

        $linked = $this->identities->find($identity->provider, $identity->subject);
        if ($linked !== null) {
            return (int) $linked['user_id'] === $userId
                ? Resolution::of(Resolution::LINKED, $user)
                : Resolution::refused('That account is already connected to another user.');
        }

        $this->linkTo($userId, $identity, 'profile');

        return Resolution::of(Resolution::LINKED, $user);
    }

    /** True when the user has no password of their own (created by external sign-in). */
    public static function hasUsablePassword(array $user): bool
    {
        return !str_starts_with((string) ($user['password'] ?? ''), self::UNUSABLE_PASSWORD_PREFIX);
    }

    private function mayRegister(ExternalAuthPolicy $policy, ExternalIdentity $identity, ?string $email, bool $verified): bool
    {
        $allowed = match ($policy->registration) {
            'open' => true,
            'domains' => $verified && $email !== null && in_array(substr($email, strrpos($email, '@') + 1), $policy->domains, true),
            default => false,
        };

        return (bool) ($this->filters?->apply('auth.can_register', $allowed, $identity) ?? $allowed);
    }

    /** @return array<string, mixed> */
    private function register(ExternalIdentity $identity, string $email, ExternalAuthPolicy $policy): array
    {
        $role = (string) ($this->filters?->apply('auth.default_role', $policy->defaultRole, $identity) ?? $policy->defaultRole);
        if (!$this->roles->exists($role) || $role === 'admin') {
            $role = 'user'; // never hand out admin through self-registration
        }

        $now = date('Y-m-d H:i:s');
        $id = (int) QueryBuilder::table('users')->insert([
            'username' => $this->uniqueUsername($identity->name, $email),
            'email' => $email,
            'password' => self::UNUSABLE_PASSWORD_PREFIX . bin2hex(random_bytes(16)),
            'role' => $role,
            'is_active' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return (array) QueryBuilder::table('users')->where('id', $id)->first();
    }

    private function uniqueUsername(?string $name, string $email): string
    {
        $base = strtolower((string) preg_replace('/[^A-Za-z0-9_.-]+/', '', (string) strstr($email, '@', true)));
        if ($base === '' && $name !== null) {
            $base = strtolower((string) preg_replace('/[^A-Za-z0-9_.-]+/', '', str_replace(' ', '.', $name)));
        }
        $base = substr($base !== '' ? $base : 'user', 0, 40);

        $candidate = $base;
        for ($i = 2; QueryBuilder::table('users')->where('username', $candidate)->exists(); $i++) {
            $candidate = $base . $i;
            if ($i > 50) {
                $candidate = $base . bin2hex(random_bytes(3));
            }
        }

        return $candidate;
    }

    private function linkTo(int $userId, ExternalIdentity $identity, string $via): void
    {
        $id = $this->identities->link($userId, $identity);
        $this->events?->dispatch(new IdentityLinked($userId, $identity->provider, $id, $via));
    }
}
