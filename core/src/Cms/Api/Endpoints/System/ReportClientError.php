<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\System;

use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Api\Endpoint;
use Kestello\Cms\Api\Schema;
use Kestello\Cms\Cms;
use Kestello\Framework\Core\ErrorHandler;
use Kestello\Framework\Log\Log;
use Kestello\Framework\Log\LogLevel;

/**
 * An error the admin hit in the browser (a screen that failed to draw, a
 * script error), so it reaches the site's log and Site Health instead of
 * staying in one person's console. Sent by the admin itself (app/errorReport.js).
 */
final class ReportClientError extends Endpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::post('/system/client-errors');
    }

    public function capability(): ?string
    {
        return 'admin.access';
    }

    /** A token must be a system one; the admin's own session is not limited by scopes. */
    public function scopes(): array
    {
        return ['system'];
    }

    public function rateLimit(): ?array
    {
        // the admin sends at most 10 per page load; this caps a loop across reloads
        return [30, 3600];
    }

    public function summary(): string
    {
        return 'Record an error the admin hit in the browser';
    }

    public function tags(): array
    {
        return ['system'];
    }

    public function body(): ?Schema
    {
        return Schema::object([
            'message' => Schema::string()->max(500),
            'screen' => Schema::string()->max(300)->description('The admin address it happened on.'),
            'where' => Schema::string()->max(200)->optional()->description('What failed: a Vue lifecycle step, "script" or "promise".'),
            'stack' => Schema::string()->max(4000)->optional(),
        ]);
    }

    public function response(): ?Schema
    {
        return Schema::object(['recorded' => Schema::bool()]);
    }

    public function handle(ApiRequest $request): mixed
    {
        $message = self::oneLine((string) $request->input('message'));
        $screen = self::oneLine((string) $request->input('screen'));
        $where = self::oneLine((string) ($request->input('where') ?? ''));
        $stack = (string) ($request->input('stack') ?? '');

        $container = Cms::instance()?->container;
        $handler = $container !== null && $container->has(ErrorHandler::class) ? $container->make(ErrorHandler::class) : null;
        $handler?->count('admin', $message . ' (' . $screen . ')');
        Log::write(LogLevel::ERROR, 'admin error: ' . $message, array_filter(['screen' => $screen, 'where' => $where, 'user' => $request->userId(), 'stack' => $stack], static fn($v) => $v !== '' && $v !== null), 'admin');

        return ['recorded' => true];
    }

    /** Line breaks out: one error, one log line, nothing that looks like another entry. */
    private static function oneLine(string $text): string
    {
        return trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $text) ?? '');
    }
}
