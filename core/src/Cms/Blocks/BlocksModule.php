<?php

declare(strict_types=1);

namespace Kestello\Cms\Blocks;

use Kestello\Cms\Cms;
use Kestello\Cms\Extension\Provider;

/** Core module: the core blocks. */
final class BlocksModule extends Provider
{
    public function boot(Cms $cms): void
    {
        CoreBlocks::register($cms);
    }
}
