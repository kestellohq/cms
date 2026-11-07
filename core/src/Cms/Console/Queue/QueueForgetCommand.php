<?php

declare(strict_types=1);

namespace Kestello\Cms\Console\Queue;

use Kestello\Framework\Database\QueryBuilder;

final class QueueForgetCommand extends QueueCommand
{
    protected string $name = 'queue:forget';
    protected string $description = 'Delete failed jobs: queue:forget <id|all>';

    public function handle(array $arguments, array $options): int
    {
        $which = $this->argument($arguments, 0);
        if ($which === null) {
            $this->output->error('  Usage: queue:forget <id|all>');
            return 1;
        }
        $query = QueryBuilder::table('failed_jobs');
        if ($which !== 'all') {
            $query->where('id', (int) $which);
        }
        $count = $query->delete();
        $this->output->success("  Deleted {$count} failed job(s).");

        return 0;
    }
}
