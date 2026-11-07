<?php

declare(strict_types=1);

namespace Kestello\Cms\Notifications\Channels;

use Kestello\Cms\Notifications\Notification;
use Kestello\Cms\Notifications\Recipient;
use Kestello\Contracts\Notifications\Channel;
use Kestello\Framework\Database\DatabaseManager;
use Kestello\Support\Json;
use Kestello\Support\Str;

/** The in-app inbox (bell icon): table notifications. */
final class DatabaseChannel implements Channel
{
    public function send(Recipient $to, Notification $notification): void
    {
        if ($to->userId === null) {
            return;
        }
        $data = $notification->toDatabase($to);
        $json = Json::encode($data);
        if (strlen($json) > 65_000) {
            throw new \LengthException('Notification data is too large for the inbox.');
        }
        DatabaseManager::getConnection()->table('notifications')->insert([
            'id' => Str::uuid7(),
            'user_id' => $to->userId,
            'type' => $notification->type(),
            'data' => $json,
            'read_at' => null,
            'created_at' => time(),
        ]);
    }
}
