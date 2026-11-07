<?php

declare(strict_types=1);

namespace Kestello\Cms\Console;

use Kestello\Cms\Auth\PasswordResets;
use Kestello\Cms\Cms;
use Kestello\Framework\Console\Command;
use Kestello\Framework\Database\QueryBuilder;

/**
 * Delete audit entries older than --days (default 365) and expired
 * password-reset tokens. The scheduler will run this daily.
 */
final class AuditPruneCommand extends Command
{
    protected string $name = 'audit:prune';
    protected string $description = 'Delete old audit log entries and expired reset tokens [--days=365]';

    public function handle(array $arguments, array $options): int
    {
        $days = max(1, (int) ($options['days'] ?? 365));
        $audit = Cms::instance()?->audit ?? new \Kestello\Cms\Audit\AuditLog();

        $entries = $audit->prune($days);
        $tokens = QueryBuilder::table(PasswordResets::TABLE)->where('expires_at', '<', time())->delete();

        $this->output->success("  Removed {$entries} audit entr" . ($entries === 1 ? 'y' : 'ies') . " older than {$days} days and {$tokens} expired reset token(s).");

        return 0;
    }
}
