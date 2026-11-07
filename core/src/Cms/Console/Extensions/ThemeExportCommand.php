<?php

declare(strict_types=1);

namespace Kestello\Cms\Console\Extensions;

use Kestello\Cms\Cms;
use Kestello\Cms\Design\ThemePackages;
use Kestello\Framework\Console\Command;

/** theme:export <slug> [--out=file.zip] [--no-site] */
final class ThemeExportCommand extends Command
{
    protected string $name = 'theme:export';
    protected string $description = 'Export a theme made in the admin as a zip (with the site\'s changes when it is running)';

    public function handle(array $arguments, array $options): int
    {
        $slug = $this->argument($arguments, 0);
        if ($slug === null) {
            $this->output->error('  Usage: theme:export <slug> [--out=file.zip] [--no-site]');
            return 1;
        }
        $cms = Cms::instance() ?? throw new \RuntimeException('Not booted.');
        try {
            $tmp = $cms->container->make(ThemePackages::class)->export($slug, !$this->hasFlag($options, 'no-site'));
        } catch (\Throwable $e) {
            $this->output->error('  ' . $e->getMessage());
            return 1;
        }
        $out = $this->option($options, 'out') ?? getcwd() . '/' . $slug . '-' . gmdate('Y-m-d') . '.zip';
        if (!@rename($tmp, $out) && !(@copy($tmp, $out) && @unlink($tmp))) {
            @unlink($tmp);
            $this->output->error("  Could not write {$out}.");
            return 1;
        }
        $this->output->success("  Exported {$slug} → {$out} (" . round(filesize($out) / 1024) . ' KB)');

        return 0;
    }
}
