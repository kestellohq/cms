<?php

declare(strict_types=1);

namespace Kestello\Tests\Framework;

use Kestello\Framework\Core\Compression;
use Kestello\Framework\Core\Request;
use Kestello\Framework\Core\Response;
use Kestello\Framework\Testing\TestCase;

final class CompressionTest extends TestCase
{
    private static function request(array $headers = ['Accept-Encoding' => 'gzip, br'], array $cookies = []): Request
    {
        return new Request('GET', '/', '/', $headers, [], [], $cookies, [], []);
    }

    private static function page(array $headers = []): Response
    {
        return new Response(str_repeat('<p>Plates on a wall.</p>', 200), 200, $headers + ['Content-Type' => 'text/html; charset=UTF-8', 'ETag' => '"abc"', 'Vary' => 'X-Kestello']);
    }

    public function testAnonymousTextIsGzipped(): void
    {
        $out = Compression::apply(self::request(), self::page());
        $this->assertSame('gzip', $out->headers()['Content-Encoding'] ?? null);
        $this->assertSame('X-Kestello, Accept-Encoding', $out->headers()['Vary']);
        $this->assertSame('"abc-gzip"', $out->headers()['ETag']);
        $this->assertSame(self::page()->content(), gzdecode($out->content()));
    }

    public function testPersonalResponsesAreLeftAlone(): void
    {
        // a cookie may mean a session: secrets and reflected input together leak through size (BREACH)
        $this->assertArrayNotHasKey('Content-Encoding', Compression::apply(self::request(cookies: ['kestello_session' => 'x']), self::page())->headers());
        $this->assertArrayNotHasKey('Content-Encoding', Compression::apply(self::request(['Accept-Encoding' => 'gzip', 'Authorization' => 'Bearer x']), self::page())->headers());
        $this->assertArrayNotHasKey('Content-Encoding', Compression::apply(self::request(), self::page()->withCookie('a', 'b'))->headers());
        // unless the response says it is the same for everyone
        $public = Compression::apply(self::request(cookies: ['kestello_session' => 'x']), self::page(['Cache-Control' => 'public, max-age=31536000, immutable']));
        $this->assertSame('gzip', $public->headers()['Content-Encoding'] ?? null);
    }

    public function testOnlyWhenItHelpsAndIsAccepted(): void
    {
        $this->assertArrayNotHasKey('Content-Encoding', Compression::apply(self::request([]), self::page())->headers());
        $this->assertArrayNotHasKey('Content-Encoding', Compression::apply(self::request(['Accept-Encoding' => 'gzip;q=0, br']), self::page())->headers());
        $this->assertArrayNotHasKey('Content-Encoding', Compression::apply(self::request(), new Response('short', 200, ['Content-Type' => 'text/plain']))->headers());
        $this->assertArrayNotHasKey('Content-Encoding', Compression::apply(self::request(), new Response(str_repeat("\0", 4000), 200, ['Content-Type' => 'font/woff2']))->headers());
        $already = self::page(['Content-Encoding' => 'br']);
        $this->assertSame($already->content(), Compression::apply(self::request(), $already)->content());
    }

    public function testEtagsMatchEitherVariant(): void
    {
        $this->assertTrue(Compression::etagMatches('"abc-gzip"', '"abc"'));
        $this->assertTrue(Compression::etagMatches('"x", W/"abc"', '"abc"'));
        $this->assertTrue(Compression::etagMatches('"abc"', '"abc"'));
        $this->assertFalse(Compression::etagMatches('"abd-gzip"', '"abc"'));
        $this->assertFalse(Compression::etagMatches('', '"abc"'));
    }
}
