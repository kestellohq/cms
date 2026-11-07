<?php

declare(strict_types=1);

namespace Kestello\Cms\Auth;

use Kestello\Framework\Events\Filters;

/**
 * Rules for new passwords: a minimum length (default 12), no very common
 * passwords, nothing built from the username or email. Long passphrases
 * are welcome (Argon2id has no length limit; bcrypt-only hosts cap at 72 bytes).
 *
 * The "auth.password.errors" filter can add rules (e.g. a breached-password
 * plugin): fn(list<string> $errors, string $password, array $user): list<string>.
 *
 * @api
 */
final class PasswordPolicy
{
    private const array COMMON = [
        'password', 'password1', 'password12', 'password123', 'password1234', 'passw0rd', '123456789012', '1234567890',
        'qwertyuiop', 'qwerty123456', 'iloveyou', 'letmein', 'welcome', 'welcome123', 'admin', 'administrator',
        'changeme', 'trustno1', 'football', 'baseball', 'superman', 'sunshine', 'princess', 'dragon', 'monkey',
        'master', 'shadow', 'michael', 'starwars', 'whatever', 'qazwsxedc', '1q2w3e4r5t6y', 'zaq12wsx', 'asdfghjkl',
        'abc123456789', 'secret', 'kestello', 'correcthorsebatterystaple', 'aaaaaaaaaaaa',
    ];

    public function __construct(
        private readonly int $minLength = 12,
        private readonly ?Filters $filters = null,
    ) {}

    public function minLength(): int
    {
        return $this->minLength;
    }

    /**
     * @param array<string, mixed> $user username/email of the account, when known
     * @return list<string> messages; empty when the password is acceptable
     */
    public function errors(string $password, array $user = []): array
    {
        $errors = [];
        $length = mb_strlen($password);

        if ($length < $this->minLength) {
            $errors[] = "Use at least {$this->minLength} characters.";
        }
        if (strlen($password) > 4096) {
            $errors[] = 'That password is too long.';
        }
        if (!defined('PASSWORD_ARGON2ID') && strlen($password) > 72) {
            $errors[] = 'Use at most 72 bytes (this server hashes with bcrypt).';
        }

        $lower = mb_strtolower($password);
        $squashed = preg_replace('/[^a-z0-9]/', '', $lower) ?? '';
        if (in_array($lower, self::COMMON, true) || in_array($squashed, self::COMMON, true) || preg_match('/^(.)\1+$/u', $password) === 1) {
            $errors[] = 'That password is too common.';
        }

        foreach (['username', 'email'] as $field) {
            $value = mb_strtolower((string) ($user[$field] ?? ''));
            if ($field === 'email') {
                $value = explode('@', $value)[0];
            }
            if (mb_strlen($value) >= 4 && str_contains($lower, $value)) {
                $errors[] = 'Don\'t use your username or email in your password.';
                break;
            }
        }

        if ($this->filters?->has('auth.password.errors')) {
            $filtered = $this->filters->apply('auth.password.errors', $errors, $password, $user);
            if (is_array($filtered)) {
                $errors = array_values(array_unique([...$errors, ...array_filter($filtered, 'is_string')]));
            }
        }

        return $errors;
    }
}
