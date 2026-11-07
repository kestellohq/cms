<?php

declare(strict_types=1);

namespace Kestello\Cms\Console\Package;

use Kestello\Cms\Cms;
use Kestello\Cms\Package\Update\UpdateProbe;
use Kestello\Framework\Console\Command;

/** @internal update:probe <owner>: the update pipeline's health check, run in a fresh process. Prints one JSON line. */
final class UpdateProbeCommand extends Command
{
    protected string $name = 'update:probe';
    protected string $description = 'Health check used by the update pipeline (prints JSON)';
    protected bool $hidden = true;

    public function handle(array $arguments, array $options): int
    {
        $owner = (string) $this->argument($arguments, 0, '');
        if (preg_match(UpdateProbe::OWNER, $owner) !== 1) {
            fwrite(STDOUT, json_encode(['probe_error' => 'Unknown package.']) . "\n");
            return 1;
        }
        $cms = Cms::instance();
        if ($cms === null) {
            fwrite(STDOUT, json_encode(['probe_error' => 'The site did not boot.']) . "\n");
            return 1;
        }
        // booting already ran a package's pending migrations and upgrade(); core's run here
        $failed = UpdateProbe::prepare($cms, $owner);
        fwrite(STDOUT, json_encode($failed !== null ? ['probe_error' => $failed] : UpdateProbe::report($cms, $owner)) . "\n");

        return 0;
    }
}
