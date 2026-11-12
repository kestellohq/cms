<?php

declare(strict_types=1);

namespace Kestello\Cms\Auth;

use Closure;
use Kestello\Contracts\Auth\ExternalIdentity;
use Kestello\Framework\Database\QueryBuilder;
use Kestello\Framework\Events\Filters;

/**
 * Accounts people create for themselves, by email sign-up (Registrations)
 * or by signing in with a provider (IdentityResolver). Both ask the same
 * policy and filters, and get the same kind of account.
 *
 * Filters:
 *   auth.can_register   bool,   string $email, ?ExternalIdentity $identity (null for email sign-up)
 *   auth.default_role   string, string $email, ?ExternalIdentity $identity
 */
final readonly class NewAccounts
{
    /** @param Closure(): AccountPolicy $policy */
    public function __construct(
        private Roles $roles,
        private Closure $policy,
        private ?Filters $filters = null,
    ) {}

    public function policy(): AccountPolicy
    {
        return ($this->policy)();
    }

    /**
     * Whether this address may create an account. The address must be proven,
     * by the emailed link or verified by a provider that is trusted with
     * emails: an account never starts with an address nobody confirmed.
     */
    public function mayRegister(string $email, ?ExternalIdentity $identity = null, bool $emailProven = true): bool
    {
        $allowed = $this->policy()->allows($email) && $emailProven;

        return (bool) ($this->filters?->apply('auth.can_register', $allowed, $email, $identity) ?? $allowed);
    }

    /**
     * Insert the account. The caller has checked mayRegister() and that the
     * address is free; the unique index catches a race.
     *
     * @param string $passwordHash a Hash::password() value, or an unusable one for provider-only accounts
     * @return array<string, mixed>
     */
    public function create(string $email, string $passwordHash, ?ExternalIdentity $identity = null): array
    {
        $now = date('Y-m-d H:i:s');
        $id = (int) QueryBuilder::table('users')->insert([
            'username' => $this->uniqueUsername($email, $identity?->name),
            'email' => $email,
            'password' => $passwordHash,
            'role' => $this->role($email, $identity),
            'is_active' => 1,
            'locale' => \Kestello\Cms\I18n\PersonLanguage::atSignUp(),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return (array) QueryBuilder::table('users')->where('id', $id)->first();
    }

    private function role(string $email, ?ExternalIdentity $identity): string
    {
        $default = $this->policy()->defaultRole;
        $role = (string) ($this->filters?->apply('auth.default_role', $default, $email, $identity) ?? $default);

        // Never hand out administrator, or a role as good as it, through self-registration.
        return $this->roles->selfServe($role) ? $role : 'user';
    }

    /** "ana" from ana@example.com, then "ana2", "ana3"... */
    private function uniqueUsername(string $email, ?string $name): string
    {
        $base = strtolower((string) preg_replace('/[^A-Za-z0-9_.-]+/', '', (string) strstr($email, '@', true)));
        if ($base === '' && $name !== null) {
            $base = strtolower((string) preg_replace('/[^A-Za-z0-9_.-]+/', '', str_replace(' ', '.', $name)));
        }
        $base = substr($base !== '' ? $base : 'user', 0, 40);

        $candidate = $base;
        for ($i = 2; QueryBuilder::table('users')->where('username', $candidate)->exists(); $i++) {
            $candidate = $i > 50 ? $base . bin2hex(random_bytes(3)) : $base . $i;
        }

        return $candidate;
    }
}
