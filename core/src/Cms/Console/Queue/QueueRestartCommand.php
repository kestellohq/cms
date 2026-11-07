<?php

declare(strict_types=1);

namespace Kestello\Cms\Console\Queue;

use Kestello\Cms\Queue\Worker;

final class QueueRestartCommand extends QueueCommand
{
    protected string $name = 'queue:restart';
    protected string $description = 'Ask running workers to exit after their current job (run after deploys)';

    public function handle(array $arguments, array $options): int
    {
        Worker::requestRestart($this->cache());
        $this->output->success('  Workers will restart after their current job.');

        return 0;
    }
}
