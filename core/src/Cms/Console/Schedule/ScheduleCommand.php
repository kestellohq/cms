<?php

declare(strict_types=1);

namespace Kestello\Cms\Console\Schedule;

use Kestello\Cms\Cms;
use Kestello\Cms\Schedule\ScheduleRunner;
use Kestello\Framework\Console\Command;

/** @internal */
abstract class ScheduleCommand extends Command
{
    protected function cms(): Cms
    {
        return Cms::instance() ?? throw new \RuntimeException('The CMS is not booted.');
    }

    protected function runner(): ScheduleRunner
    {
        return $this->cms()->container->make(ScheduleRunner::class);
    }

    /** @param array<string, string> $results */
    protected function report(array $results): void
    {
        foreach ($results as $name => $status) {
            $line = "  {$status}: {$name}";
            $status === 'failed' ? $this->output->error($line) : $this->output->line($line);
        }
    }
}
