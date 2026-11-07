<?php

declare(strict_types=1);

namespace Kestello\Cms\Media;

use Kestello\Cms\Cms;
use Kestello\Cms\Media\Image\ImageEdit;
use Kestello\Cms\Media\Image\ImageProcessor;
use Kestello\Contracts\Auth\Principal;
use Kestello\Contracts\Media\ImageException;
use Kestello\Contracts\Media\TransformableImage;
use Kestello\Contracts\Storage\Disk;
use Kestello\Contracts\Storage\FileNotFound;

/**
 * Crop, turn and flip images in the media library.
 *
 * The original file is never changed. Every edit is made again from it
 * (so repeated edits don't lose quality) and saved as a new file beside
 * it, which the row then points to: a new address, so no cache can serve
 * the old picture.
 * meta.original remembers the original, meta.edit the settings, so the
 * editor reopens where it was left and restore() goes back.
 *
 * While an image is edited its original is private: a crop often cuts
 * something out that shouldn't stay on the web.
 */
final class MediaEditor
{
    public function __construct(private readonly Cms $cms) {}

    /** Why this file can't be edited here, or null when it can. */
    public function refusal(Media $m): ?string
    {
        if ($m->kind !== 'image') {
            return 'Only images can be edited.';
        }
        if (!empty($m->meta['vector'])) {
            return 'Drawings (SVG) are edited in a drawing program, then uploaded again.';
        }
        if (!empty($m->meta['animated'])) {
            return 'Animated images can\'t be edited here: their frames would be lost.';
        }
        $format = ImageProcessor::format($m->mime);
        $engine = $this->variants()->engine();
        if ($format === null || $engine === null || !$engine->reads($m->mime) || !$engine->writes($format)) {
            return 'The image engine on this server can\'t edit ' . strtoupper($m->ext) . ' files.';
        }

        return null;
    }

    /** The edit applied now, or null. */
    public static function current(Media $m): ?ImageEdit
    {
        $edit = $m->meta['edit'] ?? null;

        return is_array($edit) && isset($m->meta['original']) ? ImageEdit::fromInput($edit) : null;
    }

    /**
     * Apply an edit to the original. An edit that changes nothing restores it.
     *
     * @param array<string, mixed> $input see ImageEdit::fromInput()
     * @throws MediaException
     */
    public function edit(Media $m, Principal $p, array $input): Media
    {
        $this->authorize($m, $p);
        if (($why = $this->refusal($m)) !== null) {
            throw MediaException::invalid(['image' => [$why]], $why);
        }
        $edit = ImageEdit::fromInput($input);
        if ($edit->isIdentity()) {
            return isset($m->meta['original']) ? $this->restore($m, $p) : $m;
        }
        $meta = $m->meta ?? [];
        $original = is_array($meta['original'] ?? null) ? $meta['original'] : [
            'path' => $m->path, 'width' => (int) $m->width, 'height' => (int) $m->height, 'size' => (int) $m->size, 'checksum' => $m->checksum,
            'alpha' => $meta['alpha'] ?? null, 'color' => $meta['color'] ?? null, 'placeholder' => $meta['placeholder'] ?? null,
        ];
        $focus = MediaVariants::focus($m);
        $focus = self::current($m)?->unmapPoint($focus) ?? $focus;   // the focal point, in the original

        $disk = $this->cms->storage->disk($m->disk);
        [$bytes, $stats] = $this->render($disk, (string) $original['path'], (int) $original['width'], (int) $original['height'], $m->mime, $edit);
        $key = self::editKey((string) $original['path']);
        $disk->put($key, $bytes, ['content_type' => $m->mime, 'visibility' => 'public', 'cache_control' => 'public, max-age=31536000, immutable']);
        $previous = isset($meta['original']) ? $m->path : null;

        $mapped = $edit->mapPoint($focus);
        $meta = ['original' => $original, 'edit' => $edit->toArray(), 'focus' => [round($mapped[0], 4), round($mapped[1], 4)]]
            + array_intersect_key($stats['meta'], array_flip(['alpha', 'color', 'placeholder']))
            + $meta;
        $m->forceFill(['path' => $key, 'size' => strlen($bytes), 'width' => $stats['width'], 'height' => $stats['height'], 'checksum' => hash('sha256', $bytes), 'meta' => $meta])->save();

        if ($previous === null) {
            $this->setVisibility($disk, (string) $original['path'], 'private');
        } else {
            self::deleteFile($disk, $previous);
        }
        $this->cms->audit->record('media.updated', ['media', (int) $m->getKey()], ['fields' => ['image'], 'edit' => $edit->toArray()], $p->userId);
        $this->library()->queueVariants($m, now: ['thumb']);

        return $m;
    }

    /**
     * Go back to the original: public again, the edited file deleted.
     *
     * @throws MediaException
     */
    public function restore(Media $m, Principal $p): Media
    {
        $this->authorize($m, $p);
        $meta = $m->meta ?? [];
        $original = $meta['original'] ?? null;
        if (!is_array($original)) {
            throw MediaException::conflict('This image has not been edited, so there is nothing to restore.');
        }
        $focus = self::current($m)?->unmapPoint(MediaVariants::focus($m)) ?? MediaVariants::focus($m);
        $disk = $this->cms->storage->disk($m->disk);
        $this->setVisibility($disk, (string) $original['path'], 'public');
        $edited = $m->path;

        unset($meta['original'], $meta['edit']);
        foreach (['alpha', 'color', 'placeholder'] as $key) {
            $meta[$key] = $original[$key] ?? null;
        }
        $meta['focus'] = [round($focus[0], 4), round($focus[1], 4)];
        $m->forceFill([
            'path' => (string) $original['path'], 'size' => (int) $original['size'], 'width' => (int) $original['width'],
            'height' => (int) $original['height'], 'checksum' => $original['checksum'] ?? null, 'meta' => $meta,
        ])->save();
        self::deleteFile($disk, $edited);
        $this->cms->audit->record('media.updated', ['media', (int) $m->getKey()], ['fields' => ['image'], 'restored' => true], $p->userId);
        $this->library()->queueVariants($m, now: ['thumb']);

        return $m;
    }

    /**
     * The original's address for the editor: an expiring link, since it
     * is private while the image is edited. Null when the disk can't make one.
     */
    public function originalUrl(Media $m, int $seconds = 3600): ?string
    {
        $path = $m->meta['original']['path'] ?? null;
        if (!is_string($path)) {
            return null;
        }
        try {
            return $this->cms->storage->disk($m->disk)->temporaryUrl($path, $seconds);
        } catch (\Kestello\Contracts\Storage\StorageException) {
            return null;           // the editor says it can't show the original
        }
    }

    /**
     * Decode the original, apply the edit, encode it in the same format and
     * measure the result (colour, transparency, placeholder).
     *
     * @return array{0: string, 1: array{width: int, height: int, meta: array<string, mixed>}}
     * @throws MediaException
     */
    private function render(Disk $disk, string $path, int $width, int $height, string $mime, ImageEdit $edit): array
    {
        $engine = $this->variants()->engine() ?? throw MediaException::invalid(['image' => ['No image engine is available.']]);
        $format = (string) ImageProcessor::format($mime);
        $tmp = (string) tempnam(sys_get_temp_dir(), 'kme');
        try {
            try {
                $in = $disk->readStream($path);
            } catch (FileNotFound) {
                throw MediaException::conflict('The original file is missing from storage, so the image can\'t be edited.');
            }
            $out = fopen($tmp, 'wb');
            stream_copy_to_stream($in, $out);
            is_resource($in) && fclose($in);
            fclose($out);

            ImageProcessor::reserveMemory($width, $height);
            $img = $engine->open($tmp);
            try {
                if ($edit->transforms()) {
                    if (!$img instanceof TransformableImage) {
                        throw MediaException::invalid(['image' => ['The image engine on this server can crop images but not turn or flip them.']]);
                    }
                    $img->rotate($edit->rotate);
                    $edit->flipHorizontal && $img->flip(horizontal: true);
                    $edit->flipVertical && $img->flip(horizontal: false);
                }
                if ($edit->crop !== null) {
                    $img->crop(...$edit->cropBox($img->width(), $img->height()));
                }
                file_put_contents($tmp, $img->encode($format, ImageProcessor::quality($format)));
            } finally {
                $img->destroy();
            }
            $stats = $this->variants()->processor()->prepare($tmp, $mime);

            return [(string) file_get_contents($tmp), $stats];
        } catch (ImageException $e) {
            throw MediaException::invalid(['image' => ['The image could not be edited: ' . $e->getMessage()]]);
        } finally {
            @unlink($tmp);
        }
    }

    private function authorize(Media $m, Principal $p): void
    {
        if (!$this->library()->canEdit($m, $p)) {
            throw MediaException::forbidden('Only the uploader or an editor can change this file.');
        }
    }

    private function setVisibility(Disk $disk, string $path, string $visibility): void
    {
        try {
            $disk->setVisibility($path, $visibility);
        } catch (\Throwable $e) {
            // Some storage (a bucket made public as a whole) has no per-file visibility; the edit still applies.
            error_log("[media] could not make {$path} {$visibility}: " . $e->getMessage());
        }
    }

    private static function deleteFile(Disk $disk, string $path): void
    {
        try {
            $disk->delete($path);
        } catch (FileNotFound) {
            // already gone
        }
    }

    /** A new name beside the original: harbour.jpg → harbour-e1a2b3c4.jpg, in the same folder. */
    private static function editKey(string $original): string
    {
        $dir = dirname($original);
        $info = pathinfo($original);

        return ($dir === '.' ? '' : $dir . '/') . $info['filename'] . '-e' . bin2hex(random_bytes(4)) . (isset($info['extension']) ? '.' . $info['extension'] : '');
    }

    private function library(): MediaLibrary
    {
        return $this->cms->container->make(MediaLibrary::class);
    }

    private function variants(): MediaVariants
    {
        return $this->cms->container->make(MediaVariants::class);
    }
}
