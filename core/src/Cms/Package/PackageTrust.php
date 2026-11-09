<?php

declare(strict_types=1);

namespace Kestello\Cms\Package;

use Kestello\Cms\Extension\Package;
use Kestello\Cms\Package\Update\KeyPins;

/**
 * Who the site trusts to give it code, for System → Security: the publishers whose signed
 * plugins and themes the admin installs (app.packages.trusted_keys), whether it takes unsigned
 * ones too (app.packages.admin_unsigned), the keys Kestello's own releases must carry
 * (release.keys), and which key signed each installed plugin and theme (KeyPins).
 *
 * Pure: everything comes in through the constructor.
 *
 * @internal
 */
final class PackageTrust
{
    /**
     * @param array<string, array{name: string, public_key: string}> $trusted key id => key (PackageSignature::trustedKeys)
     * @param array<string, array{name: string, public_key: string}> $release key id => key
     * @param list<Package> $installed
     * @param array<string, bool> $active owner => runs
     */
    public function __construct(
        private readonly array $trusted,
        private readonly array $release,
        private readonly bool $adminUnsigned,
        private readonly array $installed,
        private readonly array $active,
        private readonly KeyPins $pins,
    ) {}

    /**
     * @return array{
     *   admin_unsigned: bool,
     *   publishers: list<array{id: string, name: string}>,
     *   release_keys: list<array{id: string, name: string}>,
     *   packages: list<array{type: string, slug: string, name: string, version: string, active: bool, signed_by: list<array{id: string, name: ?string}>, organisation: ?string, updates: ?string}>
     * }
     */
    public function report(): array
    {
        $packages = [];
        foreach ($this->installed as $package) {
            $owner = $package->owner();
            $signedBy = array_map(fn(string $id): array => ['id' => $id, 'name' => $this->trusted[$id]['name'] ?? null], $this->pins->pinned($owner) ?? []);
            $packages[] = [
                'type' => $package->type()->value,
                'slug' => $package->slug(),
                'name' => $package->manifest->name,
                'version' => $package->version(),
                'active' => $this->active[$owner] ?? false,
                'signed_by' => $signedBy,
                'organisation' => $this->pins->organisation($owner),
                'updates' => $this->pins->organisation($owner) !== null ? 'the registry' : self::source($package->manifest->updates),
            ];
        }
        usort($packages, static fn(array $a, array $b): int => [$a['type'], strtolower($a['name'])] <=> [$b['type'], strtolower($b['name'])]);

        return [
            'admin_unsigned' => $this->adminUnsigned,
            'publishers' => self::keys($this->trusted),
            'release_keys' => self::keys($this->release),
            'packages' => $packages,
        ];
    }

    /**
     * @param array<string, array{name: string, public_key: string}> $keys
     * @return list<array{id: string, name: string}>
     */
    private static function keys(array $keys): array
    {
        return array_map(static fn(string $id, array $k): array => ['id' => $id, 'name' => $k['name']], array_keys($keys), array_values($keys));
    }

    /**
     * Where new versions come from, in a few words: "github.com/acme/events", "updates.acme.example".
     *
     * @param array<string, mixed>|null $updates the manifest's "updates"
     */
    private static function source(?array $updates): ?string
    {
        if (isset($updates['github']) && is_string($updates['github'])) {
            return 'github.com/' . $updates['github'];
        }
        if (isset($updates['url']) && is_string($updates['url'])) {
            $host = parse_url($updates['url'], PHP_URL_HOST);

            return is_string($host) ? $host : null;
        }

        return null;
    }
}
