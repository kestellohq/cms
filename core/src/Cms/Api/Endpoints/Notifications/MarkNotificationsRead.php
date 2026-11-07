<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Notifications;

use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Api\Endpoint;
use Kestello\Cms\Api\Schema;
use Kestello\Cms\Cms;
use Kestello\Framework\Core\Response;

/** Mark one notification (body "id") or all of them as read. */
final class MarkNotificationsRead extends Endpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::post('/me/notifications/read');
    }
    public function capability(): ?string
    {
        return 'profile.manage';
    }
    public function scopes(): array
    {
        return ['profile'];
    }
    public function summary(): string
    {
        return 'Mark notifications as read';
    }
    public function tags(): array
    {
        return ['me'];
    }

    public function body(): ?Schema
    {
        return Schema::object(['id' => Schema::string()->max(36)->optional()]);
    }

    public function handle(ApiRequest $request): mixed
    {
        $notifier = (Cms::instance() ?? throw new \RuntimeException('Not booted.'))->notifications;
        $userId = (int) $request->userId();
        $id = $request->input('id');
        $marked = $notifier->markRead($userId, is_string($id) && $id !== '' ? $id : null);

        return Response::json(['data' => ['marked' => $marked, 'unread' => $notifier->unreadCount($userId)]]);
    }
}
