<?php

declare(strict_types=1);

namespace Kestello\Tests\Admin;

use InvalidArgumentException;
use Kestello\Cms\Admin\AdminRegistry;
use Kestello\Cms\Auth\IntendedUrl;
use Kestello\Contracts\Auth\Principal;
use Kestello\Framework\Core\ExecutionContext;
use Kestello\Framework\Testing\TestCase;

final class AdminRegistryTest extends TestCase
{
    private static function principal(array $caps): Principal
    {
        return new Principal(1, ['id' => 1], 'session', $caps);
    }

    public function testCorePagesAndPluginPagesAreNamespaced(): void
    {
        $admin = new AdminRegistry();
        $routed = [];
        $admin->setRouter(function (array $page) use (&$routed) {
            $routed[] = $page['path'] . '@' . ExecutionContext::current();
        });

        $this->assertSame('/admin', $admin->page('', '@core/admin/Dashboard', 'Dashboard'));
        $this->assertSame('/admin/system/health', $admin->page('system/health', '@core/admin/system/Health', 'Health', 'system.manage'));
        $url = ExecutionContext::run('plugin:acme-shop', fn() => $admin->page('orders/{id}', '@acme-shop/admin/Order', 'Order'));
        $this->assertSame('/admin/p/acme-shop/orders/{id}', $url);
        $this->assertSame(['/admin@core', '/admin/system/health@core', '/admin/p/acme-shop/orders/{id}@plugin:acme-shop'], $routed);

        // A router installed later receives earlier pages in their owner's context.
        $late = [];
        $admin->setRouter(function (array $page) use (&$late) {
            $late[] = ExecutionContext::current();
        });
        $this->assertSame(['core', 'core', 'plugin:acme-shop'], $late);
    }

    public function testPageParametersAreConstrained(): void
    {
        $admin = new AdminRegistry();
        $routed = [];
        $admin->setRouter(function (array $page) use (&$routed) {
            $routed[$page['path']] = $page['where'];
        });
        $admin->page('orders/{id}', '@core/admin/Order', 'Order', where: ['id' => AdminRegistry::ID]);
        $admin->page('kinds/{kind}', '@core/admin/Kind', 'Kind');
        $this->assertSame(['id' => AdminRegistry::ID], $routed['/admin/orders/{id}']);
        $this->assertSame(['kind' => AdminRegistry::SLUG], $routed['/admin/kinds/{kind}'], 'a parameter without a rule is still a plain name');

        $this->assertThrows(InvalidArgumentException::class, fn() => $admin->page('a/{id}', '@core/admin/A', 'A', where: ['other' => '\\d+']), 'a rule for a parameter the path does not have');
        $this->assertThrows(InvalidArgumentException::class, fn() => $admin->page('b/{id}', '@core/admin/B', 'B', where: ['id' => '(']), 'a rule that is not a pattern');
    }

    public function testInvalidRegistrationsAreRejected(): void
    {
        $admin = new AdminRegistry();
        $this->assertThrows(InvalidArgumentException::class, fn() => $admin->page('x', '@acme/admin/X', 'X'));
        $this->assertThrows(InvalidArgumentException::class, fn() => $admin->page('../etc', '@core/admin/X', 'X'));
        $this->assertThrows(InvalidArgumentException::class, fn() => ExecutionContext::run('plugin:acme', fn() => $admin->page('x', '@core/admin/X', 'X')));
        $this->assertThrows(InvalidArgumentException::class, fn() => ExecutionContext::run('plugin:acme', fn() => $admin->link('other.x', 'X', '/x')));
        $this->assertThrows(InvalidArgumentException::class, fn() => $admin->link('core.js', 'X', 'javascript:alert(1)'));
        $this->assertThrows(InvalidArgumentException::class, fn() => $admin->link('core.http', 'X', 'http://insecure.test'));
        $this->assertThrows(InvalidArgumentException::class, fn() => $admin->page('a/{id}', '@core/admin/A', 'A', menu: []));
        $admin->page('dup', '@core/admin/D', 'D');
        $this->assertThrows(InvalidArgumentException::class, fn() => $admin->page('dup', '@core/admin/D', 'D'));
    }

    public function testMenuIsFilteredByCapabilityAndOrdered(): void
    {
        $admin = new AdminRegistry();
        $admin->page('', '@core/admin/Dashboard', 'Dashboard', menu: ['icon' => 'gauge']);
        $admin->page('system/health', '@core/admin/system/Health', 'Health', 'system.manage', menu: ['group' => 'system', 'order' => 10]);
        $admin->link('core.users', 'Users', '/admin/users', group: 'people', capability: 'users.read');
        ExecutionContext::run('plugin:acme-shop', function () use ($admin) {
            $admin->group('shop', 'Shop', order: 30);
            $admin->page('reports', '@acme-shop/admin/Reports', 'Reports', 'acme.reports', menu: ['group' => 'shop']);
        });

        $editor = $admin->menuFor(self::principal(['admin.access', 'users.read']));
        $this->assertSame(['main', 'people'], array_column($editor, 'id'));
        $this->assertSame('core.home', $editor[0]['items'][0]['id']);

        $all = $admin->menuFor(self::principal(['*']));
        $this->assertSame(['main', 'people', 'shop', 'system'], array_column($all, 'id'));
        $this->assertSame('/admin/p/acme-shop/reports', $all[2]['items'][0]['href']);
        $this->assertSame('acme-shop.reports', $all[2]['items'][0]['id']);

        $this->assertSame([], $admin->menuFor(Principal::guest()));
    }

    public function testIntendedUrlOnlyAllowsAdminPaths(): void
    {
        foreach (['/admin', '/admin/system/health', '/admin/users?page=2'] as $ok) {
            $this->assertTrue(IntendedUrl::safe($ok), $ok);
        }
        foreach (['https://evil.test/admin', '//evil.test/admin', '/admin//evil.test', '/administrator', '/admin\\evil', '/login', '/admin/x y'] as $bad) {
            $this->assertFalse(IntendedUrl::safe($bad), $bad);
        }
    }

    public function testExtensionPointsAreValidatedAndFilteredByCapability(): void
    {
        $admin = new AdminRegistry();
        ExecutionContext::run('plugin:acme', function () use ($admin) {
            $admin->widget('dashboard', '@acme/admin/Sales', title: 'Sales', size: 'quarter', capability: 'acme.sales', order: 20);
            $admin->widget('dashboard', '@acme/admin/Help', title: 'Help', order: 10);
            $admin->slot('users.edit.panels', '@acme/admin/Loyalty', when: ['role' => ['user']]);
            $admin->slot('profile.tabs', '@acme/admin/Prefs', label: 'Acme');
            $admin->column('users.list', 'acme_tier', label: 'Tier', align: 'end');
            $admin->replace('users.avatar', '@acme/admin/Avatar');
        });

        $all = $admin->extensionsFor(self::principal(['*']));
        $this->assertSame(['@acme/admin/Help', '@acme/admin/Sales'], array_column($all['widgets']['dashboard'], 'component'), 'ordered');
        $this->assertSame(['quarter', 'acme'], [$all['widgets']['dashboard'][1]['size'], $all['widgets']['dashboard'][1]['owner']]);
        $this->assertSame(['role' => ['user']], (array) $all['slots']['users.edit.panels'][0]['when']);
        $this->assertSame(['component' => '', 'key' => 'acme_tier', 'label' => 'Tier', 'align' => 'end'], array_intersect_key($all['columns']['users.list'][0], array_flip(['key', 'label', 'align', 'component'])));
        $this->assertSame(['users.avatar' => '@acme/admin/Avatar'], $all['replace']);

        ExecutionContext::run('plugin:acme', fn() => $admin->column('entries.list', 'acme_stock', label: 'Stock', when: ['type' => 'acme-product']));
        $column = $admin->extensionsFor(self::principal(['*']))['columns']['entries.list'][0];
        $this->assertSame(['type' => ['acme-product']], (array) $column['when'], 'content-list columns name the types they belong to');
        $this->assertThrows(InvalidArgumentException::class, fn() => ExecutionContext::run('plugin:acme', fn() => $admin->column('entries.list', 'acme_x', 'X', when: ['Type!' => 'x'])));

        $limited = $admin->extensionsFor(self::principal(['admin.access']));
        $this->assertSame(['@acme/admin/Help'], array_column($limited['widgets']['dashboard'], 'component'), 'a widget the user cannot use is never sent');
    }

    public function testInvalidExtensionsAreRejected(): void
    {
        $admin = new AdminRegistry();
        $plugin = static fn(callable $fn) => fn() => ExecutionContext::run('plugin:acme', $fn);

        $this->assertThrows(InvalidArgumentException::class, $plugin(fn() => $admin->widget('sidebar', '@acme/admin/W', 'W')), 'unknown point');
        $this->assertThrows(InvalidArgumentException::class, $plugin(fn() => $admin->widget('dashboard', '@other/admin/W', 'W')), 'another package\'s component');
        $this->assertThrows(InvalidArgumentException::class, $plugin(fn() => $admin->widget('dashboard', '@acme/W', 'W')), 'outside pages/admin');
        $this->assertThrows(InvalidArgumentException::class, $plugin(fn() => $admin->widget('dashboard', '@acme/admin/W', 'W', size: 'huge')));
        $this->assertThrows(InvalidArgumentException::class, $plugin(fn() => $admin->slot('profile.tabs', '@acme/admin/T')), 'tabs need a label');
        $this->assertThrows(InvalidArgumentException::class, $plugin(fn() => $admin->slot('users.edit.panels', '@acme/admin/P', when: ['bad key' => 1])));
        $this->assertThrows(InvalidArgumentException::class, $plugin(fn() => $admin->replace('users.table', '@acme/admin/T')), 'not replaceable');
        $this->assertThrows(InvalidArgumentException::class, fn() => $admin->settings('x', 'X', \Kestello\Cms\Api\Schema::object([])), 'core has no settings tabs');
        $this->assertThrows(InvalidArgumentException::class, $plugin(fn() => $admin->settings('x', 'X')), 'schema or component');
        $this->assertThrows(InvalidArgumentException::class, $plugin(fn() => $admin->settings('x', 'X', \Kestello\Cms\Api\Schema::object(['a' => \Kestello\Cms\Api\Schema::string()]), secrets: ['b'])));

        ExecutionContext::run('plugin:acme', fn() => $admin->column('users.list', 'tier', 'Tier'));
        $this->assertThrows(InvalidArgumentException::class, fn() => ExecutionContext::run('plugin:beta', fn() => $admin->column('users.list', 'tier', 'Tier')), 'one owner per column');

        // Two plugins replacing the same component: the second one fails (and so fails activation).
        ExecutionContext::run('plugin:acme', fn() => $admin->replace('users.avatar', '@acme/admin/Avatar'));
        $e = $this->assertThrows(InvalidArgumentException::class, fn() => ExecutionContext::run('plugin:beta', fn() => $admin->replace('users.avatar', '@beta/admin/Avatar')));
        $this->assertTrue(str_contains($e->getMessage(), 'already replaced by plugin:acme'));
    }
}
