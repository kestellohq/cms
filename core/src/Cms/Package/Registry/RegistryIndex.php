<?php

declare(strict_types=1);

namespace Kestello\Cms\Package\Registry;

/**
 * The signed index (registry-format.md §4): every package and organisation in short, the
 * advisories file, and the moves of packages between organisations. It names every other file
 * by its SHA-256 (FileRef), so its one signature covers the whole registry.
 *
 *   $keys  = RegistryKeys::verify($keysBody, $rootKeys, $now, $lastKeysSerial);
 *   $index = RegistryIndex::verify($indexBody, $keys, $rootKeys, $now, $lastIndexSerial);
 *   $index->packages['events']   the entry, with ->file to fetch the package file
 *
 * The index as a whole is refused when its signature, dates, serial or structure are wrong, or
 * when it names a package or organisation twice. A single package or organisation entry that
 * can't be read is left out, never guessed at.
 *
 * @internal
 */
final readonly class RegistryIndex
{
    public const int MAX_BYTES = 8 << 20;
    public const int PACKAGE_FILE_BYTES = 1 << 20;
    public const int ORGANISATION_FILE_BYTES = 64 << 10;
    public const int ADVISORIES_BYTES = 2 << 20;
    private const int MAX_LIFETIME = 14 * 86_400;
    private const int MAX_PACKAGES = 20_000;
    private const int MAX_MOVES = 10_000;

    /**
     * @param array<string, array{slug: string, type: string, name: string, summary: string, organisation: string, categories: list<string>, icon: ?string, stable: ?string, prerelease: ?string, requires_core: ?string, paid: bool, updated_at: int, file: FileRef}> $packages slug =>
     * @param array<string, array{id: string, name: string, verified: ?string, file: FileRef}> $organisations id =>
     * @param array<string, list<array{from: string, to: string, issued_at: int}>> $moves slug => oldest first
     */
    private function __construct(
        public int $serial,
        public int $issuedAt,
        public int $expiresAt,
        public array $packages,
        public array $organisations,
        public FileRef $advisories,
        public array $moves,
    ) {}

    /**
     * @param array<string, string> $rootKeys key id => raw public key, for the move certificates
     * @throws RegistryException
     */
    public static function verify(string $body, RegistryKeys $keys, array $rootKeys, int $now, ?int $lastSerial = null): self
    {
        if (strlen($body) > self::MAX_BYTES) {
            throw new RegistryException("The registry's list is larger than 8 MB.");
        }
        $d = Fields::json(Envelope::open(Fields::json($body, 'list'), $keys->indexKeysAt($now), Envelope::INDEX, 'list'), 'list');
        if (($d['format'] ?? null) !== 1) {
            throw new RegistryException("The registry's list is not format 1.");
        }
        $freshness = Freshness::check($d, $now, self::MAX_LIFETIME, $lastSerial, 'list');

        $advisories = FileRef::parse($d['advisories'] ?? null, self::ADVISORIES_BYTES);
        if ($advisories === null) {
            throw new RegistryException("The registry's list doesn't name a valid advisories file.");
        }

        $packages = [];
        foreach (self::entries($d['packages'] ?? null, self::MAX_PACKAGES, 'packages') as $entry) {
            $package = self::package($entry);
            if ($package === null) {
                continue;
            }
            if (isset($packages[$package['slug']])) {
                throw new RegistryException("The registry's list names the package \"{$package['slug']}\" twice.");
            }
            $packages[$package['slug']] = $package;
        }

        $organisations = [];
        foreach (self::entries($d['organisations'] ?? null, self::MAX_PACKAGES, 'organisations') as $entry) {
            $organisation = self::organisation($entry);
            if ($organisation === null) {
                continue;
            }
            if (isset($organisations[$organisation['id']])) {
                throw new RegistryException("The registry's list names the organisation \"{$organisation['id']}\" twice.");
            }
            $organisations[$organisation['id']] = $organisation;
        }

        $moves = [];
        foreach (self::entries($d['moves'] ?? [], self::MAX_MOVES, 'moves') as $envelope) {
            $move = self::move($envelope, $rootKeys);
            $moves[$move['slug']][] = ['from' => $move['from'], 'to' => $move['to'], 'issued_at' => $move['issued_at']];
        }
        foreach ($moves as &$list) {
            usort($list, static fn(array $a, array $b): int => $a['issued_at'] <=> $b['issued_at']);
        }
        unset($list);

        return new self($freshness['serial'], $freshness['issued_at'], $freshness['expires_at'], $packages, $organisations, $advisories, $moves);
    }

    /** @return list<array<mixed>> */
    private static function entries(mixed $v, int $max, string $what): array
    {
        if (!is_array($v) || !array_is_list($v) || count($v) > $max) {
            throw new RegistryException("The registry's list has no valid list of {$what}.");
        }

        return array_values(array_filter($v, 'is_array'));
    }

    /**
     * @param array<mixed> $p
     * @return array{slug: string, type: string, name: string, summary: string, organisation: string, categories: list<string>, icon: ?string, stable: ?string, prerelease: ?string, requires_core: ?string, paid: bool, updated_at: int, file: FileRef}|null
     */
    private static function package(array $p): ?array
    {
        $slug = Fields::packageName($p['slug'] ?? null);
        $type = in_array($p['type'] ?? null, ['plugin', 'theme'], true) ? $p['type'] : null;
        $name = Fields::line($p['name'] ?? null, 80);
        $summary = Fields::line($p['summary'] ?? null, 160);
        $organisation = Fields::organisationId($p['organisation'] ?? null);
        $categories = Fields::listOf($p['categories'] ?? null, 10, Fields::packageName(...));
        $icon = isset($p['icon']) ? Fields::path($p['icon']) : null;
        $latest = is_array($p['latest'] ?? null) ? $p['latest'] : [];
        $stable = isset($latest['stable']) ? Fields::version($latest['stable']) : null;
        $prerelease = isset($latest['prerelease']) ? Fields::version($latest['prerelease']) : null;
        $core = isset($p['requires_core']) ? Fields::constraint($p['requires_core']) : null;
        $updated = Fields::time($p['updated_at'] ?? null);
        $file = FileRef::parse($p['file'] ?? null, self::PACKAGE_FILE_BYTES);
        if ($slug === null || $type === null || $name === null || $summary === null || $organisation === null || $categories === null
            || (isset($p['icon']) && $icon === null) || ($stable === null && $prerelease === null) || (isset($latest['stable']) && $stable === null)
            || (isset($latest['prerelease']) && $prerelease === null) || (isset($p['requires_core']) && $core === null)
            || !is_bool($p['paid'] ?? null) || $updated === null || $file === null) {
            return null;
        }

        return [
            'slug' => $slug, 'type' => $type, 'name' => $name, 'summary' => $summary, 'organisation' => $organisation,
            'categories' => $categories, 'icon' => $icon, 'stable' => $stable, 'prerelease' => $prerelease,
            'requires_core' => $core, 'paid' => $p['paid'], 'updated_at' => $updated, 'file' => $file,
        ];
    }

    /**
     * @param array<mixed> $o
     * @return array{id: string, name: string, verified: ?string, file: FileRef}|null
     */
    private static function organisation(array $o): ?array
    {
        $id = Fields::organisationId($o['id'] ?? null);
        $name = Fields::line($o['name'] ?? null, 80);
        $verified = isset($o['verified']) ? Fields::line($o['verified'], 253) : null;
        $file = FileRef::parse($o['file'] ?? null, self::ORGANISATION_FILE_BYTES);
        if ($id === null || $name === null || (isset($o['verified']) && $verified === null) || $file === null) {
            return null;
        }

        return ['id' => $id, 'name' => $name, 'verified' => $verified, 'file' => $file];
    }

    /**
     * A move certificate, signed by a root key (registry-format.md §3.5). A bad one refuses the
     * whole index: the index key can't make moves, so a bad move means something is wrong.
     *
     * @param array<mixed> $envelope
     * @param array<string, string> $rootKeys
     * @return array{slug: string, from: string, to: string, issued_at: int}
     */
    private static function move(array $envelope, array $rootKeys): array
    {
        $d = Fields::json(Envelope::open($envelope, $rootKeys, Envelope::KEY, 'move certificate'), 'move certificate');
        $slug = Fields::packageName($d['slug'] ?? null);
        $from = Fields::organisationId($d['from'] ?? null);
        $to = Fields::organisationId($d['to'] ?? null);
        $issued = Fields::time($d['issued_at'] ?? null);
        if (($d['kind'] ?? null) !== 'move' || $slug === null || $from === null || $to === null || $from === $to || $issued === null) {
            throw new RegistryException("The registry's list has a move certificate that can't be read.");
        }

        return ['slug' => $slug, 'from' => $from, 'to' => $to, 'issued_at' => $issued];
    }
}
