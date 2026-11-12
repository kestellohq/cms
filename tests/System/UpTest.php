<?php

declare(strict_types=1);

namespace Kestello\Tests\System;

use Kestello\Cms\Content\Entries;
use Kestello\Cms\System\Up;
use Kestello\Framework\Testing\TestCase;

final class UpTest extends TestCase
{
    public function testASiteWhoseDatabaseAnswersIsUp(): void
    {
        $this->assertSame([200, ['status' => 'ok', 'database' => true, 'maintenance' => false]], Up::check(true, false, static function (): void {}));
        $this->assertSame([200, ['status' => 'ok', 'database' => true, 'maintenance' => true]], Up::check(true, true, static function (): void {}), 'in maintenance it still answers: restarting it would only interrupt the update');
    }

    public function testASiteWhoseDatabaseDoesNotAnswerIsDown(): void
    {
        $down = static function (): void {
            throw new \PDOException('SQLSTATE[HY000] [2002] Connection refused to db.internal:3306 as kestello');
        };
        [$status, $body] = Up::check(true, false, $down);
        $this->assertSame(503, $status);
        $this->assertSame(['status' => 'unavailable', 'database' => false], $body);
        $this->assertFalse(str_contains((string) json_encode($body), 'db.internal'), 'the reason, host and user stay private');
        $this->assertSame(503, Up::check(true, false, null)[0], 'installed without a database config');
    }

    public function testASiteNotYetInstalledIsUpSoTheInstallerCanRun(): void
    {
        $this->assertSame([200, ['status' => 'ok', 'installed' => false]], Up::check(false, false, null));
    }

    public function testNoPageCanTakeItsAddress(): void
    {
        $this->assertContains(ltrim(Up::PATH, '/'), Entries::RESERVED_PATHS);
    }
}
