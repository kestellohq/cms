<?php

declare(strict_types=1);

namespace Kestello\Cms\Console\Make;

use Kestello\Cms\Extension\Manifest;
use Kestello\Cms\Extension\ManifestException;
use Kestello\Cms\Extension\Package;
use Kestello\Cms\Extension\PackageType;
use Kestello\Framework\Console\Command;
use Kestello\Framework\Core\Paths;

/**
 * Base for code generators. Code is generated into a plugin
 * (--plugin=<slug>, required), never into core.
 */
abstract class GeneratorCommand extends Command
{
    /**
     * @var array{slug: string, root: string, rel: string, src: string, namespace: string, prefix: string}
     */
    protected array $plugin;

    public function __construct(
        protected readonly Paths $paths,
    ) {}

    /**
     * Resolve the target plugin from --plugin. Prints an error and returns false if unusable.
     *
     * @param array<string, string> $options
     */
    protected function target(array $options): bool
    {
        $slug = (string) ($this->option($options, 'plugin', '') ?? '');
        if ($slug === '') {
            $this->output->error('  Say which plugin the file goes into: --plugin=<slug>.');
            $this->output->muted('  No plugin yet? Create one first: php bin/cms make:plugin <slug>');
            return false;
        }
        $root = $this->paths->plugins() . '/' . $slug;

        try {
            $manifest = Manifest::load($root, PackageType::Plugin);
        } catch (ManifestException $e) {
            $this->output->error("  Plugin \"{$slug}\" not found or invalid.");
            $this->output->muted("  Create it first: php bin/cms make:plugin {$slug}");
            return false;
        }

        if ($manifest->namespace === null) {
            $this->output->error("  Plugin \"{$slug}\" declares no namespace in plugin.json.");
            return false;
        }

        $package = new Package($manifest, $root);

        $this->plugin = [
            'slug' => $slug,
            'root' => $root,
            'rel' => 'content/plugins/' . $slug,
            'src' => $package->srcPath(),
            'namespace' => $manifest->namespace,
            'prefix' => $package->tablePrefix(),
        ];

        return true;
    }
}
