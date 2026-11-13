<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Content;

use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Api\Schema;
use Kestello\Cms\Content\Entry;

/**
 * Change an entry (only the keys sent). Send "version" (from the loaded
 * entry) to refuse the save when someone else saved in between (409).
 */
final class UpdateEntry extends ContentEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::patch('/content/{type}/entries/{id}')->where('type', self::TYPE)->where('id', '\d{1,18}');
    }
    public function resource(): ?string
    {
        return \Kestello\Cms\Api\Resources\EntryResource::class;
    }
    public function scopes(): array
    {
        return ['content:write'];
    }
    /** 409: Someone saved the entry since it was opened. */
    public function problems(): array
    {
        return [...parent::problems(), 409];
    }

    public function summary(): string
    {
        return 'Update an entry';
    }

    public function body(): ?Schema
    {
        $schema = self::entryBody();
        $props = $schema->properties();
        $props['version'] = Schema::int()->min(0)->optional()->description('The version you loaded; 409 if the entry changed since');

        return Schema::object($props);
    }

    public function handle(ApiRequest $request): mixed
    {
        $type = self::type($request);
        $entry = self::entry($request, $type);
        $input = $request->body;
        $version = isset($input['version']) ? (int) $input['version'] : null;
        unset($input['version']);
        self::attempt(fn() => self::entries()->update($entry, $input, $request->principal, $version));

        return self::present($request, $type, $entry);
    }
}
