<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Content;

use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Api\Schema;
use Kestello\Framework\Database\DatabaseManager;
use Kestello\Framework\Database\QueryBuilder;

/**
 * Names only (no emails, no roles) of active people, for author and user
 * fields: editors pick from these without needing users.read. The role is
 * left out so a contributor can't list who the administrators are.
 */
final class ListContentUsers extends ContentEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::get('/content/users');
    }
    public function summary(): string
    {
        return 'Find people for author and user fields';
    }

    public function query(): ?Schema
    {
        return Schema::object([
            'search' => Schema::string()->max(100)->optional(),
            'ids' => Schema::string()->max(1000)->pattern('[0-9,]*')->optional(),
        ]);
    }

    public function handle(ApiRequest $request): mixed
    {
        $q = DatabaseManager::connection()->table('users')->select('id', 'username')->where('is_active', 1);
        $ids = array_slice(array_values(array_unique(array_map('intval', array_filter(explode(',', (string) $request->query('ids', '')))))), 0, 100);
        if ($ids !== []) {
            $q->whereIn('id', $ids);
        } else {
            if (($search = trim((string) $request->query('search', ''))) !== '') {
                $q->whereLike('username', '%' . QueryBuilder::escapeLike($search) . '%');
            }
            $q->orderBy('username')->limit(20);
        }

        return array_map(static fn(array $u): array => ['id' => (int) $u['id'], 'username' => (string) $u['username']], $q->get());
    }
}
