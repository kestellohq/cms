<?php

declare(strict_types=1);

namespace Kestello\Tests\Admin;

use Kestello\Cms\Admin\AdminPageController;
use Kestello\Cms\Frontend\FrontendModule;
use Kestello\Cms\View\PageResponse;
use Kestello\Framework\Core\Request;
use Kestello\Tests\Auth\AuthTestCase;

/** A mistyped admin address keeps people in the admin: its own "not found" page, with the menu. */
final class AdminNotFoundTest extends AuthTestCase
{
    private static function get(string $path): Request
    {
        return new Request('GET', $path, $path, [], [], [], [], [], ['REMOTE_ADDR' => '203.0.113.9']);
    }

    public function testPeopleWhoUseTheAdminStayInIt(): void
    {
        $this->assertNull(AdminPageController::notFound($this->cms, self::get('/admin/nope')), 'a visitor gets the site\'s page');

        $this->createUser();
        $this->assertSame(200, $this->api('POST', '/auth/session', ['login' => 'ana', 'password' => self::PASSWORD])->statusCode());
        $page = AdminPageController::notFound($this->cms, self::get('/admin/nope'));
        $this->assertInstanceOf(PageResponse::class, $page);
        $this->assertSame(['@core/admin/NotFound', 404], [$page->component, $page->status]);
        $this->assertSame('Page not found', $page->props['title']);
        $this->assertArrayHasKey('menu', $page->props['admin'], 'the admin\'s menu comes along, to find the way');
    }

    public function testOnlyAddressesUnderTheAdminTakeIt(): void
    {
        $admin = static fn(Request $r) => new PageResponse('@core/admin/NotFound', [], 404);
        $none = static fn(Request $r) => null;
        $this->assertSame('@core/admin/NotFound', FrontendModule::errorPage(self::get('/admin/webhooks'), 404, $admin)->component);
        $this->assertSame('@core/errors/Error', FrontendModule::errorPage(self::get('/administrator'), 404, $admin)->component, 'only /admin and below');
        $this->assertSame('@core/errors/Error', FrontendModule::errorPage(self::get('/admin/x'), 500, $admin)->component, 'only a page that is not there');
        $this->assertSame('@core/errors/Error', FrontendModule::errorPage(self::get('/admin/x'), 404, $none)->component, 'a visitor: the site\'s page');
        $site = FrontendModule::errorPage(self::get('/nope'), 404);
        $this->assertSame([404, 'Page not found'], [$site->props['status'], $site->props['heading']]);
    }
}
