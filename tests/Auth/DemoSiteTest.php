<?php

declare(strict_types=1);

namespace Kestello\Tests\Auth;

use Kestello\Cms\Backup\Backups;
use Kestello\Cms\Demo\Demo;
use Kestello\Cms\Demo\DemoModule;
use Kestello\Cms\Demo\DemoSite;
use Kestello\Cms\Extension\ArrayStateStore;
use Kestello\Cms\Extension\ExtensionManager;
use Kestello\Cms\Extension\SuspensionList;
use Kestello\Support\Hash;

/** The demo's snapshot and its hourly reset, on a site that already has content (the sample site is DemoContentTest's). */
final class DemoSiteTest extends AuthTestCase
{
    protected bool $demo = true;
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = $this->cms->paths->root();
        $this->cms->storage->configure($this->root . '/storage/app', []);
        $extensions = new ExtensionManager($this->cms, new ArrayStateStore(), new SuspensionList($this->root . '/suspended.json'), '0.1.0');
        $extensions->boot();
        $this->cms->setExtensions($extensions);
        @mkdir($this->root . '/storage/framework', 0777, true);
        @mkdir($this->root . '/content/uploads', 0777, true);
        file_put_contents($this->root . '/content/uploads/loaf.jpg', 'the sample image');
        $this->db->table('entries')->insert(['type' => 'page', 'status' => 'published', 'title' => 'Home', 'slug' => 'home', 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);
    }

    private function site(): DemoSite
    {
        return new DemoSite($this->cms, new Backups($this->cms));
    }

    public function testTheFirstBootMakesTheAccountAndTheSnapshotOnce(): void
    {
        $this->assertNull($this->site()->nextReset(), 'no snapshot yet');
        $this->assertTrue($this->site()->ensure());

        $user = $this->db->table('users')->where('username', Demo::USERNAME)->first();
        $this->assertSame('admin', $user['role'] ?? null);
        $this->assertTrue(Hash::verify(Demo::PASSWORD, (string) $user['password']));
        $state = $this->site()->state();
        $this->assertTrue((new Backups($this->cms))->exists((string) $state['snapshot']));
        $this->assertSame($state['reset_at'] + Demo::RESET_EVERY, $this->site()->nextReset());
        $this->assertSame(1, $this->db->table('entries')->count(), 'a site with content keeps it: no sample site on top');

        $this->assertFalse($this->site()->ensure(), 'once');
    }

    public function testTheResetPutsBackContentUploadsAndAccountsAndSignsEveryoneOut(): void
    {
        $this->site()->ensure();

        // an hour of visitors
        $this->db->table('entries')->where('slug', 'home')->update(['title' => 'Something rude']);
        $this->db->table('entries')->insert(['type' => 'post', 'status' => 'published', 'title' => 'Spam', 'slug' => 'spam', 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);
        file_put_contents($this->root . '/content/uploads/visitor.jpg', 'a visitor\'s upload');
        $this->db->table('sessions')->insert(['id' => str_repeat('a', 64), 'ip' => '203.0.113.7', 'user_agent' => 'x', 'payload' => 'x', 'created_at' => time(), 'last_activity' => time()]);

        $this->assertFalse($this->site()->due());
        $this->assertTrue($this->site()->due(time() + Demo::RESET_EVERY));
        $this->site()->reset();

        $this->assertSame(['Home'], array_column($this->db->table('entries')->get(), 'title'));
        $this->assertFalse(is_file($this->root . '/content/uploads/visitor.jpg'), 'uploads go too');
        $this->assertSame('the sample image', file_get_contents($this->root . '/content/uploads/loaf.jpg'));
        $this->assertSame(0, $this->db->table('sessions')->count(), 'everyone is signed out');
        $this->assertFalse($this->site()->due(time() + Demo::RESET_EVERY - 5), 'the next hour starts now');
    }

    public function testANewSnapshotKeepsTheOwnersWorkButNotTheirPassword(): void
    {
        $this->site()->ensure();
        $first = (string) $this->site()->state()['snapshot'];

        // the owner turned the demo off, signed in with a password of their own and changed the home page
        $this->db->table('users')->where('username', Demo::USERNAME)->update(['password' => Hash::password('owner-only-password-1'), 'role' => 'editor']);
        $this->db->table('entries')->where('slug', 'home')->update(['title' => 'Welcome']);
        sleep(1);   // backup names carry the second
        $second = $this->site()->snapshot();

        $this->assertNotSame($first, $second);
        $this->assertFalse((new Backups($this->cms))->exists($first), 'one snapshot at a time');
        $this->site()->reset();
        $user = $this->db->table('users')->where('username', Demo::USERNAME)->first();
        $this->assertTrue(Hash::verify(Demo::PASSWORD, (string) $user['password']));
        $this->assertSame('admin', $user['role']);
        $this->assertSame(['Welcome'], array_column($this->db->table('entries')->get(), 'title'));
    }

    public function testWithoutASnapshotTheResetSaysWhatToDo(): void
    {
        try {
            $this->site()->reset();
            $this->fail('no snapshot');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('demo:snapshot', $e->getMessage());
        }
    }

    public function testThePublicSiteSaysItIsADemoButTheAdminAndSignInHaveTheirOwn(): void
    {
        $this->assertStringContainsString('href="/login"', DemoModule::badge('/'));
        $this->assertStringContainsString('Demo site', DemoModule::badge('/blog/fresh-bread?x=1'));
        foreach (['/admin', '/admin/content/page', '/login', '/install'] as $url) {
            $this->assertSame('', DemoModule::badge($url), $url);
        }
        $this->assertSame(['username' => 'demo', 'password' => 'demo', 'next_reset' => 123, 'every' => 60], DemoModule::props(123));
    }

    public function testTheHealthCheckBlamesAFailingResetNotAQuietSite(): void
    {
        $due = 10_000;
        $this->assertSame('critical', DemoModule::health(false, null, null, $due)->status, 'no snapshot');
        $this->assertSame('good', DemoModule::health(true, $due, $due - 30, $due - 60)->status, 'not due yet');
        $this->assertSame('good', DemoModule::health(true, $due, $due - 30, $due + 7200)->status, 'nobody visited a demo on the web runner: the next visit resets it');
        $this->assertSame('good', DemoModule::health(true, $due, null, $due + 7200)->status, 'no scheduler at all is the Scheduled tasks check\'s to say');
        $late = DemoModule::health(true, $due, $due + 300, $due + 600);
        $this->assertSame('critical', $late->status, 'the scheduler ran after it was due and the demo is still not back');
        $this->assertStringContainsString('demo.reset', $late->message);
    }
}
