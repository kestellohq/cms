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
use Kestello\Cms\Package\Registry\RegistryClient;
use Kestello\Cms\Package\Registry\RegistryIndex;
use Kestello\Cms\Package\Registry\RegistryTrust;
use Kestello\Cms\Package\Update\UpdateJournal;
use Kestello\Cms\Package\Update\Updater;
use Kestello\Cms\Package\Update\UpdateRecovery;

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

    protected function recovery(): UpdateRecovery
    {
        return $this->cms()->container->make(UpdateRecovery::class);
    }

    /** The installed version of "plugin:slug" or "theme:slug", null when it is not installed. */
    protected function installedVersion(string $owner): ?string
    {
        if (preg_match('/^(plugin|theme):(' . self::SLUG . ')$/', $owner, $m) !== 1) {
            return null;
        }
        $packages = $m[1] === 'plugin' ? $this->extensions()->plugins() : $this->extensions()->themes();

        return isset($packages[$m[2]]) ? $packages[$m[2]]->version() : null;
    }

    /**
     * An update that stopped halfway, as the admin shows it (no server paths).
     *
     * @return array<string, mixed>|null
     */
    protected function stoppedUpdate(): ?array
    {
        $journal = $this->recovery()->journal();
        $entry = $journal->stopped();
        $running = $entry === null;
        $entry ??= $journal->runningLong();
        if ($entry === null) {
            return null;
        }

        return [
            'owner' => $entry['owner'],
            'name' => UpdateRecovery::displayName($this->cms(), (string) $entry['owner']),
            'kind' => $entry['kind'] ?? 'update',
            'from' => $entry['from'],
            'to' => $entry['to'],
            'started_at' => (int) ($entry['started_at'] ?? 0),
            'attempts' => (int) $entry['attempts'],
            'gave_up' => !$running && (int) $entry['attempts'] >= UpdateJournal::MAX_ATTEMPTS,
            // still holding the update lock after UpdateJournal::LONG: most likely a check that outlived its updater
            'running' => $running,
        ];
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
                'switch_back' => $this->recovery()->switchBackOption($p->owner(), $p->version()),
            ];
        }
        usort($plugins, static fn(array $a, array $b): int => strcasecmp($a['name'], $b['name']));

        return [
            'plugins' => $plugins,
            'invalid' => $ext->discoveryErrors(),
            'recovery' => $ext->isRecoveryMode(),
            'stopped_update' => $this->stoppedUpdate(),
            'policy' => [
                'trusted' => array_values(array_column($this->installer()->trustedKeys(), 'name')),
                'unsigned' => $this->installer()->adminMayInstallUnsigned(),
            ],
        ];
    }

    /** The registry, or null when this site has it turned off (registry.enabled). */
    protected function registry(): ?RegistryClient
    {
        return (bool) $this->cms()->config->get('registry.enabled', true) ? $this->cms()->container->make(RegistryClient::class) : null;
    }

    /** The registry's checked list, or a 409 saying why there is none. */
    protected function registryIndex(RegistryClient $client): RegistryIndex
    {
        return $client->index() ?? throw ApiProblem::conflict($client->status()['error'] ?? 'The registry could not be read yet. Try again in a minute.');
    }

    /**
     * Keep a plugin zip for review and say what it is: who signed it, what the registry says about
     * it, what it declares, and whether it may be installed (POST /plugins/install) or, when an
     * active copy is installed, applied as an update (POST /plugins/update). Nothing is installed.
     * Uploads and registry downloads both come through here, so they are judged the same way.
     *
     * @return array{token: string, package: array<string, mixed>, update: ?array{from: string, problems: list<string>}, allowed: bool}
     */
    protected function review(string $zipPath, string $notPlugin): array
    {
        $package = $this->attempt(fn() => $this->installer()->inspect($zipPath));
        if ($package['type'] !== 'plugin') {
            throw ApiProblem::validation(['file' => [$notPlugin]]);
        }
        $token = $this->attempt(fn() => $this->installer()->hold($zipPath));
        $sig = $package['signature']['status'];

        $update = null;
        if ($package['installed'] !== null && $package['installed']['active']) {
            $held = $this->installer()->held($token);
            $update = ['from' => $package['installed']['version'], 'problems' => $this->attempt(fn() => $this->cms()->container->make(Updater::class)->check($held)['problems'])];
        }

        return [
            'token' => $token,
            'package' => $package,
            'update' => $update,
            // a version the registry refuses (withdrawn, revoked key, …) never installs, whatever the signature says
            'allowed' => $package['registry']['status'] !== RegistryTrust::REFUSED
                && ($sig === 'signed' || ($sig !== 'tampered' && $this->installer()->adminMayInstallUnsigned())),
        ];
    }
}
