<?php

declare(strict_types=1);

namespace Kestello\Cms\Package\Registry;

use Kestello\Cms\Package\PackageSignature;

/**
 * The registry's root keys from config (registry.root_keys), as the readers want them: key id =>
 * raw public key. Entries that aren't Ed25519 public keys are skipped and logged, as for
 * release.keys.
 *
 * @internal
 */
final class RootKeys
{
    /** @return array<string, string> */
    public static function fromConfig(mixed $config): array
    {
        $keys = [];
        foreach (PackageSignature::trustedKeys($config, 'registry.root_keys') as $id => $key) {
            $keys[$id] = (string) base64_decode($key['public_key'], true);
        }

        return $keys;
    }
}
