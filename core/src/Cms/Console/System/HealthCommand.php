<?php

declare(strict_types=1);

namespace Kestello\Cms\Console\System;

use Kestello\Cms\Cms;
use Kestello\Framework\Console\Command;
use Kestello\Support\Json;

/** health [--json] [--only=id,id]: run Site Health. Exits 2 on critical problems, for monitoring. */
final class HealthCommand extends Command
{
    protected string $name = 'health';
    protected string $description = 'Run the Site Health checks (exit code 2 when something is critical)';

    public function handle(array $arguments, array $options): int
    {
        $cms = Cms::instance() ?? throw new \RuntimeException('The CMS is not booted.');
        $only = isset($options['only']) && is_string($options['only']) ? array_values(array_filter(explode(',', $options['only']))) : null;
        $report = $cms->health->run(fresh: true, only: $only);

        if (isset($options['json'])) {
            $this->output->line(Json::encode($report, JSON_PRETTY_PRINT));
        } else {
            foreach ($report['checks'] as $c) {
                $line = sprintf('  %-12s %-26s %s', strtoupper($c['status']), $c['label'], $c['message']);
                match ($c['status']) {
                    'critical' => $this->output->error($line),
                    'recommended' => $this->output->warning($line),
                    default => $this->output->success($line),
                };
            }
            $this->output->line(sprintf('  %d good, %d recommended, %d critical.', $report['counts']['good'], $report['counts']['recommended'], $report['counts']['critical']));
        }

        return $report['status'] === 'critical' ? 2 : 0;
    }
}
