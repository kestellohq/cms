<?php

declare(strict_types=1);

namespace Kestello\Cms\Auth\External;

/** @api */
final readonly class ExternalLoginResult
{
    public const string SUCCESS = 'success';
    public const string TWO_FACTOR = 'two_factor';
    public const string LINKED = 'linked';
    public const string FAILED = 'failed';

    /** @param array<string, mixed>|null $user */
    public function __construct(
        public string $status,
        public string $redirect,
        public string $message = '',
        public ?array $user = null,
        public bool $registered = false,
    ) {}

    public static function failed(string $message, string $redirect = '/login'): self
    {
        return new self(self::FAILED, $redirect, $message);
    }
}
