<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Blocks;

use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Api\Endpoint;
use Kestello\Cms\Cms;

/** Every block the editor can insert: fields, nesting rules, data it can bind to, and the styles blocks can be given. */
final class ListBlocks extends Endpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::get('/blocks');
    }
    public function capability(): ?string
    {
        return 'content.access';
    }
    public function scopes(): array
    {
        return ['content:read'];
    }
    public function tags(): array
    {
        return ['content'];
    }
    public function summary(): string
    {
        return 'Block types';
    }
    public function collection(): bool
    {
        return false;
    }

    public function handle(ApiRequest $request): mixed
    {
        $blocks = (Cms::instance() ?? throw new \LogicException('Not booted.'))->blocks;

        return ['blocks' => $blocks->describe(), 'bindings' => $blocks->bindings->describe(), 'design' => $blocks->design->describe()];
    }
}
