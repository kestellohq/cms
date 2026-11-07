<?php

declare(strict_types=1);

namespace Kestello\Cms\System;

use Kestello\Cms\Cms;
use Kestello\Cms\Schedule\ScheduleRunner;
use Kestello\Framework\Core\Request;
use Kestello\Framework\Core\Response;

/**
 * POST|GET /system/cron, for an external cron service when the host has no
 * cron. Send the token (bin/cms cron:token) as "Authorization: Bearer …"
 * or "X-Cron-Token"; "?token=" works for services that can't set headers,
 * but puts the token in access logs. Runs at most once every 50 seconds.
 */
final class CronController
{
    public function run(Request $request): Response
    {
        $token = (string) (preg_replace('/^Bearer\s+/i', '', $request->header('Authorization')) ?: $request->header('X-Cron-Token'));
        if ($token === '') {
            $token = (string) $request->input('token', '');
        }
        if (!CronToken::configured()) {
            return Response::json(['error' => 'The cron endpoint is off. Run "bin/cms cron:token" to enable it.'], 404);
        }
        if (!CronToken::verify($token)) {
            return Response::json(['error' => 'Invalid token.'], 403);
        }

        $cms = Cms::instance() ?? throw new \RuntimeException('Not booted.');
        if (!CoreTasks::cache($cms)->add('cron:http', time(), 50)) {
            return Response::json(['status' => 'skipped', 'reason' => 'Ran less than 50 seconds ago.'], 202);
        }

        ignore_user_abort(true);
        @set_time_limit(90);
        $results = $cms->container->make(ScheduleRunner::class)->run(via: 'http', budget: max(5, (int) $cms->config->get('cron.http_seconds', 25)));

        return Response::json(['status' => 'ok', 'tasks' => $results]);
    }
}
