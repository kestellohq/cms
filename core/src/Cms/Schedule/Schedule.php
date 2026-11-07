<?php

declare(strict_types=1);

namespace Kestello\Cms\Schedule;

use Closure;
use InvalidArgumentException;
use Kestello\Contracts\Queue\Job;
use Kestello\Framework\Core\ExecutionContext;

/**
 * $cms->schedule: recurring tasks. One cron entry runs them all
 * (`* * * * * php bin/cms schedule:run`); without cron, the web runner or
 * an external pinger of /system/cron does.
 *
 *   $cms->schedule->call(fn() => $feeds->refresh(), name: 'acme.feeds')->everyThirtyMinutes();
 *   $cms->schedule->job(new SendDigests())->dailyAt('07:00');        // pushed to the queue when due
 *   $cms->schedule->command('acme:sync --changed')->hourly();        // a registered plugin command
 *
 * Plugin task names must start with the plugin slug ("acme.feeds", "acme:sync").
 *
 * @api
 */
final class Schedule
{
    /** @var array<string, ScheduledTask> */
    private array $tasks = [];

    /** @param Closure(): mixed $callback */
    public function call(Closure $callback, string $name): ScheduledTask
    {
        return $this->add($name, 'call', $callback);
    }

    public function job(Job $job, ?string $name = null): ScheduledTask
    {
        $name ??= strtolower((string) preg_replace('/(?<!^)[A-Z]/', '-$0', (new \ReflectionClass($job))->getShortName()));
        if (ExecutionContext::current() !== 'core' && !str_contains($name, '.') && !str_contains($name, ':')) {
            $name = explode(':', ExecutionContext::current(), 2)[1] . '.' . $name;
        }

        return $this->add($name, 'job', $job);
    }

    public function command(string $commandLine, ?string $name = null): ScheduledTask
    {
        $commandLine = trim($commandLine);
        if ($commandLine === '') {
            throw new InvalidArgumentException('Empty command.');
        }

        return $this->add($name ?? $commandLine, 'command', $commandLine);
    }

    /** @return array<string, ScheduledTask> */
    public function tasks(): array
    {
        return $this->tasks;
    }

    public function get(string $name): ?ScheduledTask
    {
        return $this->tasks[$name] ?? null;
    }

    private function add(string $name, string $type, object|string $target): ScheduledTask
    {
        $owner = ExecutionContext::current();
        if (preg_match('/^[a-z0-9][^\x00-\x1f\x7f]{0,190}$/i', $name) !== 1) {
            throw new InvalidArgumentException("Invalid task name [{$name}].");
        }
        if ($owner !== 'core') {
            $slug = explode(':', $owner, 2)[1] ?? '';
            if (!str_starts_with($name, $slug . '.') && !str_starts_with($name, $slug . ':')) {
                throw new InvalidArgumentException("{$owner} task names must start with \"{$slug}.\" or \"{$slug}:\", got [{$name}].");
            }
        }
        if (isset($this->tasks[$name])) {
            throw new InvalidArgumentException("Task [{$name}] is already scheduled by {$this->tasks[$name]->owner}.");
        }

        return $this->tasks[$name] = new ScheduledTask($name, $type, $target, $owner);
    }
}
