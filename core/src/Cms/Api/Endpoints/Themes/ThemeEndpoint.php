<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Themes;

use Kestello\Cms\Api\ApiProblem;
use Kestello\Cms\Api\Endpoint;
use Kestello\Cms\Cms;
use Kestello\Cms\Content\ContentException;
use Kestello\Cms\Design\ThemeFonts;
use Kestello\Cms\Design\ThemePackages;

/** Base for /themes: theme.manage, scope settings:write. @internal */
abstract class ThemeEndpoint extends Endpoint
{
    public function capability(): ?string
    {
        return 'theme.manage';
    }
    public function scopes(): array
    {
        return ['settings:write'];
    }
    public function tags(): array
    {
        return ['themes'];
    }

    protected function cms(): Cms
    {
        return Cms::instance() ?? throw new \RuntimeException('The CMS is not booted.');
    }

    protected function packages(): ThemePackages
    {
        return $this->cms()->container->make(ThemePackages::class);
    }

    protected function fonts(): ThemeFonts
    {
        return $this->cms()->container->make(ThemeFonts::class);
    }

    protected function attempt(\Closure $write): mixed
    {
        try {
            return $write();
        } catch (ContentException $e) {
            throw match ($e->kind) {
                'invalid' => ApiProblem::validation($e->errors),
                'forbidden' => ApiProblem::forbidden($e->getMessage()),
                default => ApiProblem::conflict($e->getMessage()),
            };
        } catch (\OutOfBoundsException $e) {
            throw ApiProblem::notFound($e->getMessage());
        } catch (\RuntimeException $e) {
            throw ApiProblem::conflict($e->getMessage());
        }
    }

    /** @return array<string, mixed> */
    protected function overview(): array
    {
        $ext = $this->cms()->extensions();
        $ext->discover(force: true);
        $styles = $this->cms()->container->make(\Kestello\Cms\Design\ThemeStyles::class);
        $styles->forget();
        $variations = [];
        foreach ($styles->definition()['variations'] as $name => $v) {
            $variations[] = ['name' => $name, 'label' => $v['label']];
        }
        $running = $ext->activeTheme()?->slug();
        // updates come with the packages module; without it there is nothing to switch back to
        $recovery = $this->cms()->container->has(\Kestello\Cms\Package\Update\UpdateRecovery::class) ? $this->cms()->container->make(\Kestello\Cms\Package\Update\UpdateRecovery::class) : null;
        $active = $ext->configuredTheme();
        $docs = $running === null ? 0 : count($this->cms()->container->make(\Kestello\Cms\Design\ThemeDocuments::class)->allPublished($running));

        return [
            'themes' => array_map(fn(array $t): array => $t + ['switch_back' => $recovery?->switchBackOption('theme:' . $t['slug'], $t['version'])], $this->packages()->list()),
            'active' => $active,
            'running' => $running,
            'variations' => $variations,
            'site_changes' => $docs + ($styles->published() !== ['variation' => null, 'values' => [], 'custom_css' => ''] ? 1 : 0),
            'fonts' => $this->fontList(),
        ];
    }

    /** @return list<array{slug: string, family: string, category: string, bytes: int, files: int}> */
    protected function fontList(): array
    {
        $fonts = $this->fonts();
        $fonts->forget();

        return array_values(array_map(static fn(string $slug, array $f) => ['slug' => $slug, 'family' => $f['family'], 'category' => $f['category'], 'bytes' => $f['bytes'], 'files' => count($f['files'])], array_keys($fonts->all()), $fonts->all()));
    }

    protected function purgePages(): void
    {
        $c = $this->cms()->container;
        if ($c->has(\Kestello\Contracts\Cache\PageCache::class)) {
            $c->make(\Kestello\Contracts\Cache\PageCache::class)->purge('all');
        }
    }
}
