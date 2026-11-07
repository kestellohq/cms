<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\ContentModels;

use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Api\Schema;

/**
 * Save a new definition. Renamed fields keep their values. Changes that
 * affect stored values answer 409 until sent with confirm; purge also
 * deletes removed fields' values (otherwise they stay stored).
 */
final class UpdateContentModel extends ContentModelEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::patch('/content-models/{id}')->where('id', '\d{1,18}');
    }
    public function scopes(): array
    {
        return ['settings:write'];
    }
    public function requiresRecentAuth(): bool
    {
        return true;
    }   // changes the site's structure
    public function summary(): string
    {
        return 'Change a content model';
    }

    public function body(): ?Schema
    {
        return Schema::object([
            'definition' => Schema::any(),
            'confirm' => Schema::bool()->default(false),
            'purge' => Schema::bool()->default(false),
        ]);
    }

    public function handle(ApiRequest $request): mixed
    {
        $id = (int) $request->param('id');
        $b = $request->body;
        $result = self::attempt(fn() => self::models()->update($id, is_array($b['definition']) ? $b['definition'] : [], (bool) $b['confirm'], (bool) $b['purge']));
        self::audit($request, 'content_model.updated', $id, [
            'name' => $result['model']['name'],
            'changes' => array_map(static fn($c) => array_intersect_key($c, array_flip(['change', 'field', 'from', 'to', 'entries'])), $result['changes']),
            'purged' => (bool) $b['purge'],
        ]);

        return $result;
    }
}
