<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\System;

use Kestello\Cms\Api\Endpoint;
use Kestello\Cms\Cms;

/** Base for /system endpoints: administrators with system.manage and the "system" scope. @internal */
abstract class SystemEndpoint extends Endpoint
{
    public function capability(): ?string
    {
        return 'system.manage';
    }
    public function scopes(): array
    {
        return ['system'];
    }
    public function tags(): array
    {
        return ['system'];
    }

    protected function cms(): Cms
    {
        return Cms::instance() ?? throw new \RuntimeException('The CMS is not booted.');
    }

    protected function platform(): \Kestello\Cms\Platform\PlatformManager
    {
        return $this->cms()->container->make(\Kestello\Cms\Platform\PlatformManager::class);
    }

    protected function runner(): \Kestello\Cms\Schedule\ScheduleRunner
    {
        return $this->cms()->container->make(\Kestello\Cms\Schedule\ScheduleRunner::class);
    }
}
