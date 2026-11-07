<?php

declare(strict_types=1);

namespace Kestello\Cms\Auth\External;

/** What IdentityResolver decided. @api */
final readonly class Resolution
{
    public const string LOGIN = 'login';
    public const string LINKED = 'linked';
    public const string REGISTERED = 'registered';
    public const string REFUSED = 'refused';

    /** @param array<string, mixed>|null $user */
    private function __construct(public string $status, public ?array $user, public string $message = '') {}

    /** @param array<string, mixed> $user */
    public static function of(string $status, array $user): self
    {
        return new self($status, $user);
    }

    public static function refused(string $message): self
    {
        return new self(self::REFUSED, null, $message);
    }

    public function ok(): bool
    {
        return $this->status !== self::REFUSED;
    }
}
