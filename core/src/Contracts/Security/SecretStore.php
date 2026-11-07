<?php

declare(strict_types=1);

namespace Kestello\Contracts\Security;

/**
 * Encrypts secrets (passwords, API keys) for storage at rest.
 *
 * $context binds a ciphertext to its purpose ("platform.cache.password"):
 * a value sealed for one context will not open under another, so a secret
 * can't be moved into a field that would display it.
 *
 * @api
 */
interface SecretStore
{
    public function seal(string $plaintext, string $context): string;

    /** @throws \RuntimeException when the value is tampered with or the key is unknown */
    public function open(string $sealed, string $context): string;

    /** Whether $value looks like output of seal(). */
    public function isSealed(string $value): bool;
}
