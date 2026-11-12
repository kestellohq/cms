<?php

declare(strict_types=1);

namespace Kestello\Cms\Package\Registry;

use Closure;
use Throwable;

/**
 * Whether the registry vouches for a package zip (registry-format.md §5.3). Every zip a site
 * installs or updates from, uploaded or downloaded, is asked about here, so there is one place
 * where registry trust is decided.
 *
 * A zip is a registry version when its SHA-256 and size are those of a version of the package its
 * manifest names. The registry then vouches for it when all of these hold:
 *
 *   1. the manifest's type, slug and version are that version's;
 *   2. the version isn't withdrawn;
 *   3. the key the version names isn't revoked (keys.json);
 *   4. the root certified that key for the package's organisation, or for one the package moved
 *      from (root-signed moves), on the version's release date.
 *
 * The zip must then be signed by that key, and by nothing else: the caller verifies the signature
 * with only the key returned in "trusted".
 *
 *   $verdict = $trust->vouch($zipPath, 'plugin', 'events', '1.5.0');
 *   $verdict['status']   vouched | refused | unknown
 *
 * "unknown" is not a refusal: the registry doesn't list these bytes, is turned off, or can't be
 * read right now ("reason" says which when it isn't simply unlisted). Such a zip is trusted, or
 * not, as any other upload. "refused" is final: the registry lists these exact bytes and says no.
 *
 * @internal
 */
final class RegistryTrust
{
    public const string VOUCHED = 'vouched';
    public const string REFUSED = 'refused';
    public const string UNKNOWN = 'unknown';

    /** @param Closure(): ?RegistryClient $client null when the registry is turned off */
    public function __construct(private readonly Closure $client) {}

    /**
     * @return array{status: string, reason: ?string, version: ?string, organisation: ?string, organisation_name: ?string, moved_from: list<string>, key: ?string, trusted: array<string, array{name: string, public_key: string}>, advisories: list<array<string, mixed>>}
     */
    public function vouch(string $zipPath, string $type, string $slug, string $version): array
    {
        $client = ($this->client)();
        if ($client === null) {
            return self::verdict(self::UNKNOWN);
        }
        try {
            $index = $client->index(throw: true);
            $entry = $index?->packages[$slug] ?? null;
            if ($index === null || $entry === null || $entry['type'] !== $type) {
                return self::verdict(self::UNKNOWN);
            }
            $sha = (string) hash_file('sha256', $zipPath);
            $size = (int) filesize($zipPath);
            $package = $client->package($slug) ?? throw new RegistryException("The registry doesn't list {$slug}.");
            $listed = null;
            foreach ($package['versions'] as $v) {
                if (hash_equals($v['sha256'], $sha) && $v['size'] === $size) {
                    $listed = $v;
                    break;
                }
            }
            if ($listed === null) {
                return self::verdict(self::UNKNOWN);
            }
        } catch (Throwable $e) {
            return self::verdict(self::UNKNOWN, $e instanceof RegistryException ? $e->getMessage() : "The registry can't be read: {$e->getMessage()}");
        }

        $name = "{$package['name']} {$listed['version']}";
        if ($listed['version'] !== $version) {
            return self::verdict(self::REFUSED, "This zip is {$name} in the registry, but its manifest says version {$version}.");
        }
        if ($listed['withdrawn'] !== null) {
            return self::verdict(self::REFUSED, "{$name} was withdrawn from the registry: {$listed['withdrawn']['reason']}");
        }

        try {
            $revoked = $client->keys()->revoked($listed['key']);
            if ($revoked !== null) {
                return self::verdict(self::REFUSED, "{$name} is signed with a key the registry has revoked ({$listed['key']}). It can't be installed.");
            }
            $movedFrom = self::movedFrom($index->moves[$slug] ?? [], $entry['organisation']);
            foreach ([$entry['organisation'], ...$movedFrom] as $id) {
                $organisation = $client->organisation($id);
                $certificate = $organisation?->keyAt($listed['key'], $listed['released_at']);
                if ($organisation !== null && $certificate !== null) {
                    return [
                        'status' => self::VOUCHED,
                        'reason' => null,
                        'version' => $listed['version'],
                        'organisation' => $entry['organisation'],
                        'organisation_name' => $organisation->name,
                        'moved_from' => $movedFrom,
                        'key' => $listed['key'],
                        'trusted' => [$listed['key'] => ['name' => $organisation->name . ' (registry)', 'public_key' => base64_encode($certificate->publicKey)]],
                        'advisories' => self::advisories($client, $type, $slug, $listed['version']),
                    ];
                }
            }
        } catch (Throwable $e) {
            return self::verdict(self::UNKNOWN, $e instanceof RegistryException ? $e->getMessage() : "The registry can't be read: {$e->getMessage()}");
        }

        return self::verdict(self::REFUSED, "{$name} is signed with key {$listed['key']}, which the registry hasn't certified for {$entry['organisation']} on the day it was released.");
    }

    /**
     * The organisations a package moved away from to reach $current, newest first.
     *
     * @param list<array{from: string, to: string, issued_at: int}> $moves oldest first
     * @return list<string>
     */
    public static function movedFrom(array $moves, string $current): array
    {
        $out = [];
        $at = $current;
        foreach (array_reverse($moves) as $move) {
            if ($move['to'] === $at && !in_array($move['from'], [$current, ...$out], true)) {
                $out[] = $move['from'];
                $at = $move['from'];
            }
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    private static function advisories(RegistryClient $client, string $type, string $slug, string $version): array
    {
        try {
            return $client->advisories()->affecting($type, $slug, $version);
        } catch (Throwable) {
            // advisories inform; they never decide whether a zip is the registry's
            return [];
        }
    }

    /** @return array{status: string, reason: ?string, version: null, organisation: null, organisation_name: null, moved_from: list<string>, key: null, trusted: array{}, advisories: list<array<string, mixed>>} */
    private static function verdict(string $status, ?string $reason = null): array
    {
        return ['status' => $status, 'reason' => $reason, 'version' => null, 'organisation' => null, 'organisation_name' => null, 'moved_from' => [], 'key' => null, 'trusted' => [], 'advisories' => []];
    }
}
