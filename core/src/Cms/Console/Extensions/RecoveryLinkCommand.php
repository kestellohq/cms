<?php

declare(strict_types=1);

namespace Kestello\Cms\Console\Extensions;

use Kestello\Cms\Extension\Recovery;
use Kestello\Framework\Console\Command;
use Kestello\Framework\Core\Config;
use Throwable;

/**
 * recovery:link [--minutes=60]
 */
final class RecoveryLinkCommand extends Command
{
    protected string $name = 'recovery:link';
    protected string $description = 'Create a signed link that opens the site with all plugins disabled';

    public function __construct(
        private readonly Config $config,
    ) {}

    public function handle(array $arguments, array $options): int
    {
        $minutes = max(5, min(1440, (int) ($this->option($options, 'minutes', '60') ?? 60)));

        try {
            $recovery = new Recovery((string) $this->config->get('app.secret_key', ''));
        } catch (Throwable $e) {
            $this->output->error('  ' . $e->getMessage());
            return 1;
        }

        $base = rtrim((string) $this->config->get('app.url', ''), '/');
        $url = $base . '/admin?' . Recovery::QUERY . '=' . $recovery->createToken($minutes * 60);

        $this->output->success('  Recovery link (valid ' . $minutes . ' minutes):');
        $this->output->line('  ' . $url);
        $this->output->muted('  Plugins stay disabled and the base theme is used while the recovery cookie is set.');

        return 0;
    }
}
