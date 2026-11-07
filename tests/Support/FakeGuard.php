<?php

declare(strict_types=1);

namespace Kestello\Tests\Support;

use Kestello\Contracts\Auth\Guard;
use Kestello\Contracts\Auth\Principal;
use Kestello\Framework\Core\Request;

/** Authenticates every request as a fixed principal (null = nobody). */
final class FakeGuard implements Guard
{
    public ?Principal $principal = null;

    public function __construct(private readonly bool $bearer = false, private readonly string $id = 'fake') {}

    public function id(): string
    {
        return $this->id;
    }
    public function isBearer(): bool
    {
        return $this->bearer;
    }
    public function authenticate(Request $request): ?Principal
    {
        return $this->principal;
    }
}
