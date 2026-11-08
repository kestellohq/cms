<?php

declare(strict_types=1);

namespace Kestello\Cms\Media;

use finfo;
use Kestello\Cms\Cms;
use Kestello\Contracts\Auth\Principal;
use Kestello\Contracts\Storage\DirectUploads;
use Kestello\Contracts\Storage\Disk;
use Kestello\Framework\Database\QueryBuilder;
use Kestello\Framework\Settings\Settings;
use Kestello\Support\Str;
use Kestello\Support\Translator;

/**
 * The media library: uploads, the files' details, and which entries use them.
 *
 * Every upload has three steps, whatever the size:
 *
 *   start()     declare name and size; the extension is checked before any bytes arrive
 *   chunk()     send the bytes in order, in chunk_size pieces (resumable), or POST them
 *               straight to the bucket with the presigned form (mode "direct")
 *   complete()  the content is sniffed and must agree with the extension, images are
 *               checked against the pixel limit, the "media.scan" filter can refuse it,
 *               then it is stored under a random key and becomes a Media row
 *
 * User input never chooses a storage path: keys are "Y/m/<random>/<slug>.<ext>".
 *
 * @api
 */
final class MediaLibrary
{
    public const int CHUNK_SIZE = 4_194_304;
    public const int CHUNKED_TTL = 86_400;
    public const int DIRECT_TTL = 3_600;
    public const int MAX_PENDING = 20;
    private const int SNIFF_BYTES = 262_144;

    public function __construct(private readonly Cms $cms) {}

    // ── policy ──────────────────────────────────────────────────────────

    public function types(): MediaTypes
    {
        if (\Kestello\Cms\Demo\Demo::on($this->cms->config)) {
            return new MediaTypes(['image'], false);    // a public demo hosts no one's documents, and no SVG
        }
        $s = Settings::getInstance();
        $kinds = ['image'];
        foreach (['document' => true, 'audio' => true, 'video' => true, 'archive' => false] as $kind => $default) {
            if (filter_var($s->get("media_allow_{$kind}", $default ? '1' : '0'), FILTER_VALIDATE_BOOLEAN)) {
                $kinds[] = $kind;
            }
        }

        return new MediaTypes($kinds, filter_var($s->get('media_allow_svg', '0'), FILTER_VALIDATE_BOOLEAN));
    }

    public function maxBytes(): int
    {
        $max = max(1, min(5120, (int) Settings::getInstance()->get('media_max_upload_mb', 64))) * 1_048_576;

        return \Kestello\Cms\Demo\Demo::on($this->cms->config) ? min($max, \Kestello\Cms\Demo\Demo::MAX_UPLOAD_BYTES) : $max;
    }

    public function maxPixels(): int
    {
        return max(1, min(500, (int) Settings::getInstance()->get('media_max_megapixels', 50))) * 1_000_000;
    }

    /** The disk new uploads go to (Settings → File storage). */
    public function diskName(): string
    {
        return $this->cms->container->make(MediaStorage::class)->current();
    }

    private function disk(): Disk
    {
        return $this->cms->storage->disk($this->diskName());
    }

    /**
     * The disk an upload was started on. The library may have moved to
     * another since (an upload can stay open for a day): the file goes
     * where the row says it is.
     *
     * @param array<string, mixed> $row
     */
    private function uploadDisk(array $row): Disk
    {
        return $this->cms->storage->disk((string) $row['disk']);
    }

    private function directDisk(?Disk $disk = null): ?DirectUploads
    {
        $disk ??= $this->disk();

        return $disk instanceof DirectUploads && $disk->supportsDirectUploads() ? $disk : null;
    }

    // ── uploads ─────────────────────────────────────────────────────────

    /**
     * Begin an upload.
     *
     * @return array<string, mixed> the upload (see describeUpload())
     * @throws MediaException
     */
    public function start(Principal $p, string $filename, int $size): array
    {
        $direct = $this->directDisk();
        $row = $this->reserving($p, function () use ($p, $filename, $size, $direct): array {
            $filename = $this->check($p, $filename, $size);
            $pending = QueryBuilder::table('uploads')->where('user_id', $p->userId)->where('expires_at', '>', gmdate('Y-m-d H:i:s'))->count();
            if ($pending >= self::MAX_PENDING) {
                throw MediaException::conflict('Too many unfinished uploads. Finish or cancel some first.');
            }
            $row = $this->newRow($p, $filename, $size, $direct !== null ? 'direct' : 'chunked');
            QueryBuilder::table('uploads')->insert($row);

            return $row;
        });

        return $this->describeUpload($row, $direct);
    }

    /** Seconds an upload waits for another of the same person's to be counted (tests shorten it). */
    public static int $quotaLockWait = 10;

    /**
     * Run $work (check the quota, then reserve the room with an `uploads` row)
     * as one step per person: two uploads started at once would otherwise both
     * pass the check before either is counted.
     *
     * @template T
     * @param \Closure(): T $work
     * @return T
     * @throws MediaException
     */
    private function reserving(Principal $p, \Closure $work): mixed
    {
        $c = $this->cms->container;
        if ($p->userId === null || !$c->has(\Kestello\Contracts\Cache\Store::class)) {
            return $work();
        }
        try {
            return $c->make(\Kestello\Contracts\Cache\Store::class)->lock('media-quota:' . $p->userId, 30)->block(self::$quotaLockWait, $work);
        } catch (\Kestello\Contracts\Cache\LockTimeout) {
            throw MediaException::conflict('Another upload is starting. Try again in a moment.');
        }
    }

    /**
     * Add a file that is already on this server (an import's download) to the
     * library, with the same checks, processing and audit as an upload. The
     * file at $local is moved away: it is the library's from then on.
     *
     * @param array{title?: ?string, alt?: ?string, caption?: ?string} $details
     * @throws MediaException
     */
    public function addFile(Principal $p, string $local, string $filename, array $details = []): Media
    {
        clearstatcache(true, $local);
        $size = is_file($local) ? (int) filesize($local) : 0;
        $row = $this->reserving($p, function () use ($p, $local, $filename, $size): array {
            $filename = $this->check($p, $filename, $size);
            $row = $this->newRow($p, $filename, $size, 'chunked');
            $row['received'] = max(1, (int) ceil($size / self::CHUNK_SIZE));
            if (!@rename($local, $this->tempPath((string) $row['id'])) && !(@copy($local, $this->tempPath((string) $row['id'])) && @unlink($local))) {
                throw new \RuntimeException('Could not move the file into the media library.');
            }
            QueryBuilder::table('uploads')->insert($row);

            return $row;
        });

        return $this->complete($row, $p, $details);
    }

    /**
     * Whether $p may add a file of this name and size; the cleaned name.
     *
     * @throws MediaException
     */
    private function check(Principal $p, string $filename, int $size): string
    {
        if (!$p->can('media.upload') || $p->userId === null) {
            throw MediaException::forbidden("You can't upload files.");
        }
        $filename = self::cleanName($filename);
        $errors = [];
        if (($e = $this->types()->checkName($filename)) !== []) {
            $errors['filename'] = $e;
        }
        if ($size < 1) {
            $errors['size'][] = Translator::get('The file is empty.');
        } elseif ($size > $this->maxBytes()) {
            $errors['size'][] = Translator::get('The file is larger than {max} MB, the most this site takes.', ['max' => intdiv($this->maxBytes(), 1_048_576)]);
        }
        if ($errors === [] && ($over = $this->cms->container->make(MediaStorage::class)->checkQuota($p, $size)) !== null) {
            $errors['size'][] = $over;
        }
        if ($errors !== []) {
            throw MediaException::invalid($errors);
        }

        return $filename;
    }

    /** @return array<string, mixed> a new `uploads` row */
    private function newRow(Principal $p, string $filename, int $size, string $mode): array
    {
        $ext = MediaTypes::extension($filename);

        return [
            'id' => bin2hex(random_bytes(16)),
            'user_id' => $p->userId,
            'mode' => $mode,
            'disk' => $this->diskName(),
            'path' => self::newKey($filename, $ext),
            'filename' => $filename,
            'ext' => $ext,
            'mime' => $this->types()->forExtension($ext)['mime'],
            'size' => $size,
            'chunk_size' => self::CHUNK_SIZE,
            'received' => 0,
            'expires_at' => gmdate('Y-m-d H:i:s', time() + ($mode === 'direct' ? self::DIRECT_TTL : self::CHUNKED_TTL)),
            'created_at' => gmdate('Y-m-d H:i:s'),
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ];
    }

    /**
     * An upload in progress, for its owner only (null otherwise, or once expired).
     *
     * @return array<string, mixed>|null
     */
    public function upload(string $id, Principal $p): ?array
    {
        if (preg_match('/^[a-f0-9]{32}$/', $id) !== 1 || $p->userId === null) {
            return null;
        }
        $row = QueryBuilder::table('uploads')->where('id', $id)->where('user_id', $p->userId)->first();
        if ($row === null) {
            return null;
        }
        $row = (array) $row;

        return strtotime($row['expires_at'] . ' UTC') < time() ? null : $row;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public function describeUpload(array $row, ?DirectUploads $direct = null): array
    {
        $chunks = max(1, (int) ceil((int) $row['size'] / (int) $row['chunk_size']));
        $out = [
            'id' => $row['id'],
            'mode' => $row['mode'],
            'filename' => $row['filename'],
            'size' => (int) $row['size'],
            'chunk_size' => (int) $row['chunk_size'],
            'chunks' => $chunks,
            'received' => (int) $row['received'],
            'expires_at' => gmdate('c', (int) strtotime($row['expires_at'] . ' UTC')),
        ];
        if ($row['mode'] === 'direct') {
            $direct ??= $this->directDisk($this->uploadDisk($row));
            if ($direct !== null) {
                $out['direct'] = $direct->presignedPost((string) $row['path'], max(60, (int) strtotime($row['expires_at'] . ' UTC') - time()), (int) $row['size'], (string) $row['mime']);
            }
        }

        return $out;
    }

    /**
     * Store chunk $index (0-based). Chunks arrive in order; re-sending the
     * last one received is accepted (a retry after a lost response).
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed> the upload
     * @throws MediaException
     */
    public function chunk(array $row, int $index, string $bytes): array
    {
        if ($row['mode'] !== 'chunked') {
            throw MediaException::conflict('This upload goes straight to storage.');
        }
        $chunks = max(1, (int) ceil((int) $row['size'] / (int) $row['chunk_size']));
        $received = (int) $row['received'];
        if ($index < 0 || $index >= $chunks || $index > $received || $index < $received - 1) {
            throw MediaException::conflict("Send chunk {$received} next.");
        }
        $expected = $index === $chunks - 1 ? (int) $row['size'] - $index * (int) $row['chunk_size'] : (int) $row['chunk_size'];
        if (strlen($bytes) !== $expected) {
            throw MediaException::invalid(['chunk' => ["Chunk {$index} must be {$expected} bytes."]]);
        }
        $path = $this->tempPath((string) $row['id']);
        $fh = fopen($path, 'c');
        if ($fh === false || fseek($fh, $index * (int) $row['chunk_size']) !== 0 || fwrite($fh, $bytes) !== strlen($bytes)) {
            throw new \RuntimeException('Could not write the upload chunk.');
        }
        fclose($fh);
        $row['received'] = max($received, $index + 1);
        $row['expires_at'] = gmdate('Y-m-d H:i:s', time() + self::CHUNKED_TTL);
        QueryBuilder::table('uploads')->where('id', $row['id'])->update(['received' => $row['received'], 'expires_at' => $row['expires_at'], 'updated_at' => gmdate('Y-m-d H:i:s')]);

        return $this->describeUpload($row);
    }

    /**
     * Check the uploaded bytes and add the file to the library.
     *
     * @param array<string, mixed> $row
     * @param array{title?: ?string, alt?: ?string, caption?: ?string} $details
     * @throws MediaException
     */
    public function complete(array $row, Principal $p, array $details = []): Media
    {
        // Claim the upload first: a second "complete" for it (a client retrying a slow
        // request) finds it taken instead of making a twin that shares the file.
        $claimed = QueryBuilder::table('uploads')->where('id', $row['id'])->where('received', (int) $row['received'])->update(['received' => -1]);
        if ($claimed !== 1) {
            throw MediaException::conflict('This upload is already being finished.');
        }
        try {
            return $this->finish($row, $p, $details);
        } catch (\Throwable $e) {
            // not finished: give it back, so it can be tried again (a forgotten upload stays gone)
            QueryBuilder::table('uploads')->where('id', $row['id'])->where('received', -1)->update(['received' => (int) $row['received']]);
            throw $e;
        }
    }

    /**
     * @param array<string, mixed> $row the claimed upload
     * @param array{title?: ?string, alt?: ?string, caption?: ?string} $details
     * @throws MediaException
     */
    private function finish(array $row, Principal $p, array $details): Media
    {
        $meta = null;
        try {
            if ($row['mode'] === 'chunked') {
                $chunks = max(1, (int) ceil((int) $row['size'] / (int) $row['chunk_size']));
                $tmp = $this->tempPath((string) $row['id']);
                clearstatcache(true, $tmp);
                if ((int) $row['received'] !== $chunks || !is_file($tmp) || filesize($tmp) !== (int) $row['size']) {
                    throw MediaException::conflict('The upload is not complete yet.');
                }
                $head = (string) file_get_contents($tmp, length: self::SNIFF_BYTES);
                [$width, $height] = $this->inspect($row, $head, $tmp);
                $prepared = $this->prepareImage($row, $tmp);
                if ($prepared !== null) {
                    [$width, $height, $meta] = [$prepared['width'], $prepared['height'], $prepared['meta']];
                }
                clearstatcache(true, $tmp);
                $storedSize = (int) filesize($tmp);
                $stream = fopen($tmp, 'rb');
                $this->uploadDisk($row)->putStream((string) $row['path'], $stream, ['content_type' => $row['mime'], 'visibility' => 'public', 'cache_control' => 'public, max-age=31536000, immutable']);
                is_resource($stream) && fclose($stream);
                $checksum = hash_file('sha256', $tmp) ?: null;
            } else {
                $disk = $this->directDisk($this->uploadDisk($row)) ?? throw MediaException::conflict('Direct uploads are no longer set up for this site.');
                try {
                    $actual = $this->uploadDisk($row)->size((string) $row['path']);
                } catch (\Kestello\Contracts\Storage\FileNotFound) {
                    throw MediaException::conflict('The file has not arrived in storage yet.');
                }
                if ($actual !== (int) $row['size']) {
                    $this->uploadDisk($row)->delete((string) $row['path']);
                    throw MediaException::invalid(['size' => ['The stored file has a different size than announced.']]);
                }
                $head = $disk->readRange((string) $row['path'], 0, self::SNIFF_BYTES);
                try {
                    [$width, $height] = $this->inspect($row, $head, null);
                } catch (MediaException $e) {
                    $this->uploadDisk($row)->delete((string) $row['path']);
                    throw $e;
                }
                $checksum = null;
                $storedSize = (int) $row['size'];
                if (($this->types()->forExtension((string) $row['ext'])['kind'] ?? null) === 'image') {
                    // images come back through PHP once: orientation, metadata, statistics
                    $tmp = $this->tempPath((string) $row['id']);
                    $in = $this->uploadDisk($row)->readStream((string) $row['path']);
                    $out = fopen($tmp, 'wb');
                    stream_copy_to_stream($in, $out);
                    is_resource($in) && fclose($in);
                    fclose($out);
                    $prepared = $this->prepareImage($row, $tmp);
                    if ($prepared !== null) {
                        [$width, $height, $meta] = [$prepared['width'], $prepared['height'], $prepared['meta']];
                        if ($prepared['changed']) {
                            $stream = fopen($tmp, 'rb');
                            $this->uploadDisk($row)->putStream((string) $row['path'], $stream, ['content_type' => $row['mime'], 'visibility' => 'public', 'cache_control' => 'public, max-age=31536000, immutable']);
                            is_resource($stream) && fclose($stream);
                        }
                    }
                    clearstatcache(true, $tmp);
                    $storedSize = (int) filesize($tmp);
                    $checksum = hash_file('sha256', $tmp) ?: null;
                    @unlink($tmp);
                }
            }
        } catch (MediaException $e) {
            if ($e->kind === 'invalid') {
                $this->forgetUpload($row, deleteDirect: false);
            }
            throw $e;
        }

        $media = new Media();
        $media->forceFill([
            'disk' => $row['disk'],
            'path' => $row['path'],
            'filename' => $row['filename'],
            'title' => self::title($details['title'] ?? null, (string) $row['filename']),
            'alt' => self::text($details['alt'] ?? null, 1000),
            'caption' => self::text($details['caption'] ?? null, 2000),
            'mime' => $row['mime'],
            'ext' => $row['ext'],
            'kind' => $this->types()->forExtension((string) $row['ext'])['kind'] ?? 'document',
            'size' => $storedSize,
            'width' => $width,
            'height' => $height,
            'checksum' => $checksum,
            'uploaded_by' => $p->userId,
            'meta' => $meta,
        ]);
        $media->save();
        if ($media->ext === 'pdf') {
            $this->makePoster($media, $row['mode'] === 'chunked' ? $this->tempPath((string) $row['id']) : null);
        }
        $this->forgetUpload($row, deleteDirect: false);
        $this->queueVariants($media, now: ['thumb']);
        $this->cms->audit->record('media.uploaded', ['media', (int) $media->getKey()], ['filename' => $media->filename, 'size' => $media->size], $p->userId);

        return $media;
    }

    /**
     * Store a PDF's first page as a picture (PdfPoster), so it gets a
     * thumbnail and sizes like an image. Also used by `media:variants` for
     * PDFs uploaded before. False when it couldn't be made.
     *
     * @param string|null $local the PDF on this server, when at hand
     */
    public function makePoster(Media $media, ?string $local = null): bool
    {
        $engine = $this->variants()->engine();
        if ($media->ext !== 'pdf' || $engine === null || !$engine->reads('image/png') || PdfPoster::binary() === null
            || !filter_var(Settings::getInstance()->get('media_pdf_thumbnails', '1'), FILTER_VALIDATE_BOOLEAN)) {
            return false;
        }
        $dir = $this->cms->storage->root() . '/uploads-tmp';
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            return false;
        }
        $work = $dir . '/poster-' . bin2hex(random_bytes(8));
        $pdf = $local;
        try {
            $disk = $this->cms->storage->disk($media->disk);
            if ($pdf === null || !is_file($pdf)) {
                if ((int) $media->size > 200 * 1_048_576) {
                    return false;                 // not worth fetching a huge file back
                }
                $pdf = $work . '.pdf';
                $in = $disk->readStream($media->path);
                $out = fopen($pdf, 'wb');
                stream_copy_to_stream($in, $out);
                is_resource($in) && fclose($in);
                fclose($out);
            }
            $page = PdfPoster::render($pdf, $work . '.png');
            if ($page === null) {
                return false;
            }
            Image\ImageProcessor::reserveMemory($page['width'], $page['height']);
            $img = $engine->open($work . '.png');
            $w = $img->width();
            $h = $img->height();
            $scale = min(1.0, 1600 / max($w, $h));
            if ($scale < 1.0) {
                $img->resize(max(1, (int) round($w * $scale)), max(1, (int) round($h * $scale)));
                [$w, $h] = [max(1, (int) round($w * $scale)), max(1, (int) round($h * $scale))];
            }
            $bytes = $img->encode('jpeg', 85);
            $img->destroy();
            $key = PdfPoster::key($media->path);
            $disk->put($key, $bytes, ['content_type' => 'image/jpeg', 'visibility' => 'public', 'cache_control' => 'public, max-age=31536000, immutable']);
            $meta = $media->meta ?? [];
            $meta['poster'] = ['path' => $key, 'width' => $w, 'height' => $h];
            $media->meta = $meta;
            $media->save();
            $this->queueVariants($media, now: ['thumb']);

            return true;
        } catch (\Throwable $e) {
            error_log('[media] first page of #' . $media->getKey() . ' failed: ' . $e->getMessage());

            return false;
        } finally {
            @unlink($work . '.png');
            if ($pdf !== $local) {
                @unlink($work . '.pdf');
            }
        }
    }

    /** @param array<string, mixed> $row */
    public function abort(array $row): void
    {
        $this->forgetUpload($row, deleteDirect: true);
    }

    /** Drop uploads nobody finished. Returns how many. */
    public function purgeExpired(): int
    {
        $rows = QueryBuilder::table('uploads')->where('expires_at', '<', gmdate('Y-m-d H:i:s'))->limit(500)->get();
        foreach ($rows as $row) {
            $this->forgetUpload((array) $row, deleteDirect: true);
        }

        return count($rows);
    }

    /** @param array<string, mixed> $row */
    private function forgetUpload(array $row, bool $deleteDirect): void
    {
        @unlink($this->tempPath((string) $row['id']));
        if ($deleteDirect && $row['mode'] === 'direct' && !QueryBuilder::table('media')->where('disk', $row['disk'])->where('path', $row['path'])->exists()) {
            try {
                $this->cms->storage->disk((string) $row['disk'])->delete((string) $row['path']);
            } catch (\Throwable) {
                // the disk may be gone or unreachable; the row still goes
            }
        }
        QueryBuilder::table('uploads')->where('id', $row['id'])->delete();
    }

    /**
     * Orient, strip and measure an image in place (ImageProcessor).
     *
     * @param array<string, mixed> $row
     * @return array{width: int, height: int, changed: bool, meta: array<string, mixed>}|null
     * @throws MediaException
     */
    private function prepareImage(array $row, string $path): ?array
    {
        if (($this->types()->forExtension((string) $row['ext'])['kind'] ?? null) !== 'image') {
            return null;
        }
        if ($row['ext'] === 'svg') {
            $clean = \Kestello\Support\Svg::sanitize((string) file_get_contents($path), 5 * 1_048_576);
            if ($clean === '') {
                throw MediaException::invalid(['file' => ['This SVG could not be read safely. Files with a DOCTYPE or entities are refused.']]);
            }
            file_put_contents($path, '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . $clean);
            $size = \Kestello\Support\Svg::dimensions($clean);

            return ['width' => $size['width'], 'height' => $size['height'], 'changed' => true, 'meta' => ['vector' => true, 'sanitized' => true]];
        }
        try {
            return $this->variants()->processor()->prepare($path, (string) $row['mime']);
        } catch (\Kestello\Contracts\Media\ImageException) {
            throw MediaException::invalid(['file' => ['This image could not be decoded.']]);
        }
    }

    private function variants(): MediaVariants
    {
        return $this->cms->container->make(MediaVariants::class);
    }

    /**
     * Make $now right away (the admin grid's thumbnails), queue the rest.
     * Without a worker (sync queue) the rest is made on first request.
     *
     * @param list<string> $now
     */
    public function queueVariants(Media $media, array $now = []): void
    {
        $variants = $this->variants();
        if (!$variants->eligible($media)) {
            return;
        }
        try {
            $variants->prune($media);
            if ($now !== []) {
                $variants->generate($media, $now);
            }
            if (!$this->cms->queue->backend() instanceof \Kestello\Framework\Queue\SyncQueue) {
                $this->cms->queue->push(new GenerateVariants((int) $media->getKey()), queue: 'media');
            }
        } catch (\Throwable $e) {
            error_log('[media] variants for #' . $media->getKey() . ' failed: ' . $e->getMessage()); // made on demand instead
        }
    }

    private function tempPath(string $id): string
    {
        $dir = $this->cms->storage->root() . '/uploads-tmp';
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new \RuntimeException('Could not create the upload directory.');
        }

        return $dir . '/' . $id . '.part';
    }

    /**
     * The content must be what the extension says; images must decode and
     * stay under the pixel limit; the "media.scan" filter can refuse.
     *
     * @param array<string, mixed> $row
     * @return array{0: ?int, 1: ?int} width, height
     * @throws MediaException
     */
    private function inspect(array $row, string $head, ?string $localPath): array
    {
        $ext = (string) $row['ext'];
        $sniffed = (string) (new finfo(FILEINFO_MIME_TYPE))->buffer($head);
        $types = $this->types();
        if (!$types->agrees($ext, $sniffed)) {
            throw MediaException::invalid(['file' => ["The file's content ({$sniffed}) doesn't match its .{$ext} name."]]);
        }
        $width = $height = null;
        if ($ext === 'svg' && (int) $row['size'] > 5 * 1_048_576) {
            throw MediaException::invalid(['file' => ['SVG images can be at most 5 MB.']]);
        }
        if (($types->forExtension($ext)['kind'] ?? null) === 'image' && $ext !== 'svg') {
            $info = $localPath !== null ? @getimagesize($localPath) : @getimagesizefromstring($head);
            if ($info === false || ($info[0] ?? 0) < 1 || ($info[1] ?? 0) < 1) {
                throw MediaException::invalid(['file' => ['This image could not be read.']]);
            }
            if (!$types->agrees($ext, (string) ($info['mime'] ?? ''))) {
                throw MediaException::invalid(['file' => ["The image's format doesn't match its .{$ext} name."]]);
            }
            [$width, $height] = [(int) $info[0], (int) $info[1]];
            if ($width * $height > $this->maxPixels()) {
                throw MediaException::invalid(['file' => ["The image is {$width} × {$height} pixels; the limit is " . intdiv($this->maxPixels(), 1_000_000) . ' megapixels.']]);
            }
        }
        $verdict = $this->cms->filters->apply('media.scan', null, [
            'filename' => $row['filename'], 'mime' => $row['mime'], 'size' => (int) $row['size'],
            'path' => $localPath, 'disk' => $row['disk'], 'key' => $row['path'],
        ]);
        if (is_string($verdict) && $verdict !== '') {
            throw MediaException::invalid(['file' => [$verdict]]);
        }

        return [$width, $height];
    }

    // ── the library ─────────────────────────────────────────────────────

    public function canEdit(Media $media, Principal $p): bool
    {
        return $p->can('media.manage') || ($p->can('media.upload') && $p->userId !== null && $media->uploaded_by === $p->userId);
    }

    /**
     * @param array{title?: ?string, alt?: ?string, caption?: ?string} $input
     * @throws MediaException
     */
    public function update(Media $media, Principal $p, array $input): Media
    {
        if (!$this->canEdit($media, $p)) {
            throw MediaException::forbidden('Only the uploader or an editor can change this file.');
        }
        $changes = [];
        if (array_key_exists('title', $input)) {
            $changes['title'] = self::title($input['title'], $media->filename);
        }
        foreach (['alt' => 1000, 'caption' => 2000] as $key => $max) {
            if (array_key_exists($key, $input)) {
                $changes[$key] = self::text($input[$key], $max);
            }
        }
        $refocused = false;
        if (array_key_exists('focus', $input) && $media->kind === 'image') {
            $f = $input['focus'];
            if (!is_array($f) || count($f) !== 2 || !is_numeric($f[0] ?? null) || !is_numeric($f[1] ?? null)) {
                throw MediaException::invalid(['focus' => ['The focal point is two numbers from 0 to 1.']]);
            }
            $focus = [round(max(0.0, min(1.0, (float) $f[0])), 4), round(max(0.0, min(1.0, (float) $f[1])), 4)];
            $meta = $media->meta ?? [];
            if (($meta['focus'] ?? [0.5, 0.5]) != $focus) {
                $meta['focus'] = $focus;
                $changes['meta'] = $meta;
                $refocused = true;
            }
        }
        if ($changes !== []) {
            $media->forceFill($changes)->save();
            $this->cms->audit->record('media.updated', ['media', (int) $media->getKey()], ['fields' => array_map(static fn($k) => $k === 'meta' ? 'focus' : $k, array_keys($changes))], $p->userId);
        }
        if ($refocused) {
            $this->queueVariants($media, now: ['thumb']);
        }

        return $media;
    }

    /**
     * Delete the file and its row. Files entries still use need $force.
     *
     * @throws MediaException
     */
    public function delete(Media $media, Principal $p, bool $force = false): void
    {
        if (!$this->canEdit($media, $p)) {
            throw MediaException::forbidden('Only the uploader or an editor can delete this file.');
        }
        $used = QueryBuilder::table('media_usage')->where('media_id', $media->getKey())->count();
        if ($used > 0 && !$force) {
            throw MediaException::conflict("This file is used by {$used} " . ($used === 1 ? 'entry' : 'entries') . '.');
        }
        $this->variants()->deleteAll($media);
        $original = $media->meta['original']['path'] ?? null;      // an edited image keeps its original too
        foreach (array_filter([$media->path, is_string($original) ? $original : null]) as $path) {
            try {
                $this->cms->storage->disk($media->disk)->delete($path);
            } catch (\Kestello\Contracts\Storage\FileNotFound) {
                // already gone
            }
        }
        $id = (int) $media->getKey();
        $media->delete();
        $this->cms->audit->record('media.deleted', ['media', $id], ['filename' => $media->filename, 'was_used' => $used], $p->userId);
    }

    /**
     * Entries that use each file.
     *
     * @param list<int> $ids
     * @return array<int, list<array{id: int, type: string, title: string, status: string, field: string, author_id: ?int}>>
     */
    public function usage(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $rows = QueryBuilder::table('media_usage')->join('entries', 'entries.id', '=', 'media_usage.entry_id')
            ->whereIn('media_usage.media_id', $ids)
            ->select('media_usage.media_id', 'media_usage.field', 'entries.id', 'entries.type', 'entries.title', 'entries.status', 'entries.deleted_at', 'entries.author_id')
            ->orderBy('entries.title')->get();
        $out = [];
        foreach ($rows as $r) {
            $r = (array) $r;
            $out[(int) $r['media_id']][] = ['id' => (int) $r['id'], 'type' => (string) $r['type'], 'title' => (string) $r['title'], 'status' => $r['deleted_at'] !== null ? 'trash' : (string) $r['status'], 'field' => (string) $r['field'],
                'author_id' => $r['author_id'] === null ? null : (int) $r['author_id']];
        }

        return $out;
    }

    /**
     * The entries of usage() this person may open, and how many others use the file.
     * Someone who may upload but not edit others' entries never learns their titles.
     *
     * @param list<array{id: int, type: string, title: string, status: string, field: string, author_id: ?int}> $rows
     * @return array{0: list<array{id: int, type: string, title: string, status: string, field: string}>, 1: int}
     */
    public function visibleUsage(array $rows, Principal $p): array
    {
        $shown = [];
        $hidden = 0;
        foreach ($rows as $r) {
            $type = $this->cms->content->get($r['type']);
            $own = $r['author_id'] !== null && $r['author_id'] === $p->userId;
            if ($type !== null && $p->can($type->capability('edit')) && ($own || $p->can($type->capability('edit_others')))) {
                unset($r['author_id']);
                $shown[] = $r;
            } else {
                $hidden++;
            }
        }

        return [$shown, $hidden];
    }

    /**
     * Record which files an entry uses (called on every save).
     *
     * @param array<string, list<int>> $byField
     */
    public static function syncUsage(int $entryId, array $byField): void
    {
        QueryBuilder::table('media_usage')->where('entry_id', $entryId)->delete();
        $rows = [];
        foreach ($byField as $field => $ids) {
            foreach (array_unique($ids) as $id) {
                $rows[] = ['media_id' => (int) $id, 'entry_id' => $entryId, 'field' => mb_substr((string) $field, 0, 100)];
            }
        }
        if ($rows !== []) {
            $existing = QueryBuilder::table('media')->whereIn('id', array_unique(array_column($rows, 'media_id')))->pluck('id');
            $rows = array_values(array_filter($rows, static fn(array $r) => in_array($r['media_id'], array_map('intval', $existing), true)));
            $rows !== [] && QueryBuilder::table('media_usage')->insertMany($rows);
        }
    }

    // ── helpers ─────────────────────────────────────────────────────────

    /** The name shown and offered for download: no path, no control characters, at most 191 bytes. */
    public static function cleanName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = trim((string) preg_replace('/[\x00-\x1F\x7F"<>:|?*]+/u', '', $name), " .\t");
        if (mb_strlen($name) > 150) {
            $ext = MediaTypes::extension($name);
            $name = rtrim(mb_substr($name, 0, 140), ' .') . ($ext !== '' ? '.' . $ext : '');
        }

        return $name;
    }

    private static function newKey(string $filename, string $ext): string
    {
        $stem = str_contains($filename, '.') ? substr($filename, 0, (int) strrpos($filename, '.')) : $filename;
        $slug = mb_substr(Str::slug($stem), 0, 80) ?: 'file';

        return gmdate('Y/m') . '/' . bin2hex(random_bytes(10)) . '/' . trim($slug, '-') . '.' . $ext;
    }

    private static function title(?string $title, string $filename): string
    {
        $title = trim((string) $title);
        if ($title === '') {
            $stem = str_contains($filename, '.') ? substr($filename, 0, (int) strrpos($filename, '.')) : $filename;
            $title = trim((string) preg_replace('/[\s_-]+/', ' ', $stem));
        }

        return mb_substr($title !== '' ? $title : 'Untitled', 0, 191);
    }

    private static function text(?string $value, int $max): ?string
    {
        $value = trim((string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', (string) $value));

        return $value === '' ? null : mb_substr($value, 0, $max);
    }
}
