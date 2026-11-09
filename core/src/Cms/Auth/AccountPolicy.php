<?php

declare(strict_types=1);

namespace Kestello\Cms\Auth;

use Kestello\Cms\Cms;
use Kestello\Framework\Settings\Settings;

/**
 * Who may create an account, by email sign-up or by external sign-in, and
 * how external sign-ins are trusted (Settings → Accounts). Read from the
 * settings first, then config/auth.php, then these defaults.
 *
 *   auth.registration            closed | open | domains   (closed)
 *   auth.registration_domains    "example.com, example.org" (for "domains")
 *   auth.default_role            user
 *   auth.link_by_verified_email  false
 *   auth.trust_provider_mfa      false  skip our 2FA when the provider asserts MFA (amr)
 *
 * @api
 */
final readonly class AccountPolicy
{
    public const array REGISTRATION = ['closed', 'open', 'domains'];

    /** @param list<string> $domains */
    public function __construct(
        public string $registration = 'closed',
        public array $domains = [],
        public string $defaultRole = 'user',
        public bool $linkByVerifiedEmail = false,
        public bool $trustProviderMfa = false,
    ) {
        if (!in_array($registration, self::REGISTRATION, true)) {
            throw new \InvalidArgumentException("Unknown registration policy \"{$registration}\".");
        }
    }

    /** Whether anyone at all may create an account. */
    public function isOpen(): bool
    {
        return $this->registration !== 'closed';
    }

    /** Whether this (normalized) address may create an account. */
    public function allows(string $email): bool
    {
        return match ($this->registration) {
            'open' => true,
            'domains' => in_array(self::domainOf($email), $this->domains, true),
            default => false,
        };
    }

    public static function domainOf(string $email): string
    {
        $at = strrpos($email, '@');

        return $at === false ? '' : substr($email, $at + 1);
    }

    public static function current(?Cms $cms = null): self
    {
        $cms ??= Cms::instance();
        $get = static function (string $key, mixed $default) use ($cms): mixed {
            try {
                $value = Settings::getInstance()->get('auth.' . $key);
                if ($value !== null && $value !== '') {
                    return $value;
                }
            } catch (\Throwable) {
                // no database
            }

            return $cms?->config->get('auth.' . $key, $default) ?? $default;
        };
        $bool = static fn(mixed $v): bool => filter_var($v, FILTER_VALIDATE_BOOLEAN);
        $domains = $get('registration_domains', []);
        if (is_string($domains)) {
            $domains = explode(',', $domains);
        }
        $registration = (string) $get('registration', 'closed');

        return new self(
            registration: in_array($registration, self::REGISTRATION, true) ? $registration : 'closed',
            domains: array_values(array_filter(array_map(static fn($d) => strtolower(trim((string) $d)), (array) $domains))),
            defaultRole: (string) $get('default_role', 'user'),
            linkByVerifiedEmail: $bool($get('link_by_verified_email', false)),
            trustProviderMfa: $bool($get('trust_provider_mfa', false)),
        );
    }
}
