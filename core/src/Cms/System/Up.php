<?php

declare(strict_types=1);

namespace Kestello\Cms\System;

use Closure;

/**
 * GET /up: whether this site can answer requests, for a load balancer, an
 * uptime monitor or Docker's HEALTHCHECK. It runs before the session, the
 * plugins and the routes, and says nothing a visitor shouldn't see.
 *
 *   200 {"status":"ok", ...}           PHP runs and the database answers
 *   503 {"status":"unavailable", ...}  the database does not answer
 *
 * A site that isn't installed yet, or is in maintenance, is up: it is
 * answering as it should, and a container restarted for it would only
 * interrupt the installer or the update.
 */
final class Up
{
    public const string PATH = '/up';

    /**
     * @param ?Closure(): void $database runs a statement on the database; throws when it can't
     * @return array{0: int, 1: array<string, bool|string>} the status code and the body
     */
    public static function check(bool $installed, bool $maintenance, ?Closure $database): array
    {
        if (!$installed) {
            return [200, ['status' => 'ok', 'installed' => false]];
        }
        try {
            if ($database === null) {
                throw new \RuntimeException('no database is configured');
            }
            $database();
        } catch (\Throwable) {
            // the reason goes nowhere public: the site's own logs and Site Health have it
            return [503, ['status' => 'unavailable', 'database' => false]];
        }

        return [200, ['status' => 'ok', 'database' => true, 'maintenance' => $maintenance]];
    }
}
