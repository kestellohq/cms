<?php

declare(strict_types=1);

namespace Kestello\Tests\Framework;

use Kestello\Framework\Testing\TestCase;

/** Helpers the test base class gives every test, including a plugin's own tests outside this repository. */
final class TestCaseTest extends TestCase
{
    public function testCorePathPointsIntoTheCoreFolder(): void
    {
        $core = dirname(__DIR__, 2) . '/core';

        $this->assertSame(realpath($core), realpath(self::corePath()));
        $this->assertSame(realpath($core . '/database/migrations'), realpath(self::corePath('database/migrations')));
        $this->assertSame(self::corePath('database'), self::corePath('/database'), 'a leading slash is the same path');
    }
}
