<?php

declare(strict_types=1);

namespace Kestello\Cms\Console;

use Kestello\Cms\Auth\TwoFactor;
use Kestello\Cms\Cms;
use Kestello\Cms\Events\TwoFactorDisabled;
use Kestello\Framework\Console\Command;
use Kestello\Framework\Database\QueryBuilder;
use Kestello\Support\AppKey;

/**
 * Turn off two-factor authentication for someone who lost their device
 * and recovery codes. Audit-logged.
 *
 *   php bin/cms auth:reset-2fa ana@example.com
 */
final class ResetTwoFactorCommand extends Command
{
    protected string $name = 'auth:reset-2fa';
    protected string $description = 'Disable two-factor authentication for a user: auth:reset-2fa <username|email>';

    public function handle(array $arguments, array $options): int
    {
        $identifier = $this->argument($arguments, 0);
        if ($identifier === null) {
            $this->output->error('Usage: auth:reset-2fa <username|email>');
            return 1;
        }

        $user = QueryBuilder::table('users')->whereAny(['username', 'email'], '=', str_contains($identifier, '@') ? mb_strtolower($identifier) : $identifier)->first();
        if ($user === null) {
            $this->output->error("User \"{$identifier}\" not found.");
            return 1;
        }

        $twoFactor = new TwoFactor(static fn() => AppKey::secretStore());
        if (!$twoFactor->disable((int) $user['id'])) {
            $this->output->line("  {$user['username']} doesn't use two-factor authentication.");
            return 0;
        }

        Cms::instance()?->events->dispatch(new TwoFactorDisabled((int) $user['id'], null));
        $this->output->success("  Two-factor authentication disabled for {$user['username']}. Ask them to set it up again.");

        return 0;
    }
}
