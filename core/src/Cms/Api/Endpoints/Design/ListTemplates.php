<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Design;

use Kestello\Cms\Api\ApiProblem;
use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Design\ThemeTemplates;

/** Templates, parts and patterns of the running theme, with what the site changed. */
final class ListTemplates extends TemplateEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::get('/design/templates');
    }
    public function scopes(): array
    {
        return ['content:read'];
    }
    public function problems(): array
    {
        return [...parent::problems(), 409];
    }

    public function summary(): string
    {
        return 'Templates, parts and patterns';
    }
    public function collection(): bool
    {
        return false;
    }

    public function handle(ApiRequest $request): mixed
    {
        $t = $this->templates();
        $t->forget();
        $slug = $t->themeSlug() ?? throw ApiProblem::conflict('No theme is running.');
        $strip = static fn(array $d) => array_diff_key($d, ['data' => 1]) + ['description' => (string) ($d['data']['description'] ?? ''), 'for' => $d['data']['for'] ?? null, 'area' => $d['data']['area'] ?? null, 'category' => $d['data']['category'] ?? null, 'synced' => $d['data']['synced'] ?? null, 'count' => count($d['data']['blocks'] ?? [])];

        return [
            'theme' => ['slug' => $slug, 'name' => $this->cms()->extensions()->activeTheme()?->manifest->name ?? $slug],
            'template' => array_map($strip, $t->all('template')),
            'part' => array_map($strip, $t->all('part')),
            'pattern' => array_map($strip, $t->all('pattern')),
            'types' => array_values(array_map(static fn($type) => ['name' => $type->name, 'singular' => $type->singular(), 'plural' => $type->plural()], array_filter($this->cms()->content->types(), static fn($type) => $type->getPath() !== null))),
            'areas' => ThemeTemplates::AREAS,
        ];
    }
}
