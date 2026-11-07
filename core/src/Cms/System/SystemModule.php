<?php

declare(strict_types=1);

namespace Kestello\Cms\System;

use Kestello\Cms\Cms;
use Kestello\Cms\Extension\Provider;
use Kestello\Cms\Schedule\ScheduleRunner;
use Kestello\Framework\Console\Command;
use Kestello\Framework\Core\Container;

/** Core module: scheduler wiring, core tasks and the HTTP cron endpoint. */
final class SystemModule extends Provider
{
    public function register(Container $container): void
    {
        $c = $container;
        $c->singleton(ScheduleRunner::class, static function () use ($c): ScheduleRunner {
            $cms = $c->make(Cms::class);

            return new ScheduleRunner(
                $cms->schedule,
                $cms->queue,
                CoreTasks::cache($cms),
                static function (string $name) use ($cms): ?Command {
                    foreach ($cms->console->all() as ['command' => $command]) {
                        if ($command->getName() === $name) {
                            return $command;
                        }
                    }
                    return null;
                },
            );
        });
    }

    public function boot(Cms $cms): void
    {
        CoreTasks::register($cms);
        $cms->routes->post('/system/cron', [CronController::class, 'run'])->name('system.cron');
        $cms->routes->get('/system/cron', [CronController::class, 'run'])->name('system.cron.get');
    }
}
