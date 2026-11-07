<?php

declare(strict_types=1);

namespace Kestello\Cms\Console\I18n;

use Kestello\Cms\Cms;
use Kestello\Cms\I18n\Extractor;
use Kestello\Framework\Console\Command;
use Kestello\Framework\Core\Paths;
use Kestello\Kernel;

/**
 * i18n:extract [<plugin or theme slug>] [--path=<folder> --domain=<slug>] [--check]
 *
 * Writes the translation template: core's to core/resources/lang/kestello.pot,
 * a package's to <package>/resources/lang/<slug>.pot. --path reads any folder
 * (a plugin in its own repository). --check writes nothing and fails when the
 * committed template is out of date (CI). Core and --path work on a checkout
 * that was never installed; a package by slug needs the site.
 */
final class I18nExtractCommand extends Command
{
    protected string $name = 'i18n:extract';
    protected string $description = 'Write a translation template (.pot): i18n:extract [<plugin or theme>] [--path=<folder> --domain=<slug>] [--check]';

    public function __construct(private readonly Paths $paths) {}

    public function handle(array $arguments, array $options): int
    {
        try {
            [$root, $dir, $domain, $project] = $this->target($this->argument($arguments, 0), $this->option($options, 'path'), $this->option($options, 'domain'));
            $file = $dir . '/resources/lang/' . ($domain === 'core' ? 'kestello' : $domain) . '.pot';
            $pot = (new Extractor())->scan($root, $dir)->pot($project, $domain);
        } catch (\Throwable $e) {
            $this->output->error('  ' . $e->getMessage());
            return 1;
        }
        $count = substr_count($pot, "\nmsgid ") - 1;

        if ($this->option($options, 'check') !== null) {
            if (!is_file($file) || file_get_contents($file) !== $pot) {
                $this->output->error("  {$file} is out of date ({$count} messages in the code). Run: bin/cms i18n:extract" . ($domain === 'core' ? '' : " {$domain}"));
                return 1;
            }
            $this->output->success("  {$file} is up to date ({$count} messages).");
            return 0;
        }

        if (!is_dir(dirname($file)) && !mkdir(dirname($file), 0775, true) && !is_dir(dirname($file))) {
            $this->output->error('  Could not create ' . dirname($file));
            return 1;
        }
        file_put_contents($file, $pot);
        $this->output->success("  {$file} ({$count} messages)");

        return 0;
    }

    /** @return array{0: string, 1: string, 2: string, 3: string} root for paths, folder to read, domain, project name */
    private function target(?string $slug, mixed $path, mixed $domain): array
    {
        if (is_string($path) && $path !== '') {
            $dir = realpath($path) ?: throw new \InvalidArgumentException("There is no folder {$path}.");
            $name = is_string($domain) && $domain !== '' ? $domain : basename($dir);

            return [$dir, $dir, $name, $name];
        }
        if ($slug === null) {
            return [dirname($this->paths->core()), $this->paths->core(), 'core', 'Kestello ' . Kernel::version()];
        }
        $cms = Cms::instance() ?? throw new \InvalidArgumentException("Finding \"{$slug}\" needs an installed site. Use --path=<its folder> --domain={$slug} instead.");
        $package = $cms->extensions()->plugins()[$slug] ?? $cms->extensions()->themes()[$slug]
            ?? throw new \InvalidArgumentException("No plugin or theme is called \"{$slug}\".");

        return [$package->path(), $package->path(), $slug, $package->manifest->name . ' ' . $package->manifest->version];
    }
}
