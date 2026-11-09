<?php

declare(strict_types=1);

namespace Kestello\Cms\Package\Update;

use Closure;
use Kestello\Cms\Package\PackageSignature;
use Kestello\Framework\Settings\Settings;

/**
 * Which keys may sign the next version of an installed package.
 *
 * When a signed package is installed or updated, the key that signed it is remembered, with the
 * key ids its manifest lists under "signing_keys" (how a publisher moves to a new key). An update
 * signed by any other key, or not signed, is refused: a trusted key of one publisher can't replace
 * another publisher's plugin, and a feed can't swap in someone else's zip.
 *
 * Pins apply where signatures are required (the admin). Where unsigned packages may be installed
 * anyway (the command line, or app.packages.admin_unsigned on a development site), they add
 * nothing and are not checked. A package installed unsigned has no pin.
 *
 *   pinned("plugin:events")      list of key ids, or null
 *   problem($owner, $signature)  why an update can't be applied, or null
 *   remember($owner, $signature, $signingKeys)
 *
 * @internal
 */
final class KeyPins
{
    /**
     * @param Closure(string $owner): ?string $load  the stored JSON list, or null
     * @param Closure(string $owner, ?string $json): void $save  null forgets
     */
    public function __construct(
        private readonly Closure $load,
        private readonly Closure $save,
    ) {}

    /** Pins kept in the site's settings ("updates.key.plugin.events"). */
    public static function inSettings(): self
    {
        $key = static fn(string $owner): string => 'updates.key.' . str_replace(':', '.', $owner);

        return new self(
            static fn(string $owner): ?string => Settings::getInstance()->get($key($owner)),
            static function (string $owner, ?string $json) use ($key): void {
                $json === null ? Settings::getInstance()->delete($key($owner)) : Settings::getInstance()->set($key($owner), $json, 'updates');
            },
        );
    }

    /** @return list<string>|null */
    public function pinned(string $owner): ?array
    {
        $ids = json_decode((string) ($this->load)($owner), true);

        return is_array($ids) && array_is_list($ids) && $ids !== [] ? array_values(array_filter($ids, 'is_string')) : null;
    }

    /** @param array{status: string, key: ?string} $signature the new zip's (PackageSignature::verify()) */
    public function problem(string $owner, array $signature): ?string
    {
        $pinned = $this->pinned($owner);
        if ($pinned === null) {
            return null;
        }
        if ($signature['status'] !== PackageSignature::SIGNED || !in_array($signature['key'], $pinned, true)) {
            return 'is not signed by the publisher of the installed version (key ' . implode(' or ', $pinned) . '). A developer can replace it from the command line';
        }

        return null;
    }

    /**
     * @param array{status: string, key: ?string} $signature
     * @param list<string> $signingKeys the installed manifest's "signing_keys"
     */
    public function remember(string $owner, array $signature, array $signingKeys): void
    {
        if ($signature['status'] !== PackageSignature::SIGNED || !is_string($signature['key'])) {
            // installed unsigned (or by a key the site doesn't trust, from the CLI): no pin to keep
            ($this->save)($owner, null);

            return;
        }
        ($this->save)($owner, (string) json_encode(array_values(array_unique([$signature['key'], ...$signingKeys]))));
    }

    public function forget(string $owner): void
    {
        ($this->save)($owner, null);
    }
}
