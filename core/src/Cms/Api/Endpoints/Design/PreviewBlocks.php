<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Design;

use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Api\Schema;
use Kestello\Cms\Blocks\BlockRegistry;
use Kestello\Cms\Design\DesignCanvas;

/**
 * Prepare an unsaved block tree the way the site would show it, for the
 * builder's canvas. Nothing is stored; blocks that don't validate are
 * left out, as they would be on the site.
 */
final class PreviewBlocks extends TemplateEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::post('/design/preview');
    }
    public function scopes(): array
    {
        return ['content:read'];
    }
    public function summary(): string
    {
        return 'Preview a block tree';
    }
    public function rateLimit(): ?array
    {
        return [600, 60];
    }   // the canvas asks on every change

    public function body(): ?Schema
    {
        return Schema::object([
            'blocks' => Schema::any(1048576, 40),
            'entry' => Schema::int()->nullable()->optional(),
        ]);
    }

    public function handle(ApiRequest $request): mixed
    {
        $canvas = $this->cms()->container->make(DesignCanvas::class);
        $entry = $canvas->entry(isset($request->body['entry']) ? (int) $request->body['entry'] : null, $request->principal);
        $blocks = $canvas->prepare($request->body['blocks'] ?? [], $entry, $request->http, \Kestello\Cms\Auth\Auth::getInstance()?->user());

        return ['blocks' => $blocks, 'components' => BlockRegistry::components($blocks)];
    }
}
