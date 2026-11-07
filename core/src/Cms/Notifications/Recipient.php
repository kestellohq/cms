<?php

declare(strict_types=1);

namespace Kestello\Cms\Notifications;

use Kestello\Framework\Database\DatabaseManager;

/** Who a notification goes to (a user, or an address with no account). @api */
final readonly class Recipient
{
    /** @param array<string, string> $routes extra addresses per channel (phone, slack id…) */
    public function __construct(
        public ?int $userId,
        public ?string $email,
        public string $name = '',
        public ?string $locale = null,
        public array $routes = [],
    ) {}

    public static function user(int $id): ?self
    {
        $row = DatabaseManager::getConnection()->table('users')->where('id', $id)->first();
        if ($row === null) {
            return null;
        }

        return new self((int) $row['id'], (string) $row['email'], (string) ($row['username'] ?? ''), isset($row['locale']) && $row['locale'] !== '' ? (string) $row['locale'] : null);
    }

    /** Someone without an account (newsletter, form submitter). Only mail and plugin channels apply. */
    public static function email(string $email, string $name = ''): self
    {
        return new self(null, $email, $name);
    }

    public function route(string $channel): ?string
    {
        return $channel === 'mail' ? $this->email : ($this->routes[$channel] ?? null);
    }
}
