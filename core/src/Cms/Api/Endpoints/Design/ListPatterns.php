<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Design;

use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Api\Endpoint;
use Kestello\Cms\Cms;
use Kestello\Cms\Design\ThemeTemplates;

/**
 * Published patterns for the block inserter and the layouts a new page can start
 * from: anyone who edits content may place them. Each is fitted to this site
 * (BlockRegistry::fit), so what is placed saves as it is.
 */
final class ListPatterns extends Endpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::get('/design/patterns');
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
        return ['design'];
    }
    public function summary(): string
    {
        return 'Patterns for the block inserter';
    }
    public function collection(): bool
    {
        return false;
    }

    public function handle(ApiRequest $request): mixed
    {
        $cms = Cms::instance() ?? throw new \RuntimeException('Not booted.');
        $t = $cms->container->make(ThemeTemplates::class);
        $out = [];
        try {
            foreach ($t->all('pattern') as $p) {
                if ($p['published']) {
                    $data = $t->get('pattern', $p['key']) ?? $p['data'];
                    $out[] = [
                        'key' => $p['key'], 'title' => $p['title'], 'description' => (string) ($data['description'] ?? ''), 'category' => (string) ($data['category'] ?? 'General'),
                        'synced' => !empty($data['synced']), 'layout' => !empty($data['layout']), 'source' => $p['source'], 'blocks' => $cms->blocks->fit($data['blocks'] ?? []),
                    ];
                }
            }
        } catch (\LogicException) {
        }

        return ['patterns' => $out];
    }
}
