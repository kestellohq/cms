<?php

declare(strict_types=1);

namespace Kestello\Cms\Media;

use Kestello\Cms\Cms;

/**
 * Media library files as public pages receive them (what <KImage> takes):
 * {id, url, alt, title, caption, width, height, kind, mime, color,
 * placeholder, alpha, focus, sizes}. Deleted files are left out.
 *
 * @api
 */
final class MediaPresenter
{
    /**
     * @param list<int> $ids
     * @return array<int, array<string, mixed>> by id
     */
    public static function many(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $id) => $id > 0)));
        if ($ids === []) {
            return [];
        }
        $models = Media::query()->whereIn('id', $ids)->get()->all();
        $sources = Cms::instance()?->container->make(MediaVariants::class)->sources($models) ?? [];
        $out = [];
        foreach ($models as $m) {
            $id = (int) $m->getKey();
            $out[$id] = [
                'id' => $id, 'url' => $m->url(), 'alt' => (string) ($m->alt ?? ''), 'title' => $m->title, 'caption' => $m->caption,
                'width' => $m->width, 'height' => $m->height, 'kind' => $m->kind, 'mime' => $m->mime,
                'color' => $m->meta['color'] ?? null, 'placeholder' => $m->meta['placeholder'] ?? null, 'alpha' => (bool) ($m->meta['alpha'] ?? false),
                'focus' => $m->kind === 'image' ? MediaVariants::focus($m) : null,
                'sizes' => (object) ($sources[$id] ?? []),
            ];
        }

        return $out;
    }

    /**
     * Replace a media field's value (an id or a list of ids) with the files.
     *
     * @param array<int, array<string, mixed>> $found from many()
     */
    public static function value(mixed $value, bool $multiple, array $found): mixed
    {
        $list = array_values(array_filter(array_map(static fn($id) => is_numeric($id) ? ($found[(int) $id] ?? null) : null, (array) $value)));

        return $multiple ? $list : ($list[0] ?? null);
    }
}
