<?php

declare(strict_types=1);

namespace Kestello\Framework\Mail;

use Kestello\Contracts\Mail\Message;
use Kestello\Contracts\Mail\Transport;
use Kestello\Framework\Settings\Settings;
use RuntimeException;

/**
 * Mail sender with SMTP and PHP mail() fallback.
 *
 * If SMTP settings are configured in the database, uses SMTP via fsockopen.
 * Otherwise falls back to PHP's built-in mail() function.
 */
final class Mailer
{
    private string $smtpHost = '';
    private int $smtpPort = 587;
    private string $smtpUsername = '';
    private string $smtpPassword = '';
    private string $smtpEncryption = 'tls';
    private string $fromAddress = '';
    private string $fromName = '';
    private bool $useSmtp = false;

    /**
     * @param array<string, string>|null $settings Use these instead of the saved
     *        settings (same keys: smtp_host, smtp_port, ...), e.g. to test a
     *        configuration before saving it.
     */
    public function __construct(?array $settings = null)
    {
        $this->loadSettings($settings);
    }

    /**
     * Send an email. It goes out as multipart/alternative, a text/plain part
     * and the text/html part, so mail readers that show text and spam
     * filters both get what they expect. Without $text, the text part is
     * made from the HTML. A Content-Type header of the caller's own sends
     * the body as the only part, as given.
     *
     * @param string|array<string> $to      Recipient(s).
     * @param string               $subject Email subject.
     * @param string               $body    HTML body.
     * @param array<string, string> $headers Additional headers.
     * @param string|null          $text    Plain-text version of the body.
     *
     * @return bool Whether the email was sent successfully.
     */
    public function send(string|array $to, string $subject, string $body, array $headers = [], ?string $text = null): bool
    {
        $recipients = self::recipients(is_array($to) ? $to : [$to]);
        $subject = self::oneLine($subject);
        $from = self::address($this->fromAddress);
        $headers = self::headers($headers);
        $text = match (true) {
            array_key_exists('content-type', array_change_key_case($headers)) => '',
            $text !== null && $text !== '' => $text,
            default => PlainText::fromHtml($body),
        };

        $defaultHeaders = [
            'MIME-Version' => '1.0',
            'Content-Type' => 'text/html; charset=UTF-8',
            'From' => $this->fromName !== ''
                ? self::encodeHeader(self::oneLine($this->fromName)) . " <{$from}>"
                : $from,
        ];

        $mergedHeaders = array_merge($defaultHeaders, $headers);

        $transport = self::boundTransport();
        if ($transport !== null) {
            return $transport->send(new Message($recipients, $subject, $body, $mergedHeaders, $text));
        }

        if ($this->useSmtp) {
            return $this->sendViaSmtp($recipients, $subject, $body, $mergedHeaders, $text);
        }

        return $this->sendViaPhpMail($recipients, $subject, $body, $mergedHeaders, $text);
    }

    /**
     * Send using PHP's built-in mail() function.
     */
    private function sendViaPhpMail(array $recipients, string $subject, string $body, array $headers, string $text): bool
    {
        [$headers, $content] = self::mime($body, $text, $headers);
        $headerString = '';
        foreach ($headers as $name => $value) {
            $headerString .= $name . ': ' . self::encodeHeader($value) . "\r\n";
        }

        return @mail(implode(', ', $recipients), self::encodeHeader($subject), $content, rtrim($headerString));
    }

    /**
     * Send using SMTP via raw socket connection.
     */
    private function sendViaSmtp(array $recipients, string $subject, string $body, array $headers, string $text): bool
    {
        try {
            $conn = $this->smtpConnect();

            // EHLO
            $this->smtpCommand($conn, 'EHLO ' . gethostname(), 250);

            // STARTTLS if needed
            if ($this->smtpEncryption === 'tls') {
                $this->smtpCommand($conn, 'STARTTLS', 220);
                // Never continue in clear text: the next command sends the password.
                if (@stream_socket_enable_crypto($conn, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT) !== true) {
                    throw new RuntimeException('The mail server did not complete STARTTLS.');
                }
                $this->smtpCommand($conn, 'EHLO ' . gethostname(), 250);
            }

            // AUTH
            if ($this->smtpUsername !== '') {
                $this->smtpCommand($conn, 'AUTH LOGIN', 334);
                $this->smtpCommand($conn, base64_encode($this->smtpUsername), 334);
                $this->smtpCommand($conn, base64_encode($this->smtpPassword), 235);
            }

            // MAIL FROM
            $this->smtpCommand($conn, 'MAIL FROM:<' . self::address($this->fromAddress) . '>', 250);

            // RCPT TO
            foreach ($recipients as $recipient) {
                $this->smtpCommand($conn, "RCPT TO:<{$recipient}>", 250);
            }

            // DATA
            $this->smtpCommand($conn, 'DATA', 354);

            $this->smtpCommand($conn, self::compose($recipients, $subject, $body, $headers, $text) . "\r\n.", 250);

            // QUIT
            $this->smtpCommand($conn, 'QUIT', 221);

            fclose($conn);

            return true;
        } catch (\InvalidArgumentException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $logger = new \Kestello\Framework\Log\Logger(self::storageDir('logs'));
            $logger->error('SMTP send failed: ' . $e->getMessage());

            return false;
        }
    }

    /**
     * The message as sent after DATA, without the terminating ".": headers,
     * the body (see mime()), CRLF line endings, and any line that starts
     * with "." doubled so it can never end the data early.
     *
     * @internal
     * @param list<string>          $recipients already validated
     * @param array<string, string> $headers    already validated
     */
    public static function compose(array $recipients, string $subject, string $html, array $headers, string $text = ''): string
    {
        [$headers, $content] = self::mime($html, $text, $headers);
        $headers = ['To' => implode(', ', $recipients), 'Subject' => $subject] + $headers + [
            'Date' => date(DATE_RFC2822),
            'Message-ID' => '<' . bin2hex(random_bytes(16)) . '@' . self::domain($headers['From'] ?? '') . '>',
        ];

        $out = '';
        foreach ($headers as $name => $value) {
            $out .= $name . ': ' . ($name === 'To' ? $value : self::encodeHeader($value)) . "\r\n";
        }
        $out .= "\r\n" . $content;

        return preg_replace('/^\./m', '..', rtrim($out, "\r\n")) ?? '';
    }

    /**
     * The body as it goes on the wire, and the headers that describe it.
     * With a text part: multipart/alternative (RFC 2046), the text/plain part
     * first and the text/html part last, as readers show the last part they
     * understand. Each part is UTF-8 in base64, in 76-character lines; text
     * line breaks become CRLF first, as RFC 2046 asks of text before it is
     * encoded. The boundary starts with "=_", which base64 never contains.
     * Without a text part: the HTML alone, as before.
     *
     * @internal
     * @param array<string, string> $headers
     * @return array{0: array<string, string>, 1: string} headers, body
     */
    public static function mime(string $html, string $text, array $headers, ?string $boundary = null): array
    {
        if ($text === '') {
            $headers['Content-Transfer-Encoding'] = 'base64';

            return [$headers, self::encodeBody($html)];
        }

        $boundary ??= '=_' . bin2hex(random_bytes(16));
        $headers['Content-Type'] = 'multipart/alternative; boundary="' . $boundary . '"';
        unset($headers['Content-Transfer-Encoding']);
        $part = static fn(string $type, string $content): string => "--{$boundary}\r\n"
            . "Content-Type: {$type}; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: base64\r\n\r\n"
            . self::encodeBody($content) . "\r\n";

        return [$headers, $part('text/plain', (string) preg_replace('/\r\n|\r|\n/', "\r\n", $text))
            . $part('text/html', $html)
            . "--{$boundary}--"];
    }

    /** @return list<string> */
    private static function recipients(array $to): array
    {
        $out = [];
        foreach ($to as $address) {
            $out[] = self::address((string) $address);
        }
        if ($out === []) {
            throw new \InvalidArgumentException('An email needs at least one recipient.');
        }

        return $out;
    }

    private static function address(string $address): string
    {
        $address = trim($address);
        if (preg_match('/[\r\n<>,]/', $address) || filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
            throw new \InvalidArgumentException("Not an email address: \"{$address}\".");
        }

        return $address;
    }

    /**
     * @param array<string, string> $headers
     * @return array<string, string>
     */
    private static function headers(array $headers): array
    {
        $out = [];
        foreach ($headers as $name => $value) {
            $name = (string) $name;
            if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9-]*$/', $name)) {
                throw new \InvalidArgumentException("Not a header name: \"{$name}\".");
            }
            if (in_array(strtolower($name), ['to', 'cc', 'bcc', 'subject', 'content-transfer-encoding'], true)) {
                throw new \InvalidArgumentException("The {$name} header cannot be set this way.");
            }
            if (preg_match('/[\r\n]/', (string) $value)) {
                throw new \InvalidArgumentException("The {$name} header contains a line break.");
            }
            $out[$name] = (string) $value;
        }

        return $out;
    }

    private static function oneLine(string $value): string
    {
        return trim(preg_replace('/[\r\n\t]+/', ' ', $value) ?? '');
    }

    /** RFC 2047 for values that are not plain ASCII. */
    private static function encodeHeader(string $value): string
    {
        return preg_match('/[^\x20-\x7E]/', $value) ? mb_encode_mimeheader($value, 'UTF-8', 'B', "\r\n") : $value;
    }

    private static function encodeBody(string $body): string
    {
        return rtrim(chunk_split(base64_encode($body), 76, "\r\n"));
    }

    private static function domain(string $from): string
    {
        return preg_match('/@([A-Za-z0-9.-]+)>?\s*$/', $from, $m) ? $m[1] : 'localhost';
    }

    /**
     * Open an SMTP socket connection.
     *
     * @return resource
     */
    private function smtpConnect()
    {
        $host = $this->smtpEncryption === 'ssl'
            ? "ssl://{$this->smtpHost}"
            : $this->smtpHost;

        $conn = @fsockopen($host, $this->smtpPort, $errno, $errstr, 30);

        if ($conn === false) {
            throw new RuntimeException("SMTP connection failed: {$errstr} ({$errno})");
        }

        // Read greeting
        $this->smtpReadResponse($conn, 220);

        return $conn;
    }

    /**
     * Send an SMTP command and validate the response code.
     *
     * @param resource $conn
     */
    private function smtpCommand($conn, string $command, int $expectedCode): string
    {
        fwrite($conn, $command . "\r\n");

        return $this->smtpReadResponse($conn, $expectedCode);
    }

    /**
     * Read the SMTP response and validate the status code.
     *
     * @param resource $conn
     */
    private function smtpReadResponse($conn, int $expectedCode): string
    {
        $response = '';

        while ($line = fgets($conn, 515)) {
            $response .= $line;
            // Multi-line responses have '-' after the code, last line has space
            if (isset($line[3]) && $line[3] === ' ') {
                break;
            }
        }

        $code = (int) substr($response, 0, 3);

        if ($code !== $expectedCode) {
            throw new RuntimeException("SMTP error: expected {$expectedCode}, got {$code}. Response: {$response}");
        }

        return $response;
    }

    /**
     * Load SMTP settings from the database.
     */
    private function loadSettings(?array $override): void
    {
        $settings = $override === null ? Settings::getInstance() : new class ($override) {
            /** @param array<string, string> $values */
            public function __construct(private array $values) {}

            public function get(string $key, mixed $default = null): mixed
            {
                return $this->values[$key] ?? $default;
            }
        };

        $this->smtpHost = (string) $settings->get('smtp_host', '');
        $this->smtpPort = (int) $settings->get('smtp_port', '587');
        $this->smtpUsername = (string) $settings->get('smtp_username', '');
        $this->smtpPassword = (string) $settings->get('smtp_password', '');
        $this->smtpEncryption = (string) $settings->get('smtp_encryption', 'tls');
        $this->fromAddress = (string) $settings->get('mail_from_address', 'noreply@example.com');
        $this->fromName = (string) $settings->get('mail_from_name', '');

        // Use SMTP if host is configured
        $this->useSmtp = $this->smtpHost !== '';
    }

    /**
     * Check if SMTP is configured and available.
     */
    public function isSmtpConfigured(): bool
    {
        return $this->useSmtp;
    }

    /**
     * Send a test email to verify configuration.
     *
     * @deprecated Settings → Mail sends its test email in the site's language and
     *             layout through Kestello\Cms\Api\Endpoints\Settings\SendTestEmail::message().
     */
    public function sendTest(string $to): bool
    {
        $settings = Settings::getInstance();
        $siteName = $settings->get('site_name', 'Kestello');

        return $this->send(
            $to,
            "Test Email from {$siteName}",
            "<h1>It works!</h1><p>This is a test email from {$siteName}.</p><p>Your mail configuration is working correctly.</p>",
        );
    }

    /**
     * A Transport bound in the container (by a plugin), if any.
     */
    private static function boundTransport(): ?Transport
    {
        try {
            $container = \Kestello\Framework\Core\Application::getInstance()->container();
        } catch (\RuntimeException) {
            return null;
        }

        return $container->has(Transport::class) ? $container->make(Transport::class) : null;
    }

    private static function storageDir(string $sub): string
    {
        try {
            return \Kestello\Framework\Core\Application::getInstance()->paths()->storage($sub);
        } catch (\RuntimeException) {
            return sys_get_temp_dir();
        }
    }
}
