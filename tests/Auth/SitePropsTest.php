<?php

declare(strict_types=1);

namespace Kestello\Tests\Auth;

use Kestello\Cms\Frontend\FrontendModule;

/** What every site page knows: the language to load and whether to offer a way to the admin. */
final class SitePropsTest extends AuthTestCase
{
    public function testTheLanguageAndWhoMayOpenTheAdmin(): void
    {
        $this->cms->auth->roles->role('writer', 'Writer', ['read', 'admin.access']);
        $this->cms->auth->roles->role('member', 'Member', ['read']);
        $this->assertSame(['locale' => 'en', 'translations' => null, 'prefix' => '', 'languages' => []], FrontendModule::shared($this->cms)['i18n'], 'English: nothing to load, and one language');

        foreach (['admin' => true, 'writer' => true, 'member' => false] as $role => $admin) {
            $this->createUser(['username' => $role, 'email' => "{$role}@example.test", 'role' => $role]);
            $this->api('DELETE', '/auth/session');
            $this->assertSame(200, $this->api('POST', '/auth/session', ['login' => $role, 'password' => self::PASSWORD])->statusCode());
            $this->assertSame($admin, FrontendModule::shared($this->cms)['auth']['user']['admin'], "{$role}: follows admin.access, not the role's name");
        }
    }

    public function testPagesGetTheSiteTitlePatterns(): void
    {
        $titles = FrontendModule::shared($this->cms)['site']['titles'];
        $this->assertSame(['{title} {sep} {site}', '{site}', '-'], [$titles['page'], $titles['home'], $titles['sep']], 'so the browser writes the same <title> as the server');
    }
}
