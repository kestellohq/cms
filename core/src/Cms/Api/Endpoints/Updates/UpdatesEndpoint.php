<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Updates;

use Kestello\Cms\Api\Endpoints\Plugins\PluginEndpoint;
use Kestello\Cms\Extension\Package;
use Kestello\Cms\Package\Update\CoreUpdater;
use Kestello\Cms\Package\Update\KeyPins;
use Kestello\Cms\Package\Update\PackageLicenses;
use Kestello\Cms\Package\Update\PackageUpdates;
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
        $checks = $this->cms()->container->make(PackageUpdates::class)->results();
        $plugins = [];
        foreach ($this->extensions()->plugins() as $slug => $p) {
            $plugins[] = ['slug' => $slug, 'name' => $p->manifest->name, 'version' => $p->version(), 'active' => $this->extensions()->isPluginEnabled($slug), 'switch_back' => $this->recovery()->switchBackOption($p->owner(), $p->version())] + $this->updateOf($p, $checks);
        }
        $themes = [];
        foreach ($this->extensions()->themes() as $slug => $t) {
            $themes[] = ['slug' => $slug, 'name' => $t->manifest->name, 'version' => $t->version(), 'active' => $slug === $this->extensions()->configuredTheme(), 'switch_back' => $this->recovery()->switchBackOption($t->owner(), $t->version())] + $this->updateOf($t, $checks);
        }

        return [
            'core' => $this->core()->status() + ['switch_back' => $this->recovery()->switchBackOption('core', $this->core()->running())],
            'stopped_update' => $this->stoppedUpdate(),
            'php' => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . '.' . PHP_RELEASE_VERSION,
            'plugins' => $plugins,
            'themes' => $themes,
            'policy' => ['trusted' => array_values(array_map(static fn(array $k): string => $k['name'], $this->core()->trustedKeys())), 'unsigned' => $this->installer()->adminMayInstallUnsigned()],
            'available' => $this->available(),
        ];
    }

    /**
     * Where a package's updates come from, its license key (set or not, never the key) and what
     * the last check of its source found.
     *
     * @param array<string, array<string, mixed>> $checks PackageUpdates::results()
     * source: registry, github, url, or null (updated by upload)
     *
     * @return array{source: ?string, license: array{required: bool, set: bool, last4: ?string}, update: ?array<string, mixed>}
     */
    private function updateOf(Package $p, array $checks): array
    {
        $fromRegistry = $this->cms()->container->make(KeyPins::class)->organisation($p->owner()) !== null;
        // a package installed from the registry takes its updates from the registry, whatever its manifest says
        $updates = $fromRegistry ? null : $p->manifest->updates;
        $required = ($updates['license'] ?? false) === true;
        $check = $checks[$p->owner()] ?? null;
        if ($check !== null && $check['offer'] !== null) {
            $check['offer']['notes_html'] = Markdown::toHtml((string) $check['offer']['notes']);
        }

        return [
            'source' => $fromRegistry ? 'registry' : ($updates === null ? null : (isset($updates['github']) ? 'github' : 'url')),
            'license' => ['required' => $required] + ($required ? $this->cms()->container->make(PackageLicenses::class)->describe($p->owner()) : ['set' => false, 'last4' => null]),
            'update' => $check,
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
