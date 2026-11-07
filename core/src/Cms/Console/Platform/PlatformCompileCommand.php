<?php

declare(strict_types=1);

namespace Kestello\Cms\Console\Platform;

final class PlatformCompileCommand extends PlatformCommand
{
    protected string $name = 'platform:compile';
    protected string $description = 'Rebuild storage/framework/platform.php from the database (run on each server after deploys)';

    public function handle(array $arguments, array $options): int
    {
        if (!$this->manager()->config()->compile()) {
            $this->output->error('  Could not read platform settings from the database.');
            return 1;
        }
        $this->output->success('  Platform settings compiled.');

        return 0;
    }
}
