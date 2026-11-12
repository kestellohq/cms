<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Redirects;

use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Api\Schema;
use Kestello\Framework\Core\Response;
use Kestello\Framework\Database\QueryBuilder;

/** Addresses visitors asked for that didn't exist, most-hit first. */
final class ListNotFound extends RedirectEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::get('/redirects/not-found');
    }
    public function summary(): string
    {
        return 'List addresses that were not found';
    }

    public function query(): ?Schema
    {
        return Schema::object([
            'page' => Schema::int()->min(1)->max(100000)->default(1),
            'per_page' => Schema::int()->min(1)->max(100)->default(50),
            'search' => Schema::string()->max(200)->optional(),
            'sort' => Schema::enum(['hits', 'recent'])->default('hits'),
        ]);
    }

    public function handle(ApiRequest $request): mixed
    {
        $q = QueryBuilder::table('not_found_log');
        if (($search = trim((string) $request->query('search', ''))) !== '') {
            $q->whereLike('path', '%' . QueryBuilder::escapeLike($search) . '%');
        }
        $total = (clone $q)->count();
        $perPage = (int) $request->query('per_page');
        $page = (int) $request->query('page');
        // the id last: rows seen in the same second keep their place from page to page
        $q = $request->query('sort') === 'recent' ? $q->orderBy('last_seen_at', 'DESC') : $q->orderBy('hits', 'DESC')->orderBy('last_seen_at', 'DESC');
        $q = $q->orderBy('id', 'DESC');
        $rows = $q->offset(($page - 1) * $perPage)->limit($perPage)->get();

        return Response::json(['data' => array_map(static fn(array $r) => [
            'id' => (int) $r['id'], 'path' => (string) $r['path'], 'referrer' => (string) $r['referrer'], 'hits' => (int) $r['hits'],
            'first_seen_at' => \Kestello\Support\Date::isoUtc($r['first_seen_at']), 'last_seen_at' => \Kestello\Support\Date::isoUtc($r['last_seen_at']),
        ], $rows), 'meta' => ['total' => $total, 'page' => $page, 'per_page' => $perPage, 'last_page' => max(1, (int) ceil($total / $perPage))]]);
    }
}
