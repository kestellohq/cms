<?php

declare(strict_types=1);

namespace Kestello\Contracts\Auth;

/**
 * One redirect sign-in try, created by core. Single-use, bound to the
 * session that started it, expires after 10 minutes.
 *
 * @api
 */
final readonly class AuthAttempt
{
    public const int TTL = 600;
    public const array INTENTS = ['login', 'link'];

    public function __construct(
        public string $provider,
        public string $state,
        public string $nonce,
        public string $pkceVerifier,
        public string $intent,
        public string $redirectUri,
        public ?string $returnTo,
        public ?int $userId,
        public int $expiresAt,
    ) {}

    public static function start(string $provider, string $intent, string $redirectUri, ?string $returnTo = null, ?int $userId = null): self
    {
        if (!in_array($intent, self::INTENTS, true)) {
            throw new \InvalidArgumentException("Unknown intent \"{$intent}\".");
        }

        return new self(
            provider: $provider,
            state: self::random(32),
            nonce: self::random(32),
            pkceVerifier: self::random(48),
            intent: $intent,
            redirectUri: $redirectUri,
            returnTo: $returnTo,
            userId: $userId,
            expiresAt: time() + self::TTL,
        );
    }

    /** S256 code challenge for $pkceVerifier. */
    public function pkceChallenge(): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $this->pkceVerifier, true)), '+/', '-_'), '=');
    }

    public function expired(): bool
    {
        return $this->expiresAt < time();
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return get_object_vars($this);
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) $data['provider'],
            (string) $data['state'],
            (string) $data['nonce'],
            (string) $data['pkceVerifier'],
            (string) $data['intent'],
            (string) $data['redirectUri'],
            isset($data['returnTo']) ? (string) $data['returnTo'] : null,
            isset($data['userId']) ? (int) $data['userId'] : null,
            (int) $data['expiresAt'],
        );
    }

    private static function random(int $bytes): string
    {
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }
}
