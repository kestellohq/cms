<?php

declare(strict_types=1);

namespace Kestello\Tests\Admin;

use Kestello\Contracts\Auth\Principal;
use Kestello\Tests\Auth\AuthTestCase;

/** Where core pages sit in the admin menu: everyday pages first, the tools last. */
final class AdminMenuTest extends AuthTestCase
{
    public function testTheCoreMenuPutsEverydayPagesBeforeTheTools(): void
    {
        $cms = $this->cms;
        $modules = [
            new \Kestello\Cms\Admin\AdminModule(), new \Kestello\Cms\Content\ContentModule(), new \Kestello\Cms\Media\MediaModule(),
            new \Kestello\Cms\Forms\FormsModule(), new \Kestello\Cms\Design\DesignModule(), new \Kestello\Cms\Redirects\RedirectsModule(),
            new \Kestello\Cms\Backup\BackupModule(), new \Kestello\Cms\Import\ImportModule(), new \Kestello\Cms\Package\PackagesModule(),
            new \Kestello\Cms\Webhooks\WebhooksModule(),
        ];
        foreach ($modules as $module) {
            $module->register($cms->container);
        }
        foreach ($modules as $module) {
            $module->boot($cms);
        }
        \Kestello\Cms\Content\ContentModule::finalize($cms);

        $menu = $cms->admin->menuFor(new Principal(1, ['id' => 1], 'session', ['*']));
        $groups = [];
        $icons = [];
        foreach ($menu as $group) {
            $groups[$group['id']] = array_column($group['items'], 'id');
            $icons += array_column($group['items'], 'icon', 'id');
        }

        $this->assertSame(['main', 'content', 'people', 'appearance', 'site', 'plugins', 'system'], array_keys($groups));
        $this->assertSame(['core.settings', 'core.system.updates', 'core.system.backups', 'core.system.import', 'core.redirects'], $groups['site'], 'what an owner changes or runs now and then');
        $this->assertSame('core.system.health', $groups['system'][0], 'the tools open with the health check');
        $this->assertNotContains('core.redirects', $groups['appearance'], 'redirects are not about how the site looks');
        $this->assertContains('core.profile', $groups['people'], 'the finder still lists it; the sidebar shows it in its footer');
        $this->assertSame('house', $icons['core.home'], 'the overview no longer shares the templates icon');
        $this->assertNotContains('circle', $icons, 'every core entry has an icon of its own');
    }
}
