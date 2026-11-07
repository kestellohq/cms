<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Design;

use Kestello\Cms\Api\ApiProblem;
use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\Endpoint;
use Kestello\Cms\Cms;
use Kestello\Cms\Design\ThemeDocuments;
use Kestello\Cms\Design\ThemeStyles;

/** Base for /design: theme.design, scope settings:write for changes. @internal */
abstract class DesignEndpoint extends Endpoint
{
    public function capability(): ?string
    {
        return 'theme.design';
    }
    public function scopes(): array
    {
        return ['settings:write'];
    }
    public function tags(): array
    {
        return ['design'];
    }

    protected function cms(): Cms
    {
        return Cms::instance() ?? throw new \RuntimeException('The CMS is not booted.');
    }

    protected function styles(): ThemeStyles
    {
        return $this->cms()->container->make(ThemeStyles::class);
    }

    protected function documents(): ThemeDocuments
    {
        return $this->cms()->container->make(ThemeDocuments::class);
    }

    protected function themeSlug(): string
    {
        return $this->styles()->theme()?->slug() ?? throw ApiProblem::conflict('No theme is running.');
    }

    /** Everything the Styles screen needs. @return array<string, mixed> */
    protected function overview(ApiRequest $request): array
    {
        $styles = $this->styles();
        $styles->forget();
        $theme = $styles->theme();
        $slug = $this->themeSlug();
        $published = $styles->published();
        $names = [];
        $revisions = $this->documents()->revisions($slug, 'styles', ThemeStyles::KEY);
        $ids = array_values(array_unique(array_filter(array_column($revisions, 'author'))));
        if ($ids !== []) {
            foreach (\Kestello\Framework\Database\QueryBuilder::table('users')->whereIn('id', $ids)->get() as $u) {
                $u = (array) $u;
                $names[(int) $u['id']] = (string) ($u['display_name'] ?? '') !== '' ? (string) $u['display_name'] : (string) $u['username'];
            }
        }

        return [
            'theme' => ['slug' => $slug, 'name' => $theme?->manifest->name ?? $slug],
            'definition' => $styles->definition(),
            'published' => $published,
            'resolved' => $styles->resolve($published),
            'info' => $this->documents()->info($slug, 'styles', ThemeStyles::KEY),
            'revisions' => array_map(static fn(array $r) => $r + ['author_name' => $r['author'] !== null ? ($names[$r['author']] ?? null) : null], $revisions),
            'can' => ['custom_css' => $request->principal->can('theme.custom_css'), 'themes' => $request->principal->can('theme.manage')],
            'hosted_fonts' => $this->hostedFonts(),
        ];
    }

    /** Fonts downloaded to this site, with their @font-face rules for the live preview. @return list<array<string, mixed>> */
    protected function hostedFonts(): array
    {
        $fonts = $this->cms()->container->make(\Kestello\Cms\Design\ThemeFonts::class);
        $fonts->forget();
        $out = [];
        foreach ($fonts->all() as $slug => $f) {
            $out[] = ['slug' => $slug, 'family' => $f['family'], 'category' => $f['category'], 'bytes' => $f['bytes'], 'files' => count($f['files']), 'faces' => $fonts->faces([$slug])];
        }

        return $out;
    }

    protected function purgePages(): void
    {
        $c = $this->cms()->container;
        if ($c->has(\Kestello\Contracts\Cache\PageCache::class)) {
            $c->make(\Kestello\Contracts\Cache\PageCache::class)->purge('all');
        }
    }
}
