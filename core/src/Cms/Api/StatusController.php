<?php

declare(strict_types=1);

namespace Kestello\Cms\Api;

use Kestello\Framework\Core\Request;
use Kestello\Framework\Core\Response;
use Kestello\Kernel;

/**
 * Public health endpoint. Used by uptime checks and, later, by the
 * updater's post-update health check.
 */
final class StatusController
{
    public function show(Request $request): Response
    {
        return Response::json([
            'status' => 'ok',
            'version' => Kernel::version(),
            'timestamp' => date('c'),
        ]);
    }
}
