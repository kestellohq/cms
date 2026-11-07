<?php

declare(strict_types=1);

namespace Kestello\Tests\Framework;

use Kestello\Framework\Support\Semver;
use Kestello\Framework\Testing\TestCase;

final class SemverTest extends TestCase
{
    public function testCompare(): void
    {
        $this->assertSame(-1, Semver::compare('1.2.3', '1.10.0'));
        $this->assertSame(0, Semver::compare('v1.0', '1.0.0'));
        $this->assertSame(-1, Semver::compare('1.0.0-beta.2', '1.0.0'));
        $this->assertSame(-1, Semver::compare('1.0.0-alpha', '1.0.0-beta'));
        $this->assertSame(-1, Semver::compare('1.0.0-beta.2', '1.0.0-beta.11'));
    }

    /** @return list<array{string, string, bool}> */
    private static function cases(): array
    {
        return [
            ['1.4.2', '^1.2', true],
            ['2.0.0', '^1.2', false],
            ['1.1.9', '^1.2', false],
            ['0.3.5', '^0.3', true],
            ['0.4.0', '^0.3', false],
            ['0.0.3', '^0.0.3', true],
            ['0.0.4', '^0.0.3', false],
            ['1.2.9', '~1.2', true],
            ['1.3.0', '~1.2', false],
            ['1.9.0', '~1', true],
            ['1.2.7', '1.2.*', true],
            ['1.3.0', '1.2.*', false],
            ['1.9.9', '1.x', true],
            ['8.3.12', '>=8.3', true],
            ['8.2.0', '>=8.3', false],
            ['1.5.0', '>=1.2 <2.0', true],
            ['2.0.0', '>=1.2 <2.0', false],
            ['1.5.0', '>= 1.2, < 2.0', true],
            ['2.3.0', '^1.0 || ^2.0', true],
            ['3.0.0', '^1.0 || ^2.0', false],
            ['2.0.0-beta.1', '^1.0', false],
            ['1.5.0', '!=1.5.0', false],
            ['9.9.9', '*', true],
        ];
    }

    public function testConstraints(): void
    {
        foreach (self::cases() as [$version, $constraint, $expected]) {
            $this->assertSame($expected, Semver::satisfies($version, $constraint), "{$version} vs \"{$constraint}\"");
        }
    }

    public function testValidation(): void
    {
        $this->assertTrue(Semver::isValid('1.0.0'));
        $this->assertTrue(Semver::isValid('2.1.0-rc.1'));
        $this->assertFalse(Semver::isValid('1.0'));
        $this->assertFalse(Semver::isValid('latest'));
        $this->assertTrue(Semver::isValidConstraint('^1.2 || >=3'));
        $this->assertFalse(Semver::isValidConstraint('>=banana'));
    }
}
