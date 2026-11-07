<?php

declare(strict_types=1);

/**
 * Core defaults for the "webhooks" config group (override in the site's config/webhooks.php).
 */
return [
    // Webhook URLs must resolve to public addresses. Set this to true only to
    // reach a service on your own network (an internal build server). It can
    // only be changed here, never from the admin.
    'allow_private' => false,

    // Seconds to wait for a receiver to answer.
    'timeout' => 10,
];
