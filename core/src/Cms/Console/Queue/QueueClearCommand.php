<?php

declare(strict_types=1);

namespace Kestello\Cms\Console\Queue;

final class QueueClearCommand extends QueueCommand
{
    protected string $name = 'queue:clear';
    protected string $description = 'Delete waiting jobs: queue:clear <queue|all> --yes';

    public function handle(array $arguments, array $options): int
    {
        $which = $this->argument($arguments, 0);
        if ($which === null || !$this->hasFlag($options, 'yes')) {
            $this->output->error('  Usage: queue:clear <queue|all> --yes   (waiting jobs are deleted, not run)');
            return 1;
        }
        $count = $this->queue()->backend()->clear($which === 'all' ? null : $which);
        $this->output->success("  Deleted {$count} waiting job(s).");

        return 0;
    }
}
