<?php

declare(strict_types=1);

namespace Kestello\Cms\Package\Update;

use Closure;
use Kestello\Cms\Package\PackageSignature;
use Kestello\Framework\Settings\Settings;

/**
 * Who may sign the next version of an installed package.
 *
 * Two kinds of pin, remembered when a signed package is installed or updated:
 *
 *   - from the registry: the organisation it belongs to (registry-format.md §5.3). The next version
 *     must again be one the registry vouches for, signed by that organisation, or by one the
 *     package moved to with a root-signed move. The organisation changes keys through the root,
 *     so sites never need to know its keys in advance.
 *   - from elsewhere: the key that signed it, with the key ids its manifest lists under
 *     "signing_keys" (how a publisher moves to a new key).
 *
 * Either way the "signing_keys" of the installed manifest are also accepted, so a publisher can
 * hand a package over (P4a). Anything else, or an unsigned zip, is refused: one publisher's
 * trusted key can't replace another publisher's plugin, and a feed can't swap in someone else's zip.
 *
 * Pins apply where signatures are required (the admin). Where unsigned packages may be installed
 * anyway (the command line, or app.packages.admin_unsigned on a development site), they are not
 * checked. A package installed unsigned has no pin.
 *
 * Stored as JSON: a list of key ids (from elsewhere), or {"organisation": "acme", "keys": […]}.
 *
 *   pinned("plugin:events")        the key ids accepted, or null
 *   organisation("plugin:events")  the registry organisation, or null (not from the registry)
 *   problem($owner, $signature, $registry)  why a zip can't replace the installed version, or null
 *   remember($owner, $signature, $signingKeys, $organisation)
 *
 * @internal
 */
final class KeyPins
{
    /**
     * @param Closure(string $owner): ?string $load  the stored JSON, or null
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

    /**
     * The key ids accepted for the next version; for a registry package, only the hand-over keys.
     *
     * @return list<string>|null
     */
    public function pinned(string $owner): ?array
    {
        $pin = $this->read($owner);

        return $pin !== null && $pin['keys'] !== [] ? $pin['keys'] : null;
    }

    /** The registry organisation the package is pinned to, or null (not from the registry). */
    public function organisation(string $owner): ?string
    {
        return $this->read($owner)['organisation'] ?? null;
    }

    /**
     * @param array{status: string, key: ?string} $signature the new zip's (PackageSignature::verify())
     * @param array{organisation: string, moved_from: list<string>}|null $registry what the registry
     *        vouches for about the new zip (RegistryTrust), or null when it doesn't
     */
    public function problem(string $owner, array $signature, ?array $registry = null): ?string
    {
        $pin = $this->read($owner);
        if ($pin === null) {
            return null;
        }
        $signed = $signature['status'] === PackageSignature::SIGNED && is_string($signature['key']);
        if ($signed && in_array($signature['key'], $pin['keys'], true)) {
            return null;
        }
        if ($pin['organisation'] === null) {
            return 'is not signed by the publisher of the installed version (key ' . implode(' or ', $pin['keys']) . '). A developer can replace it from the command line';
        }
        if ($signed && $registry !== null
            && ($registry['organisation'] === $pin['organisation'] || in_array($pin['organisation'], $registry['moved_from'], true))) {
            return null;
        }

        return $registry === null
            ? "is not a version the registry vouches for, and the installed version came from the registry (organisation {$pin['organisation']}). Install updates from the registry, or a developer can replace it from the command line"
            : "is published by {$registry['organisation']} in the registry, but the installed version is {$pin['organisation']}'s and the package hasn't moved. A developer can replace it from the command line";
    }

    /**
     * @param array{status: string, key: ?string} $signature
     * @param list<string> $signingKeys the installed manifest's "signing_keys"
     * @param ?string $organisation the registry organisation, when the registry vouched for the zip
     */
    public function remember(string $owner, array $signature, array $signingKeys, ?string $organisation = null): void
    {
        if ($signature['status'] !== PackageSignature::SIGNED || !is_string($signature['key'])) {
            // installed unsigned (or by a key the site doesn't trust, from the CLI): no pin to keep
            ($this->save)($owner, null);

            return;
        }
        $keys = array_values(array_unique(array_filter($signingKeys, 'is_string')));
        ($this->save)($owner, (string) json_encode($organisation !== null
            ? ['organisation' => $organisation, 'keys' => $keys]
            : array_values(array_unique([$signature['key'], ...$keys]))));
    }

    public function forget(string $owner): void
    {
        ($this->save)($owner, null);
    }

    /** @return array{organisation: ?string, keys: list<string>}|null */
    private function read(string $owner): ?array
    {
        $data = json_decode((string) ($this->load)($owner), true);
        if (is_array($data) && array_is_list($data)) {
            $keys = array_values(array_filter($data, 'is_string'));

            return $keys !== [] ? ['organisation' => null, 'keys' => $keys] : null;
        }
        if (is_array($data) && is_string($data['organisation'] ?? null) && $data['organisation'] !== '') {
            return ['organisation' => $data['organisation'], 'keys' => array_values(array_filter(is_array($data['keys'] ?? null) ? $data['keys'] : [], 'is_string'))];
        }

        return null;
    }
}
