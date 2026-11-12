<?php

declare(strict_types=1);

namespace Kestello\Tests\Helpers;

use Dom\Element;
use Dom\HTMLDocument;
use Kestello\Framework\Testing\TestCase;
use Kestello\Support\Html;

/**
 * Markup written to get past HTML sanitizers: parser differences (what the
 * sanitizer reads as text, a browser reads as an element), elements whose
 * content follows other rules (noscript, xmp, svg, math), and URL tricks.
 * Rather than an expected string for each, every result is checked the way it
 * will be used: parsed by a browser's algorithm, it must hold no script, no
 * event handler and no unsafe link, and sanitizing it again changes nothing
 * (if it did, it would parse differently the second time: mutation XSS).
 */
final class SanitizerBypassTest extends TestCase
{
    /** @var list<string> */
    private const array PAYLOADS = [
        '<noscript><p title="</noscript><img src=x onerror=alert(1)>">',
        '<svg><style><img src=x onerror=alert(1)></style></svg>',
        '<svg></p><style><a id="</style><img src=1 onerror=alert(1)>">',
        '<math><mtext><table><mglyph><style><img src=x onerror=alert(1)>',
        '<form><math><mtext></form><form><mglyph><style></math><img src onerror=alert(1)>',
        '<math><mi><table><mi><mglyph><svg><mtext><textarea><path id="</textarea><img onerror=alert(1) src=1>">',
        '<xmp><img title="</xmp><img src=x onerror=alert(1)>">',
        '<textarea><img title="</textarea><img src=x onerror=alert(1)>">',
        '<title><img title="</title><img src=x onerror=alert(1)>">',
        '<noembed><img title="</noembed><img src=x onerror=alert(1)>">',
        '<noframes><img title="</noframes><img src=x onerror=alert(1)>">',
        '<plaintext><img src=x onerror=alert(1)>',
        '<select><template><style><img src=x onerror=alert(1)>',
        '<template><img src=x onerror=alert(1)></template>',
        '<scr<script>ipt>alert(1)</script>',
        '<<img src=x onerror=alert(1)>',
        '<img src=x onerror=alert(1)//',
        "<img src=x onerror\n=alert(1)>",
        '<img/src=x/onerror=alert(1)>',
        '<!--><img src=x onerror=alert(1)>-->',
        '<p><!-- --!><img src=x onerror=alert(1)> --></p>',
        '<![CDATA[<img src=x onerror=alert(1)>]]>',
        '<?xml ><img src=x onerror=alert(1)>?>',
        '<iframe srcdoc="<script>alert(1)</script>"></iframe>',
        '<details open ontoggle=alert(1)>x</details>',
        '<img src="x" alt="&quot; onerror=alert(1) x=&quot;">',
        '<table><td><a href="javascript:alert(1)">x</a>',
        '<a href="javascript:alert(1)">x</a>',
        '<a href="JaVaScRiPt:alert(1)">x</a>',
        '<a href="  javascript:alert(1)">x</a>',
        '<a href="java&#x09;script:alert(1)">x</a>',
        '<a href="&#106;avascript:alert(1)">x</a>',
        '<a href="javascript&colon;alert(1)">x</a>',
        "<a href=\"java\0script:alert(1)\">x</a>",
        '<a href="vbscript:msgbox(1)">x</a>',
        '<a href="data:text/html,<script>alert(1)</script>">x</a>',
        '<a href="/\evil.test">x</a>',
        '<img srcset="x 1x, javascript:alert(1) 2x">',
        '<div style="background:url(javascript:alert(1))">x</div>',
        '<a href="https://example.com" onclick="alert(1)" target="_self">x</a>',
        '<div class="x&quot; onmouseover=&quot;alert(1)">x</div>',
        '<object data="javascript:alert(1)"></object><embed src="javascript:alert(1)">',
        '<base href="javascript:/"><a href="x">x</a>',
        '<meta http-equiv="refresh" content="0;url=javascript:alert(1)">',
        '<isindex action="javascript:alert(1)" type=image>',
        '<button formaction="javascript:alert(1)">x</button>',
        '<a href="https://example.com"><svg><a href="javascript:alert(1)">x</a></svg></a>',
        '<p>ok <b>bold</b> <i>it</i> &amp; <a href="/page">link</a></p>',
    ];

    public function testNothingThatRunsGetsThrough(): void
    {
        foreach (['rich-text', 'basic'] as $profile) {
            foreach (self::PAYLOADS as $payload) {
                $out = Html::sanitize($payload, $profile);
                $this->assertSame([], self::dangers($out), "{$profile}: " . json_encode($payload) . ' gave ' . json_encode($out));
                $this->assertSame($out, Html::sanitize($out, $profile), "{$profile}: sanitizing again changes nothing for " . json_encode($payload));
            }
        }
    }

    public function testOrdinaryMarkupIsKept(): void
    {
        $this->assertSame('<p>ok <b>bold</b> <i>it</i> &amp; <a href="/page">link</a></p>', Html::sanitize('<p>ok <b>bold</b> <i>it</i> &amp; <a href="/page">link</a></p>'));
        $this->assertSame('<p>a</p><p>b</p>', Html::sanitize('<p>a<p>b'), 'unclosed paragraphs close where a browser closes them');
        $this->assertSame('<table><tbody><tr><td>1</td></tr></tbody></table>', Html::sanitize('<table><tr><td>1</td></tr></table>'));
        $this->assertSame('<p>café ✓ 日本</p>', Html::sanitize('<p>café ✓ 日本</p>'));
        // the HTML4 parser lost these
        $this->assertSame('<p>5 &lt; 6 and 7 &gt; 3</p>', Html::sanitize('<p>5 < 6 and 7 > 3</p>'), 'text with < and > in it is kept');
        $this->assertSame('<b>bold</b> <i>italic</i>', Html::sanitize('<b>bold</b> <i>italic</i>'), 'the space between two inline elements is kept');
    }

    /** What in this HTML could run or lead somewhere unsafe, read with a browser's parser. */
    private static function dangers(string $html): array
    {
        $doc = HTMLDocument::createEmpty();
        $root = $doc->createElement('div');
        $doc->appendChild($root);
        $root->innerHTML = $html;

        $found = [];
        foreach ($root->querySelectorAll('*') as $el) {
            /** @var Element $el */
            if ($el->namespaceURI !== 'http://www.w3.org/1999/xhtml') {
                $found[] = "<{$el->localName}> outside HTML";
            }
            if (in_array($el->localName, ['script', 'style', 'iframe', 'object', 'embed', 'base', 'meta', 'form', 'button', 'template', 'noscript', 'xmp', 'plaintext', 'textarea', 'title'], true)) {
                $found[] = "<{$el->localName}>";
            }
            foreach ($el->attributes as $attr) {
                if (str_starts_with(strtolower($attr->name), 'on') || in_array($attr->name, ['style', 'srcdoc', 'formaction'], true)) {
                    $found[] = "{$attr->name} on <{$el->localName}>";
                }
                if (in_array($attr->name, ['href', 'src', 'action', 'data'], true) && !Html::safeUrl($attr->value)) {
                    $found[] = "{$attr->name}=\"{$attr->value}\"";
                }
            }
        }

        return $found;
    }
}
