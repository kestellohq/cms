<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Media;

use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Api\ResourceCollection;
use Kestello\Cms\Api\Schema;
use Kestello\Cms\Media\Media;

/** Browse the library: newest first, filter by kind, search, mine, or fetch ids. */
final class ListMedia extends MediaEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::get('/media');
    }
    public function resource(): ?string
    {
        return \Kestello\Cms\Api\Resources\MediaResource::class;
    }
    public function summary(): string
    {
        return 'List media';
    }

    public function query(): ?Schema
    {
        return Schema::object([
            'page' => Schema::int()->min(1)->max(100000)->default(1),
            'per_page' => Schema::int()->min(1)->max(100)->default(40),
            'kind' => Schema::enum(\Kestello\Cms\Media\MediaTypes::KINDS)->optional(),
            'search' => Schema::string()->max(100)->optional(),
            'mine' => Schema::bool()->default(false),
            'ids' => Schema::string()->max(1100)->pattern('\d{1,18}(,\d{1,18}){0,99}')->optional()->description('Comma-separated ids (pickers resolving their value)'),
        ]);
    }

    public function handle(ApiRequest $request): mixed
    {
        $q = Media::query();
        if (($ids = $request->query('ids')) !== null) {
            $q->whereIn('id', array_map('intval', explode(',', (string) $ids)));
        }
        if (($kind = $request->query('kind')) !== null) {
            $q->where('kind', $kind);
        }
        if (($search = trim((string) $request->query('search', ''))) !== '') {
            $like = '%' . \Kestello\Framework\Database\QueryBuilder::escapeLike($search) . '%';
            $q->where(static fn($w) => $w->whereLike('title', $like)->orWhereLike('filename', $like)->orWhereLike('alt', $like));
        }
        if ($request->query('mine') === true) {
            $q->where('uploaded_by', $request->userId() ?? 0);
        }
        $result = $q->orderByDesc('created_at')->orderByDesc('id')->paginate((int) $request->query('per_page'), (int) $request->query('page'));

        return new ResourceCollection(self::present($request, $result['data']->all()), [
            'total' => $result['total'], 'page' => $result['current_page'], 'per_page' => $result['per_page'], 'last_page' => $result['last_page'],
        ]);
    }
}
