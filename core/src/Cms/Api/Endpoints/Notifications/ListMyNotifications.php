<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Notifications;

use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Api\Endpoint;
use Kestello\Cms\Api\Schema;
use Kestello\Cms\Cms;
use Kestello\Framework\Core\Response;
use Kestello\Framework\Database\QueryBuilder;
use Kestello\Support\Json;

/** Your inbox, newest first (cursor: pass the last id as "before"). */
final class ListMyNotifications extends Endpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::get('/me/notifications');
    }
    public function capability(): ?string
    {
        return 'profile.manage';
    }
    public function scopes(): array
    {
        return ['profile'];
    }
    public function summary(): string
    {
        return 'List your notifications';
    }
    public function tags(): array
    {
        return ['me'];
    }

    public function query(): ?Schema
    {
        return Schema::object([
            'unread' => Schema::bool()->default(false),
            'limit' => Schema::int()->min(1)->max(100)->default(25),
            'before' => Schema::string()->max(36)->optional(),
        ]);
    }

    public function handle(ApiRequest $request): mixed
    {
        $userId = (int) $request->userId();
        $q = QueryBuilder::table('notifications')->where('user_id', $userId);
        if ($request->query('unread') === true) {
            $q = $q->whereNull('read_at');
        }
        if (is_string($before = $request->query('before')) && $before !== '') {
            $q = $q->where('id', '<', $before);
        }
        $limit = (int) $request->query('limit', 25);
        $rows = $q->orderBy('id', 'desc')->limit($limit + 1)->get();
        $more = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);

        return Response::json([
            'data' => array_map(static fn(array $r): array => [
                'id' => (string) $r['id'],
                'type' => (string) $r['type'],
                'data' => Json::tryDecode((string) $r['data']) ?? [],
                'read_at' => $r['read_at'] !== null ? (int) $r['read_at'] : null,
                'created_at' => (int) $r['created_at'],
            ], $rows),
            'meta' => [
                'unread' => Cms::instance()?->notifications->unreadCount($userId) ?? 0,
                'next_before' => $more && $rows !== [] ? (string) end($rows)['id'] : null,
            ],
        ]);
    }
}
