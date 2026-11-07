<?php

declare(strict_types=1);

namespace Kestello\Tests\Helpers;

use Kestello\Framework\Testing\TestCase;
use Kestello\Support\Str;

use function Kestello\str;

final class StrTest extends TestCase
{
    public function testSlugAndAscii(): void
    {
        $this->assertSame('dorde-balasevic', Str::slug('Đorđe Balašević'));
        $this->assertSame('dorde', Str::slug('Ђорђе'));
        $this->assertSame('strasse-and-cafe', Str::slug('Straße & Café'));
        $this->assertSame('hello_world', Str::slug('  Hello,   World! ', '_'));
        $this->assertSame('a-at-b', Str::slug('a@b'));
        $this->assertSame('', Str::slug('!!!'));
        $this->assertSame('AEro', Str::ascii('Ærø'));
    }

    public function testCase(): void
    {
        $this->assertSame('userProfileId', Str::camel('user_profile-id'));
        $this->assertSame('UserProfileId', Str::studly('user profile id'));
        $this->assertSame('user_profile_id', Str::snake('UserProfileID'));
        $this->assertSame('html-parser', Str::kebab('HTMLParser'));
        $this->assertSame('User Profile Id', Str::headline('user_profile-id'));
        $this->assertSame('Ćao Svete', Str::title('ćao svete'));
        $this->assertSame('Šta', Str::ucfirst('šta'));
    }

    public function testTruncation(): void
    {
        $this->assertSame('Hello…', Str::limit('Hello world', 5));
        $this->assertSame('Hello world', Str::limit('Hello world', 50));
        $this->assertSame('Ћирилица…', Str::limit('Ћирилица текст', 8));
        $this->assertSame('one two…', Str::words('one two three', 2));
        $this->assertSame('one two three', Str::words('one two three', 5));
        $this->assertSame('Hello world and…', Str::excerpt('<p>Hello <b>world</b> and everyone</p><script>x()</script>', 18));
        $this->assertSame('a b c', Str::squish("  a \n\t b\u{00A0} c "));
    }

    public function testSearching(): void
    {
        $this->assertSame('user', Str::before('user@example.com', '@'));
        $this->assertSame('example.com', Str::after('user@example.com', '@'));
        $this->assertSame('a.b', Str::beforeLast('a.b.c', '.'));
        $this->assertSame('c', Str::afterLast('a.b.c', '.'));
        $this->assertSame('mid', Str::between('[mid]', '[', ']'));
        $this->assertTrue(Str::startsWith('admin/users', ['api/', 'admin/']));
        $this->assertFalse(Str::startsWith('x', ''));
        $this->assertTrue(Str::contains('Hello World', 'world', ignoreCase: true));
        $this->assertTrue(Str::containsAll('a b c', ['a', 'c']));
        $this->assertTrue(Str::is('admin/*', 'admin/users/5'));
        $this->assertFalse(Str::is('admin/*', 'api/admin/x'));
        $this->assertFalse(Str::is('a.c', 'abc'), 'dots are literal');
        $this->assertSame('a-b_b', Str::replaceFirst('_', '-', 'a_b_b'));
        $this->assertSame('a_b-b', Str::replaceLast('_', '-', 'a_b_b'));
    }

    public function testFormatting(): void
    {
        $this->assertSame('************4242', Str::mask('4242424242424242', '*', 0, -4));
        $this->assertSame('j***@example.com', Str::mask('john@example.com', '*', 1, 3));
        $this->assertSame('**ab**', Str::padBoth('ab', 6, '*'));
        $this->assertSame('007', Str::padLeft('7', 3, '0'));
        $this->assertSame('/path', Str::start('//path', '/'));
        $this->assertSame('path/', Str::finish('path///', '/'));
        $this->assertSame(4, Str::wordCount('<p>one two</p> three four'));
        $this->assertSame(2, Str::readingTime(str_repeat('word ', 250)));
    }

    public function testPlurals(): void
    {
        foreach (['post' => 'posts', 'category' => 'categories', 'box' => 'boxes', 'person' => 'people', 'Child' => 'Children',
            'leaf' => 'leaves', 'news' => 'news', 'status' => 'statuses', 'medium' => 'media', 'day' => 'days', 'quiz' => 'quizzes'] as $one => $many) {
            $this->assertSame($many, Str::plural($one), $one);
            $this->assertSame($one, Str::singular($many), $many);
        }
        $this->assertSame('comment', Str::plural('comment', 1));
        $this->assertSame('comments', Str::plural('comment', [1, 2]));
    }

    public function testIdentifiers(): void
    {
        $this->assertSame(32, strlen(Str::random()));
        $this->assertTrue(preg_match('/^[A-Za-z0-9]{40}$/', Str::random(40)) === 1);
        $this->assertTrue(Str::random() !== Str::random());

        $v4 = Str::uuid();
        $this->assertTrue(Str::isUuid($v4) && $v4[14] === '4' && in_array($v4[19], ['8', '9', 'a', 'b'], true));

        $early = Str::uuid7(new \DateTimeImmutable('2026-01-01'));
        $late = Str::uuid7(new \DateTimeImmutable('2026-06-01'));
        $this->assertTrue(Str::isUuid($early) && $early[14] === '7');
        $this->assertTrue(strcmp($early, $late) < 0, 'v7 sorts by time');

        $ulid = Str::ulid();
        $this->assertTrue(Str::isUlid($ulid));
        $this->assertTrue(strcmp(Str::ulid(new \DateTimeImmutable('2020-01-01')), $ulid) < 0, 'ULIDs sort by time');
    }

    public function testStringable(): void
    {
        $this->assertSame('hello-world', (string) str('  Hello   World ')->squish()->slug());
        $this->assertSame('Hello…', str('Hello world')->limit(5)->toString());
        $this->assertTrue(str('admin/x')->is('admin/*'));
        $this->assertSame(11, str('hello world')->length());
        $this->assertSame('[x]', str('x')->wrap('[', ']')->toString());
        $this->assertSame('X!', str('x')->upper()->when(true, fn($s) => $s->append('!'))->toString());
        $this->assertSame('"a"', json_encode(str('a')));
        $this->assertThrows(\BadMethodCallException::class, fn() => str('x')->uuid());
    }

    public function testEdgesFoundInReview(): void
    {
        $this->assertSame('1M', \Kestello\Support\Number::abbreviate(999_950));
        $this->assertSame('1K', \Kestello\Support\Number::abbreviate(999.96));
        $this->assertSame('-1.5K', \Kestello\Support\Number::abbreviate(-1500));
        $this->assertSame('1 MB', \Kestello\Support\Number::fileSize(1_048_575));
        $this->assertSame('1023 B', \Kestello\Support\Number::fileSize(1023));
        $this->assertSame('ćirićNovak', \Kestello\Support\Str::camel('Ćirić novak'));
        $this->assertSame('Smile ', \Kestello\Support\Str::ascii('Smile 😀'));
    }
}
