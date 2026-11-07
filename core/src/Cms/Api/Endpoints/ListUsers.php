<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints;

use Kestello\Cms\Api\ApiProblem;
use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Api\Cursor;
use Kestello\Cms\Api\Endpoint;
use Kestello\Cms\Api\Resources\UserResource;
use Kestello\Cms\Api\Schema;
use Kestello\Framework\Database\QueryBuilder;

final class ListUsers extends Endpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::get('/users');
    }
    public function capability(): ?string
    {
        return 'users.read';
    }
    public function scopes(): array
    {
        return ['users:read'];
    }
    public function resource(): ?string
    {
        return UserResource::class;
    }
    public function summary(): string
    {
        return 'List users (newest first, cursor paginated)';
    }

    public function query(): ?Schema
    {
        return Schema::object([
            'limit' => Schema::int()->min(1)->max(100)->default(25),
            'cursor' => Schema::string()->max(200)->optional(),
            'search' => Schema::string()->max(100)->optional()->description('Matches username or email'),
            'filter' => Schema::object([
                'role' => Schema::string()->max(20)->optional(),
                'active' => Schema::bool()->optional(),
            ])->optional(),
        ]);
    }

    public function handle(ApiRequest $request): mixed
    {
        $limit = (int) $request->query('limit');
        $query = QueryBuilder::table('users')->select(...UserResource::COLUMNS)->orderBy('id', 'DESC')->limit($limit + 1);

        if (($cursor = $request->query('cursor')) !== null) {
            $after = Cursor::decode($cursor) ?? throw ApiProblem::validation(['query.cursor' => ['Invalid cursor.']]);
            $query->where('id', '<', $after);
        }
        if (($search = $request->query('search')) !== null && $search !== '') {
            $pattern = '%' . QueryBuilder::escapeLike($search) . '%';
            $query->where(fn(QueryBuilder $q) => $q->whereLike('username', $pattern)->orWhereLike('email', $pattern));
        }

        $filter = (array) $request->query('filter', []);
        if (isset($filter['role'])) {
            $query->where('role', $filter['role']);
        }
        if (isset($filter['active'])) {
            $query->where('is_active', $filter['active'] ? 1 : 0);
        }

        $rows = $query->get();
        $more = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);

        return UserResource::collection($rows, [
            'limit' => $limit,
            'next_cursor' => $more && $rows !== [] ? Cursor::encode((int) end($rows)['id']) : null,
        ]);
    }
}
