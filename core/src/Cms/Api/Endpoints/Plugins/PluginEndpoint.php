<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Plugins;

use Kestello\Cms\Api\ApiProblem;
use Kestello\Cms\Api\Endpoint;
use Kestello\Cms\Cms;
use Kestello\Cms\Content\ContentException;
use Kestello\Cms\Extension\ExtensionManager;
use Kestello\Cms\Extension\Package;
use Kestello\Cms\Package\PackageInstaller;

/** Base for /plugins: plugins.manage, scope admin (plugins run code on the server). @internal */
abstract class PluginEndpoint extends Endpoint
{
    public const string SLUG = '[a-z0-9]+(?:-[a-z0-9]+)*';

    public function capability(): ?string
    {
        return 'plugins.manage';
    }
    public function scopes(): array
    {
        return ['admin'];
    }
    public function tags(): array
    {
        return ['plugins'];
    }

    protected function cms(): Cms
    {
        return Cms::instance() ?? throw new \RuntimeException('The CMS is not booted.');
    }

    protected function extensions(): ExtensionManager
    {
        return $this->cms()->extensions();
    }

    protected function installer(): PackageInstaller
    {
        return $this->cms()->container->make(PackageInstaller::class);
    }

    protected function plugin(string $slug): Package
    {
        return $this->extensions()->plugins()[$slug] ?? throw ApiProblem::notFound('There is no plugin with that name.');
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
        } catch (\RuntimeException $e) {
            throw ApiProblem::conflict($e->getMessage());
        }
    }

    /** @return array<string, mixed> */
    protected function overview(): array
    {
        $ext = $this->extensions();
        $ext->discover(force: true);
        $rejected = $ext->rejectedPlugins();
        $enabled = array_filter(array_keys($ext->plugins()), $ext->isPluginEnabled(...));
        $plugins = [];
        foreach ($ext->plugins() as $slug => $p) {
            $on = $ext->isPluginEnabled($slug);
            $suspended = $ext->suspensions()->reason($p->owner());
            // enabled in this request passed a trial boot already: active
            $status = !$on ? 'inactive' : ($suspended !== null ? 'suspended' : (isset($rejected[$slug]) ? 'failed' : 'active'));
            $requiredBy = [];
            foreach ($enabled as $other) {
                if ($other !== $slug && isset($ext->plugins()[$other]->manifest->dependencies[$slug])) {
                    $requiredBy[] = $other;
                }
            }
            $m = $p->manifest;
            $plugins[] = [
                'slug' => $slug,
                'name' => $m->name,
                'version' => $p->version(),
                'description' => $m->description,
                'author' => is_array($m->raw['author'] ?? null) ? (string) ($m->raw['author']['name'] ?? '') : (string) ($m->raw['author'] ?? ''),
                'homepage' => is_string($m->raw['homepage'] ?? null) && preg_match('#^https://#', $m->raw['homepage']) === 1 ? $m->raw['homepage'] : null,
                'status' => $status,
                'problem' => $suspended ?? (isset($rejected[$slug]) ? implode(' ', $rejected[$slug]) : null),
                'permissions' => $m->permissions,
                'replaces' => $m->replaces,
                'requires' => ['core' => $m->requiresCore, 'php' => $m->requiresPhp, 'plugins' => $m->dependencies],
                'required_by' => $requiredBy,
            ];
        }
        usort($plugins, static fn(array $a, array $b): int => strcasecmp($a['name'], $b['name']));

        return [
            'plugins' => $plugins,
            'invalid' => $ext->discoveryErrors(),
            'recovery' => $ext->isRecoveryMode(),
            'policy' => [
                'trusted' => array_values(array_column($this->installer()->trustedKeys(), 'name')),
                'unsigned' => $this->installer()->adminMayInstallUnsigned(),
            ],
        ];
    }
}
