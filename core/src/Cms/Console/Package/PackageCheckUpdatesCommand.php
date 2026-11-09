<?php

declare(strict_types=1);

namespace Kestello\Cms\Console\Package;

use Kestello\Cms\Cms;
use Kestello\Cms\Package\Update\PackageUpdates;
use Kestello\Framework\Console\Command;

/** package:check-updates [<plugin:slug|theme:slug>]: ask the update sources of plugins and themes now. Installs nothing. */
final class PackageCheckUpdatesCommand extends Command
{
    protected string $name = 'package:check-updates';
    protected string $description = 'Ask the sources plugins and themes name for new versions (installs nothing): package:check-updates [<plugin:slug|theme:slug>]';

    public function handle(array $arguments, array $options): int
    {
        $cms = Cms::instance() ?? throw new \RuntimeException('Not booted.');
        $only = $this->argument($arguments, 0);
        $results = $cms->container->make(PackageUpdates::class)->check(only: $only);
        if ($results === [] || ($only !== null && !isset($results[$only]))) {
            $this->output->muted($only === null ? '  No plugin or theme says where its updates come from.' : "  {$only} is not installed, or does not say where its updates come from.");

            return $only === null ? 0 : 1;
        }
        $failed = false;
        foreach ($results as $owner => $r) {
            if ($r['error'] !== null) {
                $failed = true;
                $this->output->error("  {$owner}: {$r['error']}" . ($r['renew_url'] !== null ? " Renew: {$r['renew_url']}" : ''));
            } elseif ($r['offer'] !== null) {
                $this->output->success("  {$owner}: {$r['offer']['version']} is available.");
            } else {
                $this->output->line("  {$owner}: up to date" . ($r['needs_core'] !== null ? " ({$r['needs_core']['version']} needs Kestello {$r['needs_core']['requires_core']})" : '') . '.');
            }
        }

        return $failed ? 1 : 0;
    }
}
