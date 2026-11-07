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
        [, $body] = explode("\r\n\r\n", $data, 2);

        return base64_decode(str_replace("\r\n", '', $body));
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
        $this->assertTrue(str_contains($head, "\r\nContent-Transfer-Encoding: base64"));
        $this->assertTrue(str_contains($head, "\r\nReply-To: visitor@example.test"));
        foreach (explode("\r\n", $data) as $line) {
            $this->assertTrue(strlen($line) <= 998, 'no line over the RFC 5322 limit');
        }
        $this->assertSame('<p>Hi</p>', self::body($data));
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
