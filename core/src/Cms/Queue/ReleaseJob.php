<?php

declare(strict_types=1);

namespace Kestello\Cms\Queue;

use RuntimeException;

/**
 * Thrown by job middleware (or handle()) to postpone a job: it goes back on
 * its queue after $delay seconds and the attempt is not counted.
 *
 * @api
 */
final class ReleaseJob extends RuntimeException
{
    public function __construct(public readonly int $delay = 10, string $reason = '')
    {
        parent::__construct($reason !== '' ? $reason : "Released for {$delay}s.");
    }
}
