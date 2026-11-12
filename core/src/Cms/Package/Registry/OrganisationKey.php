<?php

declare(strict_types=1);

namespace Kestello\Cms\Package\Registry;

use Kestello\Cms\Package\PackageSignature;

/**
 * A root-signed certificate saying a key belongs to an organisation, for a time
 * (registry-format.md §3.4). Package zips signed by the key are that organisation's.
 *
 * @internal
 */
final readonly class OrganisationKey
{
    private const int MAX_LIFETIME = 3 * 366 * 86_400;

    private function __construct(
        public string $organisation,
        public string $id,
        public string $publicKey,
        public int $validFrom,
        public int $validUntil,
    ) {}

    /**
     * @param array<string, string> $rootKeys key id => raw public key
     * @throws RegistryException
     */
    public static function verify(mixed $envelope, array $rootKeys): self
    {
        $d = Fields::json(Envelope::open($envelope, $rootKeys, Envelope::KEY, 'organisation key'), 'organisation key');
        $org = Fields::organisationId($d['organisation'] ?? null);
        $id = Fields::keyId($d['key'] ?? null);
        $public = Fields::publicKey($d['public_key'] ?? null);
        $from = Fields::time($d['valid_from'] ?? null);
        $until = Fields::time($d['valid_until'] ?? null);
        if (($d['kind'] ?? null) !== 'organisation' || $org === null || $id === null || $public === null || $from === null || $until === null
            || $until <= $from || $until - $from > self::MAX_LIFETIME || PackageSignature::keyId($public) !== $id) {
            throw new RegistryException("The registry has an organisation key certificate that can't be read.");
        }

        return new self($org, $id, $public, $from, $until);
    }

    /** Whether the key was certified at $at (with the clock tolerance either way). */
    public function validAt(int $at): bool
    {
        return $at >= $this->validFrom - Fields::CLOCK_TOLERANCE && $at <= $this->validUntil + Fields::CLOCK_TOLERANCE;
    }
}
