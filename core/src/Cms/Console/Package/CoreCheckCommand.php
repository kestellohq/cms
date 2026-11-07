<?php

declare(strict_types=1);

namespace Kestello\Cms\Console\Package;

use Kestello\Cms\Cms;
use Kestello\Cms\Package\Update\UpdateChecker;
use Kestello\Framework\Console\Command;

/** core:check: ask GitHub whether a new Kestello version is out (config/release.php). Installs nothing. */
final class CoreCheckCommand extends Command
{
    protected string $name = 'core:check';
    protected string $description = 'Check GitHub for a new Kestello version (installs nothing; apply one with core:update or under Updates)';

    public function handle(array $arguments, array $options): int
    {
        $cms = Cms::instance() ?? throw new \RuntimeException('Not booted.');
        $checker = $cms->container->make(UpdateChecker::class);
        $result = $checker->check();
        $this->output->label('Running', $result['running']);
        if ($result['error'] !== null) {
            $this->output->error('  ' . $result['error']);
            return 1;
        }
        if ($result['latest'] === null) {
            $this->output->success('  Kestello is up to date.');
            return 0;
        }
        $this->output->label('Available', "{$result['latest']['version']} ({$result['latest']['page']})");
        $this->output->line('  Apply it under System → Updates, or download the zip and run bin/cms core:update <zip>.');

        return 0;
    }
}
