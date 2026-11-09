<?php

declare(strict_types=1);

namespace Kestello\Cms\Demo;

use Kestello\Framework\Core\Config;

/**
 * A public demo (config app.demo): everyone signs in as "demo", only
 * content, design, menus, forms and images can be changed (DemoPolicy),
 * nothing leaves the server by email (DemoTransport), and the site goes
 * back to its snapshot every hour (DemoSite). Only the server can turn it
 * off, in config/app.php.
 */
final class Demo
{
    public const string USERNAME = 'demo';
    public const string PASSWORD = 'demo';
    public const string EMAIL = 'demo@example.com';

    /** Seconds between resets. */
    public const int RESET_EVERY = 3600;

    /** The largest image a visitor may upload, and all of them together. */
    public const int MAX_UPLOAD_BYTES = 5 * 1_048_576;
    public const int MEDIA_BYTES = 100 * 1_048_576;

    public static function on(Config $config): bool
    {
        return (bool) $config->get('app.demo', false);
    }
}
