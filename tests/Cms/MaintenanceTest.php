<?php

declare(strict_types=1);

namespace Kestello\Tests\Cms;

use Kestello\Cms\System\Maintenance;
use Kestello\Framework\Testing\TestCase;

final class MaintenanceTest extends TestCase
{
    public function testVisitorsModeKeepsTheAdminWorking(): void
    {
        $m = new Maintenance($this->tempDir());
        $this->assertFalse($m->blocks('/', false));
        $m->on(Maintenance::VISITORS, 'update');
        $this->assertTrue($m->blocks('/', false));
        $this->assertTrue($m->blocks('/blog/post', false));
        $this->assertFalse($m->blocks('/', true), 'signed-in people see the site');
        $this->assertFalse($m->blocks('/admin/plugins', false));
        $this->assertFalse($m->blocks('/api/v1/status', false));
        $this->assertFalse($m->blocks('/assets/core/x.js', false));
        $m->off();
        $this->assertNull($m->current());
    }

    public function testAllModeBlocksEveryoneAndExpires(): void
    {
        $dir = $this->tempDir();
        $m = new Maintenance($dir);
        $m->on(Maintenance::ALL, 'restore', 5);
        $this->assertTrue($m->blocks('/admin', true));
        $this->assertTrue($m->blocks('/api/v1/me', true));
        $this->assertSame('all', $m->current()['mode'] ?? null);
        // a run that died half way: the file outlives its expiry and is ignored
        file_put_contents($dir . '/framework/maintenance.json', json_encode(['mode' => 'all', 'expires_at' => time() - 1]));
        $this->assertNull($m->current());
        $this->assertFalse($m->blocks('/', false));
        $this->assertStringContainsString('Back in a moment', $m->page());
    }
}
