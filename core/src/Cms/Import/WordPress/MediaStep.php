<?php

declare(strict_types=1);

namespace Kestello\Cms\Import\WordPress;

use Kestello\Cms\Import\ImportException;
use Kestello\Cms\Media\Media;
use Kestello\Cms\Media\MediaLibrary;

/**
 * A file: an attachment from the export, or an image from the old uploads
 * folder that content shows but the export does not list. Downloaded once;
 * a later import only updates its title, alt text and caption.
 *
 * @internal
 */
final class MediaStep
{
    public function __construct(private readonly Run $run, private readonly Downloader $downloader) {}

    /**
     * @param array<string, mixed> $item
     * @param array<string, mixed> $p
     */
    public function process(array $item, array $p): Outcome
    {
        if ($this->run->option('media', true) === false) {
            return Outcome::skipped('Files were not downloaded: turned off for this import.');
        }
        $kind = (string) $item['kind'];
        $key = (string) $item['source_key'];
        $details = [
            'title' => trim((string) ($p['title'] ?? '')) !== '' ? trim(html_entity_decode(strip_tags((string) $p['title']), ENT_QUOTES | ENT_HTML5, 'UTF-8')) : null,
            'alt' => trim((string) ($p['alt'] ?? '')) !== '' ? (string) $p['alt'] : null,
            'caption' => trim((string) ($p['caption'] ?? '')) !== '' ? (string) $p['caption'] : null,
        ];
        $checksum = SourceMap::checksum([$p['url'], $details]);
        $library = $this->run->cms->container->make(MediaLibrary::class);

        $mapped = $this->run->map->get($kind, $key);
        if ($mapped !== null) {
            $media = Media::query()->find($mapped['target_id']);
            if (!$media instanceof Media) {
                return Outcome::skipped('Deleted on this site after the last import; not brought back.');
            }
            if ($mapped['checksum'] === $checksum) {
                return Outcome::unchanged((int) $media->getKey());
            }
            if ($mapped['target_hash'] !== null && $mapped['target_hash'] !== self::hash($media)) {
                return Outcome::kept((int) $media->getKey(), 'Changed on this site since the last import; left as it is.');
            }
            $media = $library->update($media, $this->run->principal, array_filter($details, static fn($v) => $v !== null));
            $this->run->map->put($kind, $key, 'media', (int) $media->getKey(), $checksum, self::hash($media));

            return Outcome::updated((int) $media->getKey());
        }

        $urls = $kind === 'file' ? [(string) $p['original'], (string) $p['url']] : [(string) $p['url']];
        try {
            $tmp = $this->downloader->fetch($urls);
        } catch (ImportException $e) {
            return Outcome::failed($e->getMessage());
        }
        try {
            $filename = (string) ($p['filename'] ?? '') !== '' ? (string) $p['filename'] : basename($tmp);
            $media = $library->addFile($this->run->principal, $tmp, $filename, $details);
        } finally {
            @unlink($tmp);
        }
        $this->run->map->put($kind, $key, 'media', (int) $media->getKey(), $checksum, self::hash($media));

        return Outcome::created((int) $media->getKey());
    }

    private static function hash(Media $media): string
    {
        return SourceMap::checksum([$media->title, $media->alt, $media->caption]);
    }
}
