<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Content;

use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Api\Schema;
use Kestello\Cms\Content\Reviews;

/**
 * The entries waiting on the caller, most urgent first: their own sent back with changes
 * requested, those assigned to them, and those in review that are theirs to publish
 * (Reviews::waitingFor). The dashboard's "Waiting on you" card.
 */
final class ListWaiting extends ContentEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::get('/content/waiting');
    }
    public function summary(): string
    {
        return 'List the entries waiting on you';
    }

    public function query(): ?Schema
    {
        return Schema::object(['limit' => Schema::int()->min(1)->max(50)->default(20)]);
    }

    public function response(): ?Schema
    {
        return Schema::object([
            'id' => Schema::int(), 'type' => Schema::string(), 'type_label' => Schema::string(), 'title' => Schema::string(), 'status' => Schema::string(),
            'reason' => Schema::enum(Reviews::WAITING)->description('changes_requested: yours, sent back; assigned: assigned to you; review: in review, yours to publish'),
            'since' => Schema::string()->nullable()->description('When it started waiting on you'),
        ]);
    }

    public function handle(ApiRequest $request): mixed
    {
        return self::cms()->container->make(Reviews::class)->waitingFor($request->principal, (int) $request->query('limit', 20));
    }
}
