<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Media;

use Closure;
use Kestello\Cms\Api\ApiProblem;
use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\Endpoint;
use Kestello\Cms\Api\Resources\MediaResource;
use Kestello\Cms\Cms;
use Kestello\Cms\Media\Media;
use Kestello\Cms\Media\MediaException;
use Kestello\Cms\Media\MediaLibrary;
use Kestello\Framework\Database\QueryBuilder;

/**
 * Shared plumbing. Anyone who can upload (media.upload) or manage the
 * library (media.manage) can browse it; changing a file needs manage or
 * being its uploader.
 */
abstract class MediaEndpoint extends Endpoint
{
    public function capability(): ?string
    {
        return 'admin.access';
    }
    public function scopes(): array
    {
        return ['content:read'];
    }
    public function tags(): array
    {
        return ['media'];
    }

    public function authorize(ApiRequest $request): bool
    {
        return $request->principal->can('media.upload') || $request->principal->can('media.manage');
    }

    protected static function cms(): Cms
    {
        return Cms::instance() ?? throw new \LogicException('Media endpoints need a booted CMS.');
    }

    protected static function library(): MediaLibrary
    {
        return self::cms()->container->make(MediaLibrary::class);
    }

    protected static function find(ApiRequest $request): Media
    {
        return Media::query()->find($request->intParam('id')) ?? throw ApiProblem::notFound('No such file.');
    }

    /** @param list<Media> $models */
    protected static function present(ApiRequest $request, array $models, bool $usage = false): array
    {
        $uploaders = [];
        $ids = array_values(array_unique(array_filter(array_map(static fn(Media $m) => $m->uploaded_by, $models))));
        if ($ids !== []) {
            foreach (QueryBuilder::table('users')->whereIn('id', $ids)->select('id', 'username')->get() as $u) {
                $u = (array) $u;
                $uploaders[(int) $u['id']] = ['id' => (int) $u['id'], 'name' => (string) $u['username']];
            }
        }
        $used = $usage ? self::library()->usage(array_map(static fn(Media $m) => (int) $m->getKey(), $models)) : [];
        $library = self::library();
        $editor = self::cms()->container->make(\Kestello\Cms\Media\MediaEditor::class);
        $sources = \Kestello\Cms\Cms::instance()?->container->make(\Kestello\Cms\Media\MediaVariants::class)->sources($models, $usage ? ['thumb', 'medium'] : ['thumb']) ?? [];
        $out = [];
        foreach ($models as $m) {
            $canEdit = $library->canEdit($m, $request->principal);
            $row = [
                'model' => $m, 'uploader' => $uploaders[$m->uploaded_by] ?? null, 'sizes' => $sources[(int) $m->getKey()] ?? null,
                'can' => ['edit' => $canEdit, 'edit_image' => $canEdit && $editor->refusal($m) === null],
            ];
            if ($usage && $canEdit && isset($m->meta['original'])) {
                // the private original, for the image editor
                $row['original'] = ['url' => $editor->originalUrl($m), 'width' => (int) $m->meta['original']['width'], 'height' => (int) $m->meta['original']['height']];
            }
            if ($usage) {
                $row['usage'] = $used[(int) $m->getKey()] ?? [];
            }
            $out[] = new MediaResource($row);
        }

        return $out;
    }

    protected static function attempt(Closure $write): mixed
    {
        try {
            return $write();
        } catch (MediaException $e) {
            throw match ($e->kind) {
                'invalid' => ApiProblem::validation($e->errors),
                'conflict' => ApiProblem::conflict($e->getMessage()),
                default => ApiProblem::forbidden($e->getMessage()),
            };
        }
    }

    /** @return array<string, mixed> */
    protected static function upload(ApiRequest $request): array
    {
        return self::library()->upload((string) $request->param('id'), $request->principal) ?? throw ApiProblem::notFound('No such upload, or it expired.');
    }
}
