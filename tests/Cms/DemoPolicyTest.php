<?php

declare(strict_types=1);

namespace Kestello\Tests\Cms;

use Kestello\Cms\Demo\DemoPolicy;
use Kestello\Framework\Testing\TestCase;

final class DemoPolicyTest extends TestCase
{
    public function testReadingIsAllowedExceptOtherVisitorsTracesAndWholeDatabases(): void
    {
        $this->assertTrue(DemoPolicy::allows('GET', '/users', 'users.read', false));
        $this->assertTrue(DemoPolicy::allows('get', '/backups', 'backups.manage', false), 'the list shows what backups look like');
        foreach (['/audit', '/me/sessions', '/security/sessions', '/backups/{name}/download', '/system/info', '/form-submissions/{id}/files/{field}/{index}'] as $path) {
            $this->assertFalse(DemoPolicy::allows('GET', $path, null, false), $path);
            $this->assertFalse(DemoPolicy::allows('HEAD', $path, null, false), "HEAD {$path}");
        }
    }

    public function testContentDesignMenusFormsAndImagesStayOpen(): void
    {
        $this->assertTrue(DemoPolicy::allows('POST', '/content/{type}/entries', 'content.access', false));
        $this->assertTrue(DemoPolicy::allows('PUT', '/design/styles', 'theme.design', false));
        $this->assertTrue(DemoPolicy::allows('PUT', '/menus/{id}', 'menus.manage', false));
        $this->assertTrue(DemoPolicy::allows('PATCH', '/forms/{id}', 'forms.manage', false));
        $this->assertTrue(DemoPolicy::allows('POST', '/media/uploads', 'media.upload', false));
        $this->assertTrue(DemoPolicy::allows('PATCH', '/media/{id}', 'admin.access', false));
        $this->assertTrue(DemoPolicy::allows('POST', '/auth/session', null, false), 'signing in');
        $this->assertTrue(DemoPolicy::allows('POST', '/forms/{id}/submissions', null, false), 'visitors filling in a form');
    }

    public function testTheAccountPeoplePackagesAndSettingsAreClosed(): void
    {
        foreach ([
            ['PUT', '/me/password', 'profile.manage', false],
            ['PATCH', '/me', 'profile.manage', false],
            ['POST', '/me/2fa/totp', 'profile.manage', true],
            ['POST', '/me/tokens', 'profile.manage', true],
            ['POST', '/auth/password/forgot', null, false],
            ['POST', '/auth/password/reset', null, false],
            ['POST', '/users', 'users.manage', true],
            ['PATCH', '/roles/{name}', 'roles.manage', true],
            ['POST', '/plugins/upload', 'plugins.manage', true],
            ['POST', '/themes/{slug}/activate', 'theme.manage', true],
            ['POST', '/updates/core/check', 'core.update', false],
            ['POST', '/backups', 'backups.manage', false],
            ['PATCH', '/settings', 'settings.manage', true],
            ['POST', '/settings/mail/test', 'settings.manage', false],
            ['POST', '/imports', 'import.run', true],
            ['POST', '/webhooks/{id}/ping', 'webhooks.manage', false],
            ['POST', '/redirects', 'redirects.manage', false],
            ['POST', '/system/schedule/{name}/run', 'system.manage', false],
            ['POST', '/themes/fonts', 'theme.design', false],
            ['DELETE', '/themes/fonts/{slug}', 'theme.design', false],
            ['POST', '/design/templates/{kind}/{key}/theme-update', 'theme.design', false],
        ] as [$method, $path, $capability, $recent]) {
            $this->assertFalse(DemoPolicy::allows($method, $path, $capability, $recent), "{$method} {$path}");
        }
    }

    public function testAnythingNewIsClosedUntilListed(): void
    {
        $this->assertFalse(DemoPolicy::allows('POST', '/acme/sync', 'acme.manage', false), 'a plugin endpoint');
        $this->assertFalse(DemoPolicy::allows('POST', '/something', null, false), 'a public endpoint');
        $this->assertFalse(DemoPolicy::allows('PATCH', '/content/{type}/entries/{id}', 'content.access', true), 'a recent sign-in marks it sensitive');
    }
}
