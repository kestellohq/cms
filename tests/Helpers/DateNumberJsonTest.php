<?php

declare(strict_types=1);

namespace Kestello\Tests\Helpers;

use Kestello\Framework\Testing\TestCase;
use Kestello\Support\Clock;
use Kestello\Support\Date;
use Kestello\Support\Json;
use Kestello\Support\Number;

final class DateNumberJsonTest extends TestCase
{
    protected function tearDown(): void
    {
        Clock::unfreeze();
    }

    public function testApiTimesAreUtcWithTheirZone(): void
    {
        $this->assertSame('2026-11-06T19:41:02Z', Date::isoUtc('2026-11-06 19:41:02'), 'a database time is UTC');
        $this->assertSame('2026-11-06T18:41:02Z', Date::isoUtc(new \DateTimeImmutable('2026-11-06 19:41:02', new \DateTimeZone('Europe/Sarajevo'))));
        $this->assertNull(Date::isoUtc(null));
        $this->assertNull(Date::isoUtc(''));
        $this->assertNull(Date::isoUtc('not a time'));
    }

    public function testParsingAndTimezones(): void
    {
        $d = Date::parse('2026-03-29 01:30:00', 'UTC');
        $this->assertSame('2026-03-29 03:30:00', $d->tz('Europe/Belgrade')->toDateTimeString(), 'DST: UTC+2 after the switch');
        $this->assertSame('2026-03-29 01:30:00', $d->tz('Europe/Belgrade')->toDatabase(), 'stored back as UTC');
        $this->assertSame('2026-03-29T01:30:00+00:00', json_decode(json_encode($d)));
        $this->assertSame(2026, Date::fromFormat('d.m.Y', '05.11.2026')->year());
        $this->assertNull(Date::tryParse('not a date'));
        $this->assertNull(Date::fromDatabase(null));
        $this->assertThrows(\InvalidArgumentException::class, fn() => Date::parse('2026-01-01', 'Mars/Base'));
    }

    public function testArithmeticAndBoundaries(): void
    {
        $jan31 = Date::create(2026, 1, 31, 10, 0, 0, 'UTC');
        $this->assertSame('2026-02-28', $jan31->addMonths(1)->toDateString(), 'no overflow into March');
        $this->assertSame('2024-02-29', Date::create(2024, 1, 31, tz: 'UTC')->addMonths(1)->toDateString(), 'leap year');
        $this->assertSame('2025-12-31', $jan31->subMonths(1)->toDateString());
        $this->assertSame('2025-01-31', $jan31->subYears(1)->toDateString());
        $this->assertSame('2026-01-31', $jan31->toDateString(), 'immutable');

        $wed = Date::create(2026, 11, 4, 15, 20, 0, 'UTC');
        $this->assertSame('2026-11-02 00:00:00', $wed->startOfWeek()->toDateTimeString());
        $this->assertSame('2026-11-08 23:59:59', $wed->endOfWeek()->toDateTimeString());
        $this->assertSame('2026-11-01 00:00:00', $wed->startOfWeek(7)->toDateTimeString(), 'Sunday weeks');
        $this->assertSame('2026-11-30 23:59:59', $wed->endOfMonth()->toDateTimeString());
        $this->assertSame('2026-01-01 00:00:00', $wed->startOfYear()->toDateTimeString());
    }

    public function testClockAndHumanDiffs(): void
    {
        Clock::freeze('2026-11-04 12:00:00 UTC');
        $this->assertSame('2026-11-04 12:00:00', Date::now('UTC')->toDateTimeString());

        $this->assertSame('3 minutes ago', Date::parse('2026-11-04 11:57:00', 'UTC')->diffForHumans());
        $this->assertSame('in 2 days', Date::parse('2026-11-06 12:00:00', 'UTC')->diffForHumans());
        $this->assertSame('just now', Date::parse('2026-11-04 11:59:58', 'UTC')->diffForHumans());
        $this->assertSame('1 year ago', Date::parse('2025-10-01', 'UTC')->diffForHumans());
        $this->assertSame('5h ago', Date::parse('2026-11-04 07:00:00', 'UTC')->diffForHumans(short: true));

        $this->assertTrue(Date::parse('yesterday', 'UTC')->isPast());
        $this->assertSame('2026-11-05', Date::parse('+1 day', 'UTC')->toDateString(), 'relative strings use the clock');
        $this->assertSame(3, Date::parse('2026-11-01', 'UTC')->diffInDays());

        Clock::travel(3600);
        $this->assertSame('13:00:00', Date::now('UTC')->toTimeString());
    }

    public function testLocalizedFormatting(): void
    {
        $d = Date::create(2026, 11, 4, 9, 5, 0, 'UTC');
        $this->assertSame('Nov 4, 2026', $d->formatLocale('medium', 'en_US'));
        $this->assertSame('4. November 2026', $d->formatLocale('d. MMMM y', 'de_DE'));
        $this->assertContains('2026', $d->formatLocale('long', 'sr_Latn'));
    }

    public function testNumbers(): void
    {
        $this->assertSame('1,234.57', Number::format(1234.567, 2, 'en_US'));
        $this->assertSame('1.234,57', Number::format(1234.567, 2, 'de_DE'));
        $this->assertSame('€1,234.50', Number::currency(1234.5, 'EUR', 'en_US'));
        $this->assertSame('12.5%', Number::percentage(12.5, 0, 'en_US'));
        $this->assertSame('1.5 KB', Number::fileSize(1536));
        $this->assertSame('512 B', Number::fileSize(512));
        $this->assertSame('1.2K', Number::abbreviate(1200));
        $this->assertSame('-3.4M', Number::abbreviate(-3_400_000));
        $this->assertSame('3rd', Number::ordinal(3, 'en'));
        $this->assertSame('forty-two', Number::spell(42, 'en'));
        $this->assertSame(10, Number::clamp(15, 0, 10));
    }

    public function testJson(): void
    {
        $this->assertSame('{"a":"/ć","f":1.0}', Json::encode(['a' => '/ć', 'f' => 1.0]));
        $this->assertSame(['a' => 1], Json::decode('{"a":1}'));
        $this->assertThrows(\JsonException::class, fn() => Json::decode('{bad'));
        $this->assertThrows(\JsonException::class, fn() => Json::decode(str_repeat('[', 100) . str_repeat(']', 100)), 'depth limit');
        $this->assertThrows(\JsonException::class, fn() => Json::decode('"' . str_repeat('a', 100) . '"', maxBytes: 50), 'size limit');
        $this->assertSame('d', Json::tryDecode('{bad', 'd'));
        $this->assertTrue(Json::isValid('[1]') && !Json::isValid('[1'));
        $this->assertSame('12345678901234567890', Json::decode('12345678901234567890'), 'big ints kept as strings');
    }
}
