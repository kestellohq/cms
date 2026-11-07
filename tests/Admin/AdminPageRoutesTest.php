<?php

declare(strict_types=1);

namespace Kestello\Tests\Admin;

use Kestello\Tests\Auth\AuthTestCase;

/** Admin pages with ids in their address answer 404 for anything that is not one, instead of asking the API for "NaN". */
final class AdminPageRoutesTest extends AuthTestCase
{
    public function testCoreAdminPagesTurnAwayMalformedIds(): void
    {
        $cms = $this->cms;
        $modules = [new \Kestello\Cms\Admin\AdminModule(), new \Kestello\Cms\Content\ContentModule(), new \Kestello\Cms\Forms\FormsModule(), new \Kestello\Cms\Webhooks\WebhooksModule(), new \Kestello\Cms\Design\DesignModule()];
        foreach ($modules as $module) {
            $module->register($cms->container);
        }
        foreach ($modules as $module) {
            $module->boot($cms);
        }
        $match = static function (string $uri) use ($cms): bool {
            foreach ($cms->routes->routes() as $route) {
                if (str_starts_with($route->path, '/admin') && $route->matches('GET', $uri) !== false) {
                    return true;
                }
            }

            return false;
        };
        foreach (['/admin/users/12', '/admin/content/page/new', '/admin/content/page/7', '/admin/forms/3/submissions', '/admin/system/webhooks/4', '/admin/content-types/2', '/admin/appearance/templates/page/single-post'] as $ok) {
            $this->assertTrue($match($ok), $ok);
        }
        foreach (['/admin/users/abc', '/admin/users/new', '/admin/users/1.5', '/admin/users/0', '/admin/content/page/abc', '/admin/forms/new', '/admin/system/webhooks/new', '/admin/content-types/new', '/admin/content/<x>'] as $bad) {
            $this->assertFalse($match($bad), $bad);
        }
    }
}
