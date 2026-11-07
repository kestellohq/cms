<?php

declare(strict_types=1);

namespace Kestello\Cms\Import\WordPress;

use Kestello\Framework\Database\Model;
use Kestello\Framework\Database\QueryBuilder;

/**
 * What each WordPress item became on this site, per WordPress site. It
 * outlives single imports: importing a newer export of the same site finds
 * the entries, terms and files made last time and updates them instead of
 * making copies.
 *
 * A mapping row: target_type + target_id (what it became), checksum (of the
 * WordPress item as imported, to see whether it changed there) and
 * target_hash / target_version (of what was written here, to see whether
 * someone edited it here since).
 *
 * @internal
 */
final class SourceMap
{
    /** @var array<string, array<string, mixed>|false> */
    private array $cache = [];

    public function __construct(public readonly string $siteKey) {}

    /**
     * @return array{target_type: string, target_id: int, checksum: string, target_hash: ?string, target_version: ?int}|null
     */
    public function get(string $kind, string $key): ?array
    {
        $cacheKey = $kind . "\0" . $key;
        if (!array_key_exists($cacheKey, $this->cache)) {
            $row = QueryBuilder::table('import_sources')->where('site_key', $this->siteKey)->where('kind', $kind)->where('source_key', mb_substr($key, 0, 191))->first();
            $this->cache[$cacheKey] = $row === null ? false : (array) $row;
        }
        $row = $this->cache[$cacheKey];
        if ($row === false) {
            return null;
        }

        return [
            'target_type' => (string) $row['target_type'],
            'target_id' => (int) $row['target_id'],
            'checksum' => (string) $row['checksum'],
            'target_hash' => $row['target_hash'] === null ? null : (string) $row['target_hash'],
            'target_version' => $row['target_version'] === null ? null : (int) $row['target_version'],
        ];
    }

    /** target_type of something that was on this site before any import: used, never changed by one. */
    public const string FOUND = 'found';

    public function targetId(string $kind, string $key): ?int
    {
        return $this->get($kind, $key)['target_id'] ?? null;
    }

    public function put(string $kind, string $key, string $targetType, int $targetId, string $checksum, ?string $targetHash = null, ?int $targetVersion = null): void
    {
        $key = mb_substr($key, 0, 191);
        $now = Model::freshTimestamp();
        $row = ['target_type' => $targetType, 'target_id' => $targetId, 'checksum' => $checksum, 'target_hash' => $targetHash, 'target_version' => $targetVersion, 'updated_at' => $now];
        $updated = QueryBuilder::table('import_sources')->where('site_key', $this->siteKey)->where('kind', $kind)->where('source_key', $key)->update($row);
        if ($updated === 0 && !QueryBuilder::table('import_sources')->where('site_key', $this->siteKey)->where('kind', $kind)->where('source_key', $key)->exists()) {
            QueryBuilder::table('import_sources')->insert($row + ['site_key' => $this->siteKey, 'kind' => $kind, 'source_key' => $key, 'created_at' => $now]);
        }
        unset($this->cache[$kind . "\0" . $key]);
    }

    public function forget(string $kind, string $key): void
    {
        QueryBuilder::table('import_sources')->where('site_key', $this->siteKey)->where('kind', $kind)->where('source_key', mb_substr($key, 0, 191))->delete();
        unset($this->cache[$kind . "\0" . $key]);
    }

    /** A stable fingerprint of anything JSON can hold. */
    public static function checksum(mixed $value): string
    {
        return sha1(json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) ?: '');
    }
}
