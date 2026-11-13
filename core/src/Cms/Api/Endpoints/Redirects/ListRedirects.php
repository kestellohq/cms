<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Redirects;

use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Api\Resources\RedirectResource;
use Kestello\Cms\Api\Schema;
use Kestello\Framework\Database\QueryBuilder;

/** Redirect rules, newest first, with search and an origin filter. */
final class ListRedirects extends RedirectEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::get('/redirects');
    }
    public function resource(): ?string
    {
        return RedirectResource::class;
    }

    public function summary(): string
    {
        return 'List redirects';
    }

    public function query(): ?Schema
    {
        return Schema::object([
            'page' => Schema::int()->min(1)->max(100000)->default(1),
            'per_page' => Schema::int()->min(1)->max(100)->default(50),
            'search' => Schema::string()->max(200)->optional(),
            'origin' => Schema::enum(['manual', 'auto', 'import'])->optional(),
        ]);
    }

    public function handle(ApiRequest $request): mixed
    {
        $q = QueryBuilder::table('redirects');
        if (($search = trim((string) $request->query('search', ''))) !== '') {
            $like = '%' . QueryBuilder::escapeLike($search) . '%';
            $q->where(static fn($w) => $w->whereLike('source', $like)->orWhereLike('target', $like)->orWhereLike('note', $like));
        }
        if (($origin = $request->query('origin')) !== null) {
            $q->where('origin', $origin);
        }
        $total = (clone $q)->count();
        $perPage = (int) $request->query('per_page');
        $page = (int) $request->query('page');
        $rows = $q->orderBy('created_at', 'DESC')->orderBy('id', 'DESC')->offset(($page - 1) * $perPage)->limit($perPage)->get();

        return RedirectResource::collection($rows, ['total' => $total, 'page' => $page, 'per_page' => $perPage, 'last_page' => max(1, (int) ceil($total / $perPage))]);
    }
}
