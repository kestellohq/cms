<?php

declare(strict_types=1);

namespace Kestello\Cms\Console\Demo;

use Kestello\Cms\Cms;
use Kestello\Cms\Demo\Demo;
use Kestello\Cms\Demo\DemoSite;
use Kestello\Framework\Console\Command;

/**
 * demo:snapshot and demo:reset: the server side of a public demo. Nobody
 * can manage a demo from its admin, so its owner does it here.
 */
final class DemoCommand extends Command
{
    public function __construct(private readonly string $action)
    {
        $this->name = 'demo:' . $action;
        $this->description = $action === 'snapshot'
            ? 'Take the demo snapshot from the site as it is now: every hourly reset goes back to it'
            : 'Put the demo back to its snapshot now (everything visitors changed is gone, everyone is signed out)';
    }

    public function handle(array $arguments, array $options): int
    {
        $cms = Cms::instance();
        if ($cms === null || !Demo::on($cms->config)) {
            $this->output->error('  This site is not a demo. Set \'demo\' => true in config/app.php first.');
            return 1;
        }
        $site = $cms->container->make(DemoSite::class);
        try {
            $name = $this->action === 'snapshot' ? $site->snapshot() : $site->reset();
        } catch (\Throwable $e) {
            $this->output->error('  ' . $e->getMessage());
            return 1;
        }
        $this->output->success($this->action === 'snapshot' ? "  New snapshot: {$name}. The next reset goes back to it." : "  The demo is back to {$name}.");

        return 0;
    }
}
