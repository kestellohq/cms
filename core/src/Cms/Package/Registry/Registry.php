<?php

declare(strict_types=1);

namespace Kestello\Cms\Package\Registry;

use Kestello\Support\Translator;

/**
 * The plugin and theme registry, read-only, for plugins and themes that show it: a directory of
 * packages, an organisation's page, a list of advisories. The site's own checked copy
 * (RegistryClient) is what it reads, so it shows exactly what Plugins → Add new would install.
 *
 *   $registry = $cms->registry();
 *   $registry->status()              where the site's copy stands, and why it can't be used
 *   $registry->packages('plugin')    every free plugin, by name
 *   $registry->package('events')     one package in full, or null when the registry doesn't list it
 *   $registry->organisations()       every organisation, by name
 *   $registry->organisation('acme')  one organisation, with its packages
 *   $registry->advisories()          every advisory, newest first
 *   $registry->categories()          category id => label, in the site's language
 *
 * Text from publishers (descriptions, release notes) comes as safe HTML without images; icons and
 * screenshots are addresses of the registry's own copies, never a publisher's server. Times are
 * Unix timestamps. Paid packages are left out until sites can buy them. Nothing here decides
 * trust: installs still go through PackageInstaller and RegistryTrust.
 *
 * When the registry is turned off (registry.enabled) or the site has no usable copy yet, the lists
 * are empty and status() says why. package(), organisation() and advisories() may need to fetch a
 * file the index pins: they throw RegistryException when it can't be fetched or read.
 *
 * @api
 */
final class Registry
{
    /** @param RegistryClient|null $client null when the registry is turned off on this site */
    public function __construct(private readonly ?RegistryClient $client) {}

    public function enabled(): bool
    {
        return $this->client !== null;
    }

    /**
     * Where the site's copy of the registry stands.
     *
     * @return array{enabled: bool, usable: bool, checked_at: ?int, expires_at: ?int, packages: int, error: ?string}
     */
    public function status(): array
    {
        if ($this->client === null) {
            return ['enabled' => false, 'usable' => false, 'checked_at' => null, 'expires_at' => null, 'packages' => 0, 'error' => null];
        }
        $s = $this->client->status();

        return [
            'enabled' => true,
            'usable' => $this->client->index() !== null,
            'checked_at' => $s['checked_at'],
            'expires_at' => $s['expires_at'],
            'packages' => $s['packages'],
            'error' => $s['error'],
        ];
    }

    /**
     * Fetch the registry's list now and check it; on failure the previous copy stays in use.
     * Sites do this on their own every app.updates.check_every hours.
     *
     * @return array{enabled: bool, usable: bool, checked_at: ?int, expires_at: ?int, packages: int, error: ?string}
     */
    public function refresh(): array
    {
        $this->client?->refresh();

        return $this->status();
    }

    /**
     * Every free package, or every one of a type, by name.
     *
     * @param 'plugin'|'theme'|null $type
     * @return list<array{slug: string, type: string, name: string, summary: string, organisation: string, categories: list<string>, icon: ?string, stable: ?string, prerelease: ?string, requires_core: ?string, updated_at: int}>
     */
    public function packages(?string $type = null): array
    {
        $index = $this->client?->index();
        if ($index === null) {
            return [];
        }
        $out = [];
        foreach ($index->packages as $entry) {
            if (!$entry['paid'] && ($type === null || $entry['type'] === $type)) {
                $out[] = $this->summary($entry);
            }
        }
        usort($out, static fn(array $a, array $b): int => [strtolower($a['name']), $a['slug']] <=> [strtolower($b['name']), $b['slug']]);

        return $out;
    }

    /**
     * One free package in full, or null when the registry doesn't list it. Versions are newest
     * first, withdrawn ones included with the reason; advisories are the package's, newest first.
     *
     * @return array{
     *   slug: string, type: string, name: string, summary: string, organisation: string, categories: list<string>, icon: ?string,
     *   stable: ?string, prerelease: ?string, requires_core: ?string, updated_at: int,
     *   description_html: string, license: string, homepage: ?string, support: ?string, source: ?string,
     *   screenshots: list<array{url: string, caption: string}>,
     *   versions: list<array{version: string, prerelease: bool, released_at: int, requires: array{core: string, php: ?string, extensions: list<string>}, dependencies: array<string, string>, permissions: array<mixed>, notes_html: string, size: int, withdrawn: ?array{at: int, reason: string}}>,
     *   advisories: list<array{id: string, type: string, package: string, affected: string, fixed: ?string, severity: string, title: string, url: string, published_at: int}>
     * }|null
     * @throws RegistryException when the package's file can't be fetched or read
     */
    public function package(string $slug): ?array
    {
        $entry = $this->client?->index()?->packages[$slug] ?? null;
        if ($this->client === null || $entry === null || $entry['paid']) {
            return null;
        }
        $file = $this->client->package($slug);
        if ($file === null) {
            return null;
        }

        return $this->summary($entry) + [
            'description_html' => RegistryCatalog::html($file['description']),
            'license' => $file['license'],
            'homepage' => $file['homepage'],
            'support' => $file['support'],
            'source' => $file['source'],
            'screenshots' => array_map(fn(array $s): array => ['url' => $this->client->url($s['image']), 'caption' => $s['caption']], $file['screenshots']),
            'versions' => array_map(static fn(array $v): array => [
                'version' => $v['version'],
                'prerelease' => $v['prerelease'],
                'released_at' => $v['released_at'],
                'requires' => ['core' => $v['requires_core'], 'php' => $v['requires_php'], 'extensions' => $v['extensions']],
                'dependencies' => $v['dependencies'],
                'permissions' => $v['permissions'],
                'notes_html' => RegistryCatalog::html($v['notes']),
                'size' => $v['size'],
                'withdrawn' => $v['withdrawn'],
            ], $file['versions']),
            'advisories' => array_values(array_filter($this->advisories(), static fn(array $a): bool => $a['type'] === $entry['type'] && $a['package'] === $slug)),
        ];
    }

    /**
     * Every organisation, by name; verified is the domain it proved it holds, or null.
     *
     * @return list<array{id: string, name: string, verified: ?string}>
     */
    public function organisations(): array
    {
        $index = $this->client?->index();
        if ($index === null) {
            return [];
        }
        $out = array_map(static fn(array $o): array => ['id' => $o['id'], 'name' => $o['name'], 'verified' => $o['verified']], array_values($index->organisations));
        usort($out, static fn(array $a, array $b): int => [strtolower($a['name']), $a['id']] <=> [strtolower($b['name']), $b['id']]);

        return $out;
    }

    /**
     * One organisation, with the slugs of its free packages, or null when the registry doesn't list it.
     *
     * @return array{id: string, name: string, verified: ?string, homepage: ?string, packages: list<string>}|null
     * @throws RegistryException when the organisation's file can't be fetched or read
     */
    public function organisation(string $id): ?array
    {
        $entry = $this->client?->index()?->organisations[$id] ?? null;
        if ($this->client === null || $entry === null) {
            return null;
        }
        $file = $this->client->organisation($id);
        if ($file === null) {
            return null;
        }
        $packages = array_column(array_filter($this->packages(), static fn(array $p): bool => $p['organisation'] === $id), 'slug');

        return ['id' => $id, 'name' => $file->name, 'verified' => $file->verifiedDomain, 'homepage' => $file->homepage, 'packages' => $packages];
    }

    /**
     * Every advisory, newest first. type is plugin, theme or core (package "core").
     *
     * @return list<array{id: string, type: string, package: string, affected: string, fixed: ?string, severity: string, title: string, url: string, published_at: int}>
     * @throws RegistryException when the advisories file can't be fetched or read
     */
    public function advisories(): array
    {
        if ($this->client?->index() === null) {
            return [];
        }
        $all = $this->client->advisories()->all;
        usort($all, static fn(array $a, array $b): int => [$b['published_at'], $b['id']] <=> [$a['published_at'], $a['id']]);

        return $all;
    }

    /**
     * The registry's categories, id => label in the site's language: the ones the registry's rules
     * name (rules/categories.json there), then any other a listed package uses, labelled with its id
     * until Kestello knows it. Plugins → Add new shows the same labels.
     *
     * @return array<string, string>
     */
    public function categories(): array
    {
        $labels = [
            'content' => Translator::get('Content'),
            'design' => Translator::get('Design and layout'),
            'forms' => Translator::get('Forms and surveys'),
            'commerce' => Translator::get('Shops and payments'),
            'marketing' => Translator::get('Marketing and search'),
            'media' => Translator::get('Pictures and media'),
            'people' => Translator::get('Members and accounts'),
            'integrations' => Translator::get('Other services'),
            'developer' => Translator::get('Developer tools'),
            'theme' => Translator::get('Themes'),
        ];
        foreach ($this->packages() as $package) {
            foreach ($package['categories'] as $id) {
                $labels[$id] ??= $id;
            }
        }

        return $labels;
    }

    /**
     * @param array{slug: string, type: string, name: string, summary: string, organisation: string, categories: list<string>, icon: ?string, stable: ?string, prerelease: ?string, requires_core: ?string, updated_at: int} $entry
     * @return array{slug: string, type: string, name: string, summary: string, organisation: string, categories: list<string>, icon: ?string, stable: ?string, prerelease: ?string, requires_core: ?string, updated_at: int}
     */
    private function summary(array $entry): array
    {
        return [
            'slug' => $entry['slug'],
            'type' => $entry['type'],
            'name' => $entry['name'],
            'summary' => $entry['summary'],
            'organisation' => $entry['organisation'],
            'categories' => $entry['categories'],
            'icon' => $entry['icon'] !== null && $this->client !== null ? $this->client->url($entry['icon']) : null,
            'stable' => $entry['stable'],
            'prerelease' => $entry['prerelease'],
            'requires_core' => $entry['requires_core'],
            'updated_at' => $entry['updated_at'],
        ];
    }
}
