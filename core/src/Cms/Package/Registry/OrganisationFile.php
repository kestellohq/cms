<?php

declare(strict_types=1);

namespace Kestello\Cms\Package\Registry;

/**
 * One organisation (registry-format.md §7): o/<id>/<sha256>.json, pinned by the index, with its
 * root-signed key certificates, current and past. Strict: anything wrong refuses the file, since
 * it decides which keys may sign the organisation's packages.
 *
 *   $org = OrganisationFile::parse($bytes, $index->organisations['acme'], $rootKeys);
 *   $org->keyAt($keyId, $releasedAt)   the certificate when that key was the organisation's then
 *
 * @internal
 */
final readonly class OrganisationFile
{
    private const int MAX_KEYS = 20;

    /** @param list<OrganisationKey> $keys */
    private function __construct(
        public string $id,
        public string $name,
        public ?string $verifiedDomain,
        public ?string $prefix,
        public ?string $homepage,
        public array $keys,
    ) {}

    /**
     * @param array{id: string, file: FileRef} $entry the index entry
     * @param array<string, string> $rootKeys key id => raw public key
     * @throws RegistryException
     */
    public static function parse(string $bytes, array $entry, array $rootKeys): self
    {
        $id = $entry['id'];
        $d = Fields::json($entry['file']->check($bytes), "file for the organisation {$id}");
        $name = Fields::line($d['name'] ?? null, 80);
        $homepage = isset($d['homepage']) ? Fields::https($d['homepage']) : null;
        $prefix = $d['prefix'] ?? null;
        $verified = $d['verified'] ?? null;
        $domain = is_array($verified) ? Fields::line($verified['domain'] ?? null, 253) : null;
        if (($d['format'] ?? null) !== 1 || ($d['id'] ?? null) !== $id || $name === null || (isset($d['homepage']) && $homepage === null)
            || ($prefix !== null && (!is_string($prefix) || !str_ends_with($prefix, '-') || Fields::organisationId(substr($prefix, 0, -1)) === null))
            || ($verified !== null && $domain === null)) {
            throw new RegistryException("The registry's file for the organisation {$id} can't be read.");
        }

        $list = $d['keys'] ?? null;
        if (!is_array($list) || !array_is_list($list) || count($list) > self::MAX_KEYS) {
            throw new RegistryException("The registry's file for the organisation {$id} has no valid list of keys.");
        }
        $keys = [];
        foreach ($list as $envelope) {
            $key = OrganisationKey::verify($envelope, $rootKeys);
            if ($key->organisation !== $id) {
                throw new RegistryException("The registry's file for the organisation {$id} holds a key certified for {$key->organisation}.");
            }
            $keys[] = $key;
        }

        return new self($id, $name, $domain, $prefix, $homepage, $keys);
    }

    /**
     * The certificate of $keyId when it was this organisation's key at $at, or null. Revocations
     * live in keys.json, so a caller also checks RegistryKeys::revoked().
     */
    public function keyAt(string $keyId, int $at): ?OrganisationKey
    {
        foreach ($this->keys as $key) {
            if ($key->id === $keyId && $key->validAt($at)) {
                return $key;
            }
        }

        return null;
    }
}
