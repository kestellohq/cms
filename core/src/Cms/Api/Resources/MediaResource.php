<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Resources;

use Kestello\Cms\Api\Resource;
use Kestello\Cms\Api\Schema;
use Kestello\Cms\Media\Media;
use Kestello\Contracts\Auth\Principal;

/**
 * A media library file. Row: model (Media), can ({edit}), usage (list or
 * null when not loaded), uploader ({id, name} or null), sizes (variant
 * sources by size: thumb in lists, thumb and medium for one file).
 */
final class MediaResource extends Resource
{
    public const string NAME = 'media';

    protected function fields(Principal $principal): array
    {
        /** @var Media $m */
        $m = $this->row['model'];
        $out = [
            'id' => (int) $m->getKey(),
            'title' => $m->title,
            'filename' => $m->filename,
            'alt' => $m->alt,
            'caption' => $m->caption,
            'kind' => $m->kind,
            'mime' => $m->mime,
            'ext' => $m->ext,
            'size' => (int) $m->size,
            'width' => $m->width,
            'height' => $m->height,
            'url' => $m->url(),
            'focus' => $m->kind === 'image' ? \Kestello\Cms\Media\MediaVariants::focus($m) : null,
            'color' => $m->meta['color'] ?? null,
            'placeholder' => $m->meta['placeholder'] ?? null,
            'animated' => (bool) ($m->meta['animated'] ?? false),
            'sizes' => $this->row['sizes'] ?? null,
            'uploader' => $this->row['uploader'] ?? null,
            'created_at' => self::date($m->created_at),
            'updated_at' => self::date($m->updated_at),
            'can' => $this->row['can'] ?? ['edit' => false],
            'edit' => \Kestello\Cms\Media\MediaEditor::current($m)?->toArray(),
            'original' => $this->row['original'] ?? null,
        ];
        if (array_key_exists('usage', $this->row)) {
            $out['usage'] = $this->row['usage'];
            $out['usage_hidden'] = (int) ($this->row['usage_hidden'] ?? 0);
        }

        return $out;
    }

    public static function properties(): array
    {
        return [
            'id' => Schema::int(), 'title' => Schema::string(), 'filename' => Schema::string(), 'alt' => Schema::string()->nullable(),
            'caption' => Schema::string()->nullable(), 'kind' => Schema::enum(\Kestello\Cms\Media\MediaTypes::KINDS), 'mime' => Schema::string(),
            'ext' => Schema::string(), 'size' => Schema::int(), 'width' => Schema::int()->nullable(), 'height' => Schema::int()->nullable(),
            'url' => Schema::string()->nullable(), 'focus' => Schema::list(Schema::number())->nullable(), 'color' => Schema::string()->nullable(),
            'placeholder' => Schema::string()->nullable(), 'animated' => Schema::bool(), 'sizes' => Schema::any()->nullable()->description('Generated sizes by name, with their URLs'),
            'uploader' => Schema::object(['id' => Schema::int(), 'name' => Schema::string()->description('Their username')])->nullable(),
            'created_at' => Schema::string()->format('date-time')->nullable(), 'updated_at' => Schema::string()->format('date-time')->nullable(),
            'can' => Schema::dictionary(),
            'edit' => Schema::object([
                'rotate' => Schema::int(), 'flip_horizontal' => Schema::bool(), 'flip_vertical' => Schema::bool(),
                'crop' => Schema::object(['x' => Schema::number(), 'y' => Schema::number(), 'width' => Schema::number(), 'height' => Schema::number()])->nullable(),
            ])->nullable()->description('How the image was cropped, turned and flipped, or null when it is the original'),
            'original' => Schema::object(['url' => Schema::string()->nullable(), 'width' => Schema::int(), 'height' => Schema::int()])->nullable()
                ->description('An edited image\'s original, for people who may edit it (single-item answers only); the URL expires'),
            'usage' => Schema::any()->nullable()->optional()->description('Where it is used; single-item answers only, and only entries the viewer may open'),
            'usage_hidden' => Schema::int()->optional()->description('How many other entries use it (ones the viewer may not open)'),
        ];
    }
}
