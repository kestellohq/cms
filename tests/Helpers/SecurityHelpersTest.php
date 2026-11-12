<?php

declare(strict_types=1);

namespace Kestello\Tests\Helpers;

use Kestello\Framework\Testing\TestCase;
use Kestello\Support\AppKey;
use Kestello\Support\Clock;
use Kestello\Support\Crypt;
use Kestello\Support\Hash;
use Kestello\Support\Html;
use Kestello\Support\Url;

final class SecurityHelpersTest extends TestCase
{
    private const string KEY = 'abcdef0123456789abcdef0123456789abcdef0123456789abcdef0123456789';

    protected function setUp(): void
    {
        AppKey::set(self::KEY);
    }

    protected function tearDown(): void
    {
        AppKey::clear();
        Clock::unfreeze();
    }

    // ── Crypt / Hash ────────────────────────────────────────────────

    public function testCryptRoundTripsAndBindsPurpose(): void
    {
        $token = Crypt::encrypt(['user' => 5, 'roles' => ['a']], 'acme.cart');
        $this->assertSame(['user' => 5, 'roles' => ['a']], Crypt::decrypt($token, 'acme.cart'));
        $this->assertThrows(\RuntimeException::class, fn() => Crypt::decrypt($token, 'acme.other'));
        $this->assertNull(Crypt::tryDecrypt($token . 'x', 'acme.cart'));

        // Key rotation: old values still decrypt.
        $new = bin2hex(random_bytes(32));
        AppKey::set($new, [self::KEY]);
        $this->assertSame(['user' => 5, 'roles' => ['a']], Crypt::decrypt($token, 'acme.cart'));
        AppKey::set($new);
        $this->assertNull(Crypt::tryDecrypt($token, 'acme.cart'));
    }

    public function testHashing(): void
    {
        $hash = Hash::password('secret');
        $this->assertTrue(Hash::verify('secret', $hash));
        $this->assertFalse(Hash::verify('wrong', $hash));
        $this->assertFalse(Hash::verify('x', ''));
        $this->assertFalse(Hash::needsRehash($hash));

        $mac = Hash::hmac('token', 'reset');
        $this->assertTrue(Hash::verifyHmac('token', $mac, 'reset'));
        $this->assertFalse(Hash::verifyHmac('token', $mac, 'other'), 'purpose-bound');
        $this->assertTrue($mac !== Hash::hmac('token', 'other'));
        $this->assertThrows(\RuntimeException::class, function () {
            AppKey::clear();
            Hash::hmac('x');
        });
    }

    // ── URLs ────────────────────────────────────────────────────────

    public function testUrlBuilding(): void
    {
        $this->assertSame('/search?q=a&page=2', Url::withQuery('/search?q=a', ['page' => 2]));
        $this->assertSame('https://x.test/p?b=2#top', Url::withoutQuery('https://x.test/p?a=1&b=2#top', ['a']));
        $this->assertSame('https://x.test/p', Url::withoutQuery('https://x.test/p?a=1'));
        $this->assertTrue(Url::isSameOrigin('https://x.test/a', 'https://X.test:443/b'));
        $this->assertFalse(Url::isSameOrigin('https://x.test', 'http://x.test'));
    }

    public function testSafeRedirects(): void
    {
        foreach (['/account', '/a?b=c#d', 'https://example.com/x', 'https://EXAMPLE.com'] as $ok) {
            $this->assertTrue(Url::isSafeRedirect($ok, 'example.com'), $ok);
        }
        foreach (['//evil.com', '/\\evil.com', '\\\\evil.com', 'https://evil.com', 'javascript:alert(1)', "/\tjavascript:x",
            'https://example.com@evil.com', 'https://user:pass@example.com/', "java\nscript:x", '', 'ftp://example.com', 'data:text/html,x'] as $bad) {
            $this->assertFalse(Url::isSafeRedirect($bad, 'example.com'), json_encode($bad));
        }
    }

    public function testSignedUrls(): void
    {
        Clock::freeze('2026-11-04 12:00:00 UTC');
        $signed = Url::signed('https://example.com/unsubscribe?u=5&list=news', expires: 3600);

        $this->assertTrue(Url::hasValidSignature($signed));
        $this->assertTrue(Url::hasValidSignature(str_replace('https://example.com', 'http://internal:8080', $signed)), 'host-independent');
        $this->assertFalse(Url::hasValidSignature(str_replace('u=5', 'u=6', $signed)), 'tampered query');
        $this->assertFalse(Url::hasValidSignature(str_replace('/unsubscribe', '/delete', $signed)), 'tampered path');
        $this->assertFalse(Url::hasValidSignature(Url::withoutQuery($signed, ['expires'])), 'expiry removed');
        $this->assertFalse(Url::hasValidSignature($signed, 'other-purpose'));
        $this->assertFalse(Url::hasValidSignature('https://example.com/x'));

        Clock::travel(3601);
        $this->assertFalse(Url::hasValidSignature($signed), 'expired');

        $forever = Url::signed('/download/5');
        $this->assertTrue(Url::hasValidSignature($forever));
        $this->assertTrue(Url::hasValidSignature(Url::withQuery($forever, [])), 'parameter order normalized');
    }

    // ── HTML ────────────────────────────────────────────────────────

    public function testEscapingAndAttributes(): void
    {
        $this->assertSame('&lt;b&gt; &quot;x&quot; &#039;y&#039; &amp;amp;', Html::escape('<b> "x" \'y\' &amp;'));
        $this->assertSame(
            ' class="btn active" disabled data-id="5" data-cfg="{&quot;a&quot;:1}"',
            Html::attributes(['class' => ['btn', 'active' => true, 'hidden' => false], 'disabled' => true, 'hidden' => false, 'data-id' => 5, 'data-cfg' => ['a' => 1]]),
        );
        $this->assertThrows(\InvalidArgumentException::class, fn() => Html::attributes(['onclick' => 'x()']));
        $this->assertThrows(\InvalidArgumentException::class, fn() => Html::attributes(['a b' => 'x']));
        $this->assertSame('<a href="/x?a=1&amp;b=2">Me &amp; you</a>', Html::tag('a', ['href' => '/x?a=1&b=2'], 'Me & you'));
        $this->assertSame("Title\nPara one & two\nItem", Html::toText('<h1>Title</h1><p>Para one &amp; two</p><ul><li>Item</li></ul><script>evil()</script>'));
    }

    public function testSanitizerKeepsSafeMarkup(): void
    {
        $html = '<h2>Title</h2><p>Hello <strong>bold</strong> <a href="https://x.test/a?b=1&amp;c=2" target="_blank">link</a></p>'
            . '<img src="/media/a.jpg" alt="A" width="300"><table><tr><td colspan="2">cell</td></tr></table><p>Ünïcödé — ћирилица</p>';
        $clean = Html::sanitize($html, 'rich-text');

        $this->assertContains('<h2>Title</h2>', $clean);
        $this->assertContains('<a href="https://x.test/a?b=1&amp;c=2" target="_blank" rel="noopener noreferrer">link</a>', $clean);
        $this->assertContains('<img src="/media/a.jpg" alt="A" width="300">', $clean);
        $this->assertContains('<td colspan="2">cell</td>', $clean);
        $this->assertContains('Ünïcödé — ћирилица', $clean, 'UTF-8 preserved');
        $this->assertSame('', Html::sanitize('   '));
    }

    public function testSanitizerBlocksXss(): void
    {
        $vectors = [
            '<script>alert(1)</script>',
            '<img src=x onerror=alert(1)>',
            '<a href="javascript:alert(1)">x</a>',
            '<a href="JaVaScRiPt:alert(1)">x</a>',
            '<a href="java&#x09;script:alert(1)">x</a>',
            '<a href="&#106;&#97;&#118;&#97;&#115;&#99;&#114;&#105;&#112;&#116;:alert(1)">x</a>',
            "<a href=\" \x01javascript:alert(1)\">x</a>",
            '<a href="vbscript:msgbox(1)">x</a>',
            '<a href="data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg==">x</a>',
            '<img src="data:image/svg+xml,<svg onload=alert(1)>">',
            '<svg><script>alert(1)</script></svg>',
            '<svg onload=alert(1)>',
            '<iframe src="https://evil.test"></iframe>',
            '<object data="x.swf"></object><embed src="x.swf">',
            '<style>body{background:url(javascript:alert(1))}</style>',
            '<p style="background:url(javascript:alert(1))">x</p>',
            '<form action="https://evil.test"><input name=x><button formaction="javascript:alert(1)">go</button></form>',
            '<math><mi xlink:href="javascript:alert(1)">x</mi></math>',
            '<details open ontoggle=alert(1)>',
            '<body onload=alert(1)>',
            '<meta http-equiv="refresh" content="0;url=javascript:alert(1)">',
            '<base href="javascript:alert(1)//">',
            '<noscript><p title="</noscript><img src=x onerror=alert(1)>">',
            '<img srcset="x.jpg 1x, javascript:alert(1) 2x">',
            '<a href="#" onclick="alert(1)">x</a>',
            '<<script>script>alert(1)<</script>/script>',
            '<!--<img src=x onerror=alert(1)>-->',
            '<div id="kestello-root"><img src=x onerror=alert(1)></div>',
            '<template><img src=x onerror=alert(1)></template>',
            '<a href="/x" id="__proto__" name="config">dom clobbering</a>',
            '<isindex action="javascript:alert(1)" type="image">',
        ];

        foreach ($vectors as $vector) {
            $out = strtolower(Html::sanitize($vector, 'rich-text'));
            foreach (['<script', 'javascript:', 'vbscript:', 'data:', 'onerror', 'onload', 'onclick', 'ontoggle', '<iframe', '<object',
                '<embed', '<style', 'style=', '<form', '<input', '<svg', '<math', '<meta', '<base', ' id=', ' name=', 'formaction'] as $needle) {
                $this->assertFalse(str_contains($out, $needle), "{$needle} survived in: {$vector} → {$out}");
            }

        }
    }

    public function testARelativeLinkWithABackslashIsRefused(): void
    {
        // browsers read /\evil.test as //evil.test, another site
        $this->assertStringNotContainsString('evil.test', Html::sanitize('<a href="/\\evil.test">x</a>', 'rich-text'));
        $this->assertStringContainsString('href="/about"', Html::sanitize('<a href="/about">x</a>', 'rich-text'));
        $this->assertFalse(\Kestello\Cms\Menus\Menus::safeUrl('/\\evil.test'));
        $this->assertTrue(\Kestello\Cms\Menus\Menus::safeUrl('/about'));
    }

    public function testBasicProfileAndCustomProfiles(): void
    {
        $out = Html::sanitize('<h1>T</h1><p>Hi <a href="https://x.test">x</a> <img src="/a.jpg"></p>', 'basic');
        $this->assertSame('T<p>Hi <a href="https://x.test" rel="nofollow ugc">x</a> </p>', $out, 'headings unwrapped, images dropped, external links nofollow');

        Html::profile('acme-chat', ['elements' => ['b' => [], 'a' => ['href']], 'link_schemes' => ['https']]);
        $this->assertSame('<b>x</b> <a>y</a>', Html::sanitize('<b>x</b> <a href="http://x.test">y</a>', 'acme-chat'));
        $this->assertThrows(\InvalidArgumentException::class, fn() => Html::sanitize('x', 'nope'));
    }

    public function testSvgSanitizer(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" viewBox="0 0 10 10" onload="alert(1)">'
            . '<script>alert(1)</script><defs><linearGradient id="g"><stop offset="0" stop-color="red"/></linearGradient></defs>'
            . '<rect width="10" height="10" fill="url(#g)" onclick="x()"/><use href="#g"/><use xlink:href="https://evil.test/x.svg#a"/>'
            . '<foreignObject><iframe src="x"/></foreignObject><a href="javascript:alert(1)"><circle r="1"/></a>'
            . '<animate attributeName="href" to="javascript:alert(1)"/><path d="M0 0L10 10" fill="url(https://evil.test/a)"/></svg>';
        $out = Html::sanitize($svg, 'svg');

        $this->assertContains('<rect width="10" height="10" fill="url(#g)"/>', $out);
        $this->assertContains('<use href="#g"/>', $out);
        $this->assertContains('viewBox="0 0 10 10"', $out);
        foreach (['script', 'onload', 'onclick', 'evil.test', 'javascript', 'foreignobject', 'iframe', 'animate', '<a '] as $needle) {
            $this->assertFalse(str_contains(strtolower($out), $needle), "{$needle} survived: {$out}");
        }

        $this->assertSame('', Html::sanitize('<!DOCTYPE svg [<!ENTITY x "y">]><svg>&x;</svg>', 'svg'), 'no DTDs (XXE, billion laughs)');
        $this->assertSame('', Html::sanitize('<html><svg/></html>', 'svg'), 'root must be svg');
        $this->assertSame('', Html::sanitize('<svg><unclosed></svg>', 'svg'), 'malformed');
    }
}
