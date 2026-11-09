<?php

declare(strict_types=1);

namespace Kestello\Cms\Package\Registry;

use Kestello\Cms\Package\PackageSignature;

/**
 * keys.json: the index keys and every revoked key, signed by a root key (registry-format.md §3.3).
 *
 * The index key signs every day in CI, so it is the key most likely to leak. It can't vouch for
 * itself: only the root, kept offline, says which index keys are valid and which keys are revoked.
 * The file is strict: one malformed entry refuses it whole.
 *
 *   $keys = RegistryKeys::verify($body, $rootKeys, Clock::timestamp(), $lastSerial);
 *   $keys->indexKeysAt($now)   key id => raw public key, valid now and not revoked
 *   $keys->revoked($keyId)     the revocation, or null
 *
 * @internal
 */
final readonly class RegistryKeys
{
    public const int MAX_BYTES = 256 << 10;
    private const int MAX_LIFETIME = 400 * 86_400;     // 13 months and a little
    private const int MAX_INDEX_KEYS = 8;
    private const int MAX_REVOKED = 1000;

    /**
     * @param array<string, array{public_key: string, valid_from: int, valid_until: int}> $indexKeys key id =>
     * @param array<string, array{at: int, reason: string}> $revoked key id =>
     */
    private function __construct(
        public int $serial,
        public int $issuedAt,
        public int $expiresAt,
        private array $indexKeys,
        private array $revoked,
    ) {}

    /**
     * @param array<string, string> $rootKeys key id => raw public key (shipped in core)
     * @param int|null $lastSerial the highest serial this site has accepted; a lower one is refused
     * @throws RegistryException
     */
    public static function verify(string $body, array $rootKeys, int $now, ?int $lastSerial = null): self
    {
        if (strlen($body) > self::MAX_BYTES) {
            throw new RegistryException("The registry's key list is larger than 256 KB.");
        }
        $d = Fields::json(Envelope::open(Fields::json($body, 'key list'), $rootKeys, Envelope::KEY, 'key list'), 'key list');
        if (($d['kind'] ?? null) !== 'keys' || ($d['format'] ?? null) !== 1) {
            throw new RegistryException("The registry's key list is not a key list (format 1).");
        }
        $freshness = Freshness::check($d, $now, self::MAX_LIFETIME, $lastSerial, 'key list');

        $indexKeys = [];
        foreach (self::entries($d['index_keys'] ?? null, self::MAX_INDEX_KEYS, 'index keys') as $k) {
            $public = Fields::publicKey($k['public_key'] ?? null);
            $id = Fields::keyId($k['key'] ?? null);
            $from = Fields::time($k['valid_from'] ?? null);
            $until = Fields::time($k['valid_until'] ?? null);
            if ($public === null || $id === null || $from === null || $until === null || $until <= $from || $until - $from > self::MAX_LIFETIME || PackageSignature::keyId($public) !== $id) {
                throw new RegistryException("The registry's key list has an index key that can't be read.");
            }
            $indexKeys[$id] = ['public_key' => $public, 'valid_from' => $from, 'valid_until' => $until];
        }

        $revoked = [];
        foreach (self::entries($d['revoked'] ?? [], self::MAX_REVOKED, 'revoked keys') as $r) {
            $id = Fields::keyId($r['key'] ?? null);
            $at = Fields::time($r['at'] ?? null);
            $reason = Fields::line($r['reason'] ?? null, 200);
            if ($id === null || $at === null || $reason === null) {
                throw new RegistryException("The registry's key list has a revoked key that can't be read.");
            }
            $revoked[$id] = ['at' => $at, 'reason' => $reason];
        }

        return new self($freshness['serial'], $freshness['issued_at'], $freshness['expires_at'], $indexKeys, $revoked);
    }

    /**
     * Index keys valid at $at and not revoked, for opening the index.
     *
     * @return array<string, string> key id => raw public key
     */
    public function indexKeysAt(int $at): array
    {
        $keys = [];
        foreach ($this->indexKeys as $id => $k) {
            if (!isset($this->revoked[$id]) && $at >= $k['valid_from'] - Fields::CLOCK_TOLERANCE && $at <= $k['valid_until'] + Fields::CLOCK_TOLERANCE) {
                $keys[$id] = $k['public_key'];
            }
        }

        return $keys;
    }

    /** @return array{at: int, reason: string}|null */
    public function revoked(string $keyId): ?array
    {
        return $this->revoked[$keyId] ?? null;
    }

    /** @return list<array<mixed>> */
    private static function entries(mixed $v, int $max, string $what): array
    {
        if (!is_array($v) || !array_is_list($v) || count($v) > $max) {
            throw new RegistryException("The registry's key list has no valid list of {$what}.");
        }
        foreach ($v as $entry) {
            if (!is_array($entry)) {
                throw new RegistryException("The registry's key list has no valid list of {$what}.");
            }
        }

        return $v;
    }
}
