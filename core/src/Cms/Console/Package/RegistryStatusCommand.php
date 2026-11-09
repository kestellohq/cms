<?php

declare(strict_types=1);

namespace Kestello\Cms\Console\Package;

use Kestello\Cms\Cms;
use Kestello\Cms\Package\Registry\RegistryClient;
use Kestello\Framework\Console\Command;

/** registry:status [--refresh]: where this site's copy of the plugin and theme registry stands. */
final class RegistryStatusCommand extends Command
{
    protected string $name = 'registry:status';
    protected string $description = 'Show this site\'s copy of the plugin and theme registry (--refresh fetches it first)';

    public function handle(array $arguments, array $options): int
    {
        $cms = Cms::instance() ?? throw new \RuntimeException('Not booted.');
        if (!(bool) $cms->config->get('registry.enabled', true)) {
            $this->output->line('  The registry is turned off (registry.enabled).');

            return 0;
        }
        $client = $cms->container->make(RegistryClient::class);
        $status = isset($options['refresh']) ? $client->refresh() : $client->status();
        $this->output->label('Registry', (string) $cms->config->get('registry.url'));
        $this->output->label('Checked', $status['checked_at'] === null ? 'never' : gmdate('Y-m-d H:i', $status['checked_at']) . ' UTC');
        if ($status['index_serial'] !== null) {
            $this->output->label('List', "number {$status['index_serial']}, key list number {$status['keys_serial']}");
        }
        if ($status['expires_at'] !== null) {
            $this->output->label('Valid until', gmdate('Y-m-d H:i', $status['expires_at']) . ' UTC');
            $this->output->label('Packages', (string) $status['packages']);
        }
        if ($status['error'] !== null) {
            $this->output->error('  ' . $status['error']);

            return 1;
        }

        return 0;
    }
}
