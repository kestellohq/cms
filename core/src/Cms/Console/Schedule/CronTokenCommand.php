<?php

declare(strict_types=1);

namespace Kestello\Cms\Console\Schedule;

use Kestello\Cms\Auth\AuthModule;
use Kestello\Cms\System\CronToken;

final class CronTokenCommand extends ScheduleCommand
{
    protected string $name = 'cron:token';
    protected string $description = 'Enable POST /system/cron for an external cron service (new token each time; --revoke turns it off)';

    public function handle(array $arguments, array $options): int
    {
        if ($this->hasFlag($options, 'revoke')) {
            CronToken::revoke();
            $this->output->success('  The cron endpoint is off.');
            return 0;
        }

        $token = CronToken::issue();
        $url = rtrim(AuthModule::site($this->cms())[1], '/') . '/system/cron';
        $this->output->success('  New cron token (shown once; any older token stops working):');
        $this->output->line('  ' . $token);
        $this->output->line('');
        $this->output->line("  Have a cron service call, every minute:  POST {$url}");
        $this->output->line('  with the header:  Authorization: Bearer <token>');

        return 0;
    }
}
