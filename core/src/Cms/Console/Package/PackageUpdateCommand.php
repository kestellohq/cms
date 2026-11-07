<?php

declare(strict_types=1);

namespace Kestello\Cms\Console\Package;

use Kestello\Cms\Cms;
use Kestello\Cms\Content\ContentException;
use Kestello\Cms\Package\Update\Updater;
use Kestello\Framework\Console\Command;

/** package:update <zip> [--downgrade]: backup, swap, health check, roll back by itself when something is wrong. */
final class PackageUpdateCommand extends Command
{
    protected string $name = 'package:update';
    protected string $description = 'Update an installed plugin or theme from a zip (backup, health check, automatic rollback): package:update <zip> [--downgrade]';

    public function handle(array $arguments, array $options): int
    {
        $zip = $this->argument($arguments, 0);
        if ($zip === null || !is_file($zip)) {
            $this->output->error('  Usage: package:update <zip> [--downgrade]');
            return 1;
        }
        $cms = Cms::instance() ?? throw new \RuntimeException('Not booted.');
        $updater = $cms->container->make(Updater::class);
        $opts = ['allow_unsigned' => true, 'allow_downgrade' => $this->hasFlag($options, 'downgrade')];
        try {
            $problems = $updater->check((string) realpath($zip), $opts)['problems'];
            if ($problems !== []) {
                $this->output->error('  ' . implode("\n  ", $problems));
                return 1;
            }
            $this->output->muted('  Backing up, updating and checking the site…');
            $r = $updater->update((string) realpath($zip), $opts);
        } catch (ContentException $e) {
            $this->output->error('  ' . implode("\n  ", $e->errors !== [] ? array_merge(...array_values($e->errors)) : [$e->getMessage()]));
            return 1;
        }
        if ($r['ok']) {
            $this->output->success("  Updated {$r['slug']} {$r['from']} → {$r['to']}. Backup: {$r['backup']}");
            return 0;
        }
        $this->output->error("  The update to {$r['to']} failed its check:");
        foreach ($r['problems'] as $p) {
            $this->output->line("    {$p}");
        }
        if ($r['rolled_back']) {
            $this->output->warning("  {$r['slug']} {$r['from']} and the database were put back.");
        } else {
            foreach ($r['rollback_problems'] as $p) {
                $this->output->error("  {$p}");
            }
        }

        return 1;
    }
}
