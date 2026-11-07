<?php

declare(strict_types=1);

namespace Kestello\Cms\Console\Package;

use Kestello\Cms\Cms;
use Kestello\Cms\Content\ContentException;
use Kestello\Cms\Package\Update\CoreUpdater;
use Kestello\Framework\Console\Command;

/** core:update <zip> [--downgrade] | core:update --status */
final class CoreUpdateCommand extends Command
{
    protected string $name = 'core:update';
    protected string $description = 'Update Kestello from a release zip (backup, health check, automatic rollback): core:update <zip> [--downgrade], or --status';

    public function handle(array $arguments, array $options): int
    {
        $cms = Cms::instance() ?? throw new \RuntimeException('Not booted.');
        $core = $cms->container->make(CoreUpdater::class);
        if ($this->hasFlag($options, 'status')) {
            $s = $core->status();
            $this->output->line("  Kestello {$s['version']} (" . ($s['mode'] === 'development' ? 'development checkout: core/ runs directly' : 'release folders') . ')');
            foreach ($s['releases'] as $r) {
                $this->output->line('    ' . ($r['current'] ? '* ' : '  ') . $r['version']);
            }
            return 0;
        }
        $zip = $this->argument($arguments, 0);
        if ($zip === null || !is_file($zip)) {
            $this->output->error('  Usage: core:update <zip> [--downgrade] | core:update --status');
            return 1;
        }
        $opts = ['allow_unsigned' => true, 'allow_downgrade' => $this->hasFlag($options, 'downgrade')];
        try {
            $check = $core->check((string) realpath($zip), $opts);
            if ($check['problems'] !== []) {
                $this->output->error('  ' . implode("\n  ", $check['problems']));
                return 1;
            }
            $this->output->muted("  Backing up, switching to {$check['version']} and checking the site…");
            $r = $core->update((string) realpath($zip), $opts);
        } catch (ContentException $e) {
            $this->output->error('  ' . implode("\n  ", $e->errors !== [] ? array_merge(...array_values($e->errors)) : [$e->getMessage()]));
            return 1;
        }
        if ($r['ok']) {
            $this->output->success("  Kestello {$r['from']} → {$r['to']}. Backup: {$r['backup']}");
            return 0;
        }
        $this->output->error("  {$r['to']} failed its check:");
        foreach ($r['problems'] as $p) {
            $this->output->line("    {$p}");
        }
        if ($r['rolled_back']) {
            $this->output->warning("  Switched back to {$r['from']} and restored the database.");
        } else {
            foreach ($r['rollback_problems'] as $p) {
                $this->output->error("  {$p}");
            }
        }

        return 1;
    }
}
