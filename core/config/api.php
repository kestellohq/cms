<?php

declare(strict_types=1);

/**
 * Core defaults for the "api" config group (override in the site's config/api.php).
 */
return [
    // CORS is off until origins are listed, e.g. ['https://app.example.com'].
    // credentials = true allows cookies from those origins (never with '*').
    'cors' => [
        'origins' => [],
        'credentials' => false,
    ],
];
