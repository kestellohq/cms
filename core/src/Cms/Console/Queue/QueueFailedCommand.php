<?php

declare(strict_types=1);

namespace Kestello\Cms\Console\Queue;

use Kestello\Cms\Queue\JobQueue;
use Kestello\Framework\Database\QueryBuilder;
use Kestello\Support\Json;

/** queue:failed [id] — list failed jobs, or show one (payload secrets masked). */
final class QueueFailedCommand extends QueueCommand
{
    protected string $name = 'queue:failed';
    protected string $description = 'List failed jobs, or show one: queue:failed [id]';

    public function handle(array $arguments, array $options): int
    {
        $id = $this->argument($arguments, 0);
        if ($id !== null) {
            $row = QueryBuilder::table('failed_jobs')->where('id', (int) $id)->first();
            if ($row === null) {
                $this->output->error("  No failed job {$id}.");
                return 1;
            }
            $this->output->line("  {$row['job']} on {$row['queue']} (owner {$row['owner']}), {$row['attempts']} attempt(s), failed " . date('Y-m-d H:i:s', (int) $row['failed_at']));
            $this->output->line('  Payload: ' . Json::encode(JobQueue::displayPayload((string) $row['payload'])));
            $this->output->line('  ' . str_replace("\n", "\n  ", (string) $row['exception']));
            return 0;
        }

        $rows = array_map(static fn(array $r) => [
            $r['id'], $r['job'], $r['queue'], $r['owner'], date('Y-m-d H:i', (int) $r['failed_at']),
            mb_strimwidth(strtok((string) $r['exception'], "\n") ?: '', 0, 70, '…'),
        ], QueryBuilder::table('failed_jobs')->orderByDesc('id')->limit(100)->get());

        if ($rows === []) {
            $this->output->success('  No failed jobs.');
            return 0;
        }
        $this->output->table(['ID', 'Job', 'Queue', 'Owner', 'Failed', 'Error'], $rows);

        return 0;
    }
}
