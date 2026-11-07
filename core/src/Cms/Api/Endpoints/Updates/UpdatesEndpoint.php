<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Updates;

use Kestello\Cms\Api\Endpoints\Plugins\PluginEndpoint;
use Kestello\Cms\Package\Update\CoreUpdater;
use Kestello\Cms\Package\Update\UpdateChecker;
use Kestello\Support\Markdown;

/** Base for /updates: core.update, scope admin. @internal */
abstract class UpdatesEndpoint extends PluginEndpoint
{
    public function capability(): ?string
    {
        return 'core.update';
    }
    public function tags(): array
    {
        return ['updates'];
    }

    protected function core(): CoreUpdater
    {
        return $this->cms()->container->make(CoreUpdater::class);
    }

    /** @return array<string, mixed> */
    protected function versions(): array
    {
        $plugins = [];
        foreach ($this->extensions()->plugins() as $slug => $p) {
            $plugins[] = ['slug' => $slug, 'name' => $p->manifest->name, 'version' => $p->version(), 'active' => $this->extensions()->isPluginEnabled($slug)];
        }
        $themes = [];
        foreach ($this->extensions()->themes() as $slug => $t) {
            $themes[] = ['slug' => $slug, 'name' => $t->manifest->name, 'version' => $t->version(), 'active' => $slug === $this->extensions()->configuredTheme()];
        }

        return [
            'core' => $this->core()->status(),
            'php' => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . '.' . PHP_RELEASE_VERSION,
            'plugins' => $plugins,
            'themes' => $themes,
            'policy' => ['trusted' => array_values(array_map(static fn(array $k): string => $k['name'], $this->core()->trustedKeys())), 'unsigned' => $this->installer()->adminMayInstallUnsigned()],
            'available' => $this->available(),
        ];
    }

    protected function checker(): UpdateChecker
    {
        return $this->cms()->container->make(UpdateChecker::class);
    }

    /**
     * What the last check of GitHub found, with the release notes as safe HTML.
     *
     * @return array<string, mixed>|null
     */
    protected function available(): ?array
    {
        $last = $this->checker()->last();
        if ($last === null) {
            return null;
        }
        if ($last['latest'] !== null) {
            $last['latest']['notes_html'] = Markdown::toHtml((string) ($last['latest']['notes'] ?? ''));
        }

        return $last + ['repository' => $this->checker()->repository()];
    }
}
