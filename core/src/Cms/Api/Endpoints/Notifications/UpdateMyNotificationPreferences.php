<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Notifications;

use Kestello\Cms\Api\ApiProblem;
use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Api\Endpoint;
use Kestello\Cms\Api\Schema;
use Kestello\Cms\Cms;

/** Switch channels on or off per notification type. A type you can't receive is refused like an unknown one. */
final class UpdateMyNotificationPreferences extends Endpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::put('/me/notification-preferences');
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
        return 'Update your notification preferences';
    }
    public function tags(): array
    {
        return ['me'];
    }

    public function body(): ?Schema
    {
        return Schema::object([
            'preferences' => Schema::list(Schema::object([
                'type' => Schema::string()->max(191),
                'channel' => Schema::string()->max(50),
                'enabled' => Schema::bool(),
            ]))->max(500),
        ]);
    }

    public function handle(ApiRequest $request): mixed
    {
        $notifier = (Cms::instance() ?? throw new \RuntimeException('Not booted.'))->notifications;
        $types = $notifier->typesFor($request->principal);
        $channels = $notifier->channels();
        $prefs = (array) $request->input('preferences', []);
        foreach ($prefs as $i => $p) {
            if (!isset($types[$p['type']]) || !in_array($p['channel'], $channels, true)) {
                throw ApiProblem::validation(["preferences.{$i}" => ['Unknown notification type or channel.']]);
            }
        }
        foreach ($prefs as $p) {
            $notifier->setPreference((int) $request->userId(), $p['type'], $p['channel'], (bool) $p['enabled']);
        }

        return null;
    }
}
