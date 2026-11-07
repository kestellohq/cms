<?php

declare(strict_types=1);

namespace Kestello\Cms\Media;

use Kestello\Cms\Cms;
use Kestello\Framework\Database\Model;

/**
 * A file in the media library. Write through MediaLibrary.
 *
 * @property int $id
 * @property string $disk
 * @property string $path
 * @property string $filename
 * @property string $title
 * @property ?string $alt
 * @property ?string $caption
 * @property string $mime
 * @property string $ext
 * @property string $kind
 * @property int $size
 * @property ?int $width
 * @property ?int $height
 * @property ?string $checksum
 * @property ?int $uploaded_by
 * @property ?array $meta
 *
 * @api
 */
final class Media extends Model
{
    protected string $table = 'media';
    protected array $casts = ['size' => 'int', 'width' => 'int', 'height' => 'int', 'uploaded_by' => 'int', 'meta' => 'json'];

    /** Public address of the original (null when the disk has no public URL). */
    public function url(): ?string
    {
        try {
            return Cms::instance()?->storage->disk($this->disk)->url($this->path);
        } catch (\Throwable) {
            return null;
        }
    }

    public function isImage(): bool
    {
        return $this->kind === 'image';
    }
}
