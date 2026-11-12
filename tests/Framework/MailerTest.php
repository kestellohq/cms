<?php

declare(strict_types=1);

namespace Kestello\Tests\Framework;

use Kestello\Framework\Mail\Mailer;
use Kestello\Framework\Testing\TestCase;

/** The built-in SMTP sender: what reaches the wire, and what never may. */
final class MailerTest extends TestCase
{
    /**
     * Sends through a one-connection fake SMTP server and returns its log.
     *
     * @return list<array{cmd?: string, data?: string}>
     */
    private function sendVia(callable $send): array
    {
        $log = tempnam(sys_get_temp_dir(), 'smtp');
        $proc = proc_open([PHP_BINARY, __DIR__ . '/fixtures/smtp-server.php', $log], [1 => ['pipe', 'w']], $pipes);
        $port = (int) trim((string) fgets($pipes[1]));
        try {
            $send(new Mailer([
                'smtp_host' => '127.0.0.1',
                'smtp_port' => (string) $port,
                'smtp_encryption' => 'none',
                'mail_from_address' => 'site@example.test',
                'mail_from_name' => 'Studio Ćirić',
            ]));
        } finally {
            proc_close($proc);
        }
        $out = json_decode((string) file_get_contents($log), true) ?: [];
        unlink($log);

        return $out;
    }

    private static function data(array $log): string
    {
        foreach ($log as $entry) {
            if (isset($entry['data'])) {
                return $entry['data'];
            }
        }

        return '';
    }

    private static function body(string $data): string
    {
        return self::parts($data)['text/html'] ?? '';
    }

    /**
     * The decoded parts of a message, by type, checked the way a mail reader
     * reads them: a multipart/alternative with a quoted boundary, every part
     * declared UTF-8 and base64, and nothing after the closing boundary.
     *
     * @return array<string, string>
     */
    private static function parts(string $data): array
    {
        [$head, $body] = explode("\r\n\r\n", $data, 2);
        $body = rtrim($body, "\r\n");    // the CRLF before the "." that ends DATA
        if (preg_match('/\r\nContent-Type: multipart\/alternative; boundary="([^"]+)"\r\n/', "\r\n" . $head . "\r\n", $m) !== 1) {
            preg_match('/\r\nContent-Type: ([^;\r]+)/', "\r\n" . $head, $type);

            return [$type[1] ?? '' => base64_decode(str_replace("\r\n", '', $body), true)];
        }
        $boundary = $m[1];
        if (!str_ends_with($body, "\r\n--{$boundary}--")) {
            throw new \RuntimeException('The multipart body does not end with its closing boundary.');
        }
        $out = [];
        foreach (array_slice(explode("--{$boundary}\r\n", substr($body, 0, -strlen("\r\n--{$boundary}--"))), 1) as $part) {
            [$partHead, $content] = explode("\r\n\r\n", $part, 2);
            if (preg_match('/^Content-Type: (text\/(?:plain|html)); charset=UTF-8\r\nContent-Transfer-Encoding: base64$/', $partHead, $t) !== 1) {
                throw new \RuntimeException("Unexpected part headers: {$partHead}");
            }
            $decoded = base64_decode(str_replace("\r\n", '', rtrim($content, "\r\n")), true);
            if ($decoded === false) {
                throw new \RuntimeException('A part is not valid base64.');
            }
            $out[$t[1]] = $decoded;
        }

        return $out;
    }

    public function testALineWithASingleDotCannotEndTheMessage(): void
    {
        $text = "Hello\r\n.\r\nMAIL FROM:<ceo@example.test>\r\nRCPT TO:<victim@example.test>\r\nDATA\r\nSpam\r\n.\r\n";
        $log = $this->sendVia(fn(Mailer $m) => $this->assertTrue($m->send('owner@example.test', 'Form', $text)));

        $commands = array_values(array_filter(array_map(static fn($e) => $e['cmd'] ?? null, $log)));
        $this->assertSame(1, count(array_filter($commands, static fn($c) => str_starts_with($c, 'RCPT'))), 'only the real recipient');
        $this->assertSame(['EHLO', 'MAIL', 'RCPT', 'DATA', 'QUIT'], array_map(static fn($c) => substr($c, 0, 4), $commands));
        $this->assertSame($text, self::body(self::data($log)), 'the body arrives intact');
    }

    public function testHeadersAreEncodedAndComplete(): void
    {
        $log = $this->sendVia(fn(Mailer $m) => $m->send('owner@example.test', "Nova poruka:\r\nBcc: x@evil.test Ђорђе", '<p>Hi</p>', ['Reply-To' => 'visitor@example.test']));
        $data = self::data($log);
        [$head] = explode("\r\n\r\n", $data, 2);

        $this->assertFalse(str_contains($head, "\r\nBcc:"), 'a line break in the subject never starts a header');
        $this->assertTrue((bool) preg_match('/\r\nSubject: Nova poruka: Bcc: x@evil\.test =\?UTF-8\?B\?/', $head), 'non-ASCII subject is RFC 2047 encoded');
        $this->assertTrue((bool) preg_match('/\r\nFrom: [^\r]*=\?UTF-8\?B\?[^\r]* <site@example\.test>/', $head), 'the sender name too');
        $this->assertTrue(str_contains($head, '<site@example.test>'));
        $this->assertTrue(str_contains($head, "\r\nDate: "));
        $this->assertTrue(str_contains($head, "\r\nMessage-ID: <"));
        $this->assertTrue(str_contains($head, "\r\nReply-To: visitor@example.test"));
        foreach (explode("\r\n", $data) as $line) {
            $this->assertTrue(strlen($line) <= 998, 'no line over the RFC 5322 limit');
        }
        $this->assertSame('<p>Hi</p>', self::body($data));
    }

    public function testTheMessageHasAPlainTextAndAnHtmlPart(): void
    {
        $html = '<p>Zdravo Đorđe,</p><p><a href="https://site.test/x">Otvori</a></p>';
        $text = "Zdravo Đorđe,\nOtvori: https://site.test/x\n" . str_repeat('ž', 120);
        $log = $this->sendVia(fn(Mailer $m) => $this->assertTrue($m->send('owner@example.test', 'Pozdrav', $html, [], $text)));
        $data = self::data($log);
        [$head, $body] = explode("\r\n\r\n", $data, 2);

        $this->assertTrue((bool) preg_match('/\r\nContent-Type: multipart\/alternative; boundary="(=_[0-9a-f]{32})"(\r\n|$)/', "\r\n" . $head, $m), 'multipart/alternative with a quoted boundary');
        $this->assertTrue(str_contains($head, "\r\nMIME-Version: 1.0"));
        $this->assertFalse(str_contains($head, 'Content-Transfer-Encoding'), 'only the parts have an encoding');
        $this->assertFalse(str_contains(base64_decode(str_replace("\r\n", '', $body)) ?: '', $m[1]), 'the boundary appears nowhere in the content');
        $this->assertSame(1, preg_match_all('/^--' . preg_quote($m[1], '/') . '--\r?$/m', $body), 'one closing boundary');
        $this->assertSame(0, preg_match('/(?<!\r)\n|\r(?!\n)/', $data), 'every line ends in CRLF');
        foreach (explode("\r\n", $body) as $line) {
            $this->assertTrue(strlen($line) <= 76, 'base64 lines of at most 76 characters');
        }
        $parts = self::parts($data);
        $this->assertSame(['text/plain', 'text/html'], array_keys($parts), 'text first, HTML last: readers show the last part they understand');
        $this->assertSame(str_replace("\n", "\r\n", $text), $parts['text/plain'], 'the text part, UTF-8, with CRLF line breaks');
        $this->assertSame($html, $parts['text/html']);
    }

    public function testHtmlOnlyCallersGetATextPartMadeFromTheHtml(): void
    {
        $html = '<!DOCTYPE html><html><head><title>Hi</title><style>p{color:red}</style></head><body>'
            . '<h1>Hi Ana</h1><p>Fish &amp; chips<br>at 5</p><p><a href="https://site.test/a?b=1&amp;c=2">Open it</a></p>'
            . '<table><tr><th>Name</th><td>Ana &lt;b&gt;</td></tr><tr><th>Topic</th><td>Order</td></tr></table></body></html>';
        $log = $this->sendVia(fn(Mailer $m) => $this->assertTrue($m->send('owner@example.test', 'Hi', $html)));
        $parts = self::parts(self::data($log));

        $this->assertSame($html, $parts['text/html'], 'the HTML goes as it was');
        $this->assertSame("Hi Ana\r\n\r\nFish & chips\r\nat 5\r\n\r\nOpen it (https://site.test/a?b=1&c=2)\r\n\r\nName: Ana <b>\r\nTopic: Order", $parts['text/plain'], 'words, breaks and link addresses, without markup or the head');
    }

    public function testACallersOwnContentTypeIsSentAsTheOnlyPart(): void
    {
        $log = $this->sendVia(fn(Mailer $m) => $this->assertTrue($m->send('owner@example.test', 'Plain', 'Hello', ['Content-Type' => 'text/plain; charset=UTF-8'])));
        $data = self::data($log);

        $this->assertFalse(str_contains($data, 'multipart'), 'no alternative is made for a body of another type');
        $this->assertSame(['text/plain' => 'Hello'], self::parts($data));
    }

    public function testUnsafeAddressesAndHeadersAreRefused(): void
    {
        $m = new Mailer(['smtp_host' => '127.0.0.1', 'mail_from_address' => 'site@example.test']);
        $this->assertThrows(\InvalidArgumentException::class, fn() => $m->send("a@example.test>\r\nRCPT TO:<b@example.test", 'x', 'x'));
        $this->assertThrows(\InvalidArgumentException::class, fn() => $m->send('not an address', 'x', 'x'));
        $this->assertThrows(\InvalidArgumentException::class, fn() => $m->send([], 'x', 'x'));
        $this->assertThrows(\InvalidArgumentException::class, fn() => $m->send('a@example.test', 'x', 'x', ['Reply-To' => "a@example.test\r\nBcc: b@example.test"]));
        $this->assertThrows(\InvalidArgumentException::class, fn() => $m->send('a@example.test', 'x', 'x', ['Bcc' => 'b@example.test']));
        $this->assertThrows(\InvalidArgumentException::class, fn() => $m->send('a@example.test', 'x', 'x', ["X-A\r\nBcc" => 'b']));
    }

    public function testAFailedSendReturnsFalse(): void
    {
        // Nothing listens on port 1.
        $m = new Mailer(['smtp_host' => '127.0.0.1', 'smtp_port' => '1', 'smtp_encryption' => 'none', 'mail_from_address' => 'site@example.test']);
        $this->assertFalse($m->send('a@example.test', 'x', 'x'));
    }
}
