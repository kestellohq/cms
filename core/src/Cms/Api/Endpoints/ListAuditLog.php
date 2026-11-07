<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints;

use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Api\Endpoint;
use Kestello\Cms\Api\Schema;
use Kestello\Cms\Cms;
use Kestello\Framework\Core\Response;
use Kestello\Framework\Database\QueryBuilder;

/** The audit log, newest first. Page with meta.next_before_id. */
final class ListAuditLog extends Endpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::get('/audit');
    }
    public function capability(): ?string
    {
        return 'audit.read';
    }
    public function scopes(): array
    {
        return ['audit:read'];
    }
    public function summary(): string
    {
        return 'Read the audit log';
    }
    public function tags(): array
    {
        return ['audit'];
    }

    public function query(): ?Schema
    {
        return Schema::object([
            'event' => Schema::string()->max(100)->pattern('^[a-z0-9._:*-]+$')->optional()->description('Exact name, or a prefix ending in * ("auth.*")'),
            'actor_id' => Schema::int()->min(1)->optional(),
            'subject_type' => Schema::string()->max(50)->optional(),
            'subject_id' => Schema::string()->max(100)->optional(),
            'since' => Schema::int()->min(0)->optional()->description('Unix timestamp'),
            'before_id' => Schema::int()->min(1)->optional(),
            'limit' => Schema::int()->min(1)->max(200)->default(50),
        ]);
    }

    public function handle(ApiRequest $request): mixed
    {
        $filters = array_filter($request->query, static fn($v, $k) => $v !== null && $k !== 'limit', ARRAY_FILTER_USE_BOTH);
        $limit = (int) $request->query('limit', 50);
        $rows = Cms::instance()?->audit->query($filters, $limit) ?? [];

        // Name the actors, so clients can say who did what without a lookup per row.
        $ids = array_values(array_unique(array_filter(array_column($rows, 'actor_id'))));
        $names = $ids === [] ? [] : array_column(QueryBuilder::table('users')->select('id', 'username')->whereIn('id', $ids)->get(), 'username', 'id');
        foreach ($rows as &$row) {
            $row['actor'] = $row['actor_id'] === null ? null : ['id' => $row['actor_id'], 'username' => $names[$row['actor_id']] ?? null];
        }
        unset($row);

        return Response::json([
            'data' => $rows,
            'meta' => ['next_before_id' => count($rows) === $limit ? end($rows)['id'] : null],
        ]);
    }
}
