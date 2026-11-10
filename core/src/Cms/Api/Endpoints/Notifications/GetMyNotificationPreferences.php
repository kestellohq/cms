<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Notifications;

use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Api\Endpoint;
use Kestello\Cms\Cms;
use Kestello\Framework\Core\Response;

/** The notification types you can receive and which channels you have switched off. */
final class GetMyNotificationPreferences extends Endpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::get('/me/notification-preferences');
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
        return 'Get your notification preferences';
    }
    public function tags(): array
    {
        return ['me'];
    }

    public function handle(ApiRequest $request): mixed
    {
        $notifier = (Cms::instance() ?? throw new \RuntimeException('Not booted.'))->notifications;
        $userId = (int) $request->userId();
        $data = [];
        foreach ($notifier->typesFor($request->principal) as $type => $t) {
            $off = $notifier->disabledChannels($userId, $type);
            $data[] = [
                'type' => $type,
                'label' => $t['label'],
                'description' => $t['description'],
                'channels' => array_map(static fn(string $c) => ['channel' => $c, 'enabled' => !in_array($c, $off, true)], $notifier->channels()),
            ];
        }

        return Response::json(['data' => $data]);
    }
}
