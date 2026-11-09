<?php

declare(strict_types=1);

namespace Kestello\Tests\Framework;

use Kestello\Framework\Testing\TestCase;

/**
 * Response::send() over real HTTP. PHP rewrites the status of a response when some headers
 * are sent after it (WWW-Authenticate makes it 401, Location makes it 302), so these run
 * PHP's built-in server and read the status line a client gets.
 */
final class ResponseSendTest extends TestCase
{
    /** @var resource|null */
    private static $server = null;
    private static int $port = 0;

    /** Start the server once, on a free port, for every test in this class. */
    private static function serve(): void
    {
        if (self::$server !== null) {
            return;
        }
        $probe = stream_socket_server('tcp://127.0.0.1:0');
        self::$port = (int) parse_url('tcp://' . stream_socket_get_name($probe, false), PHP_URL_PORT);
        fclose($probe);
        $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        self::$server = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . self::$port, __DIR__ . '/fixtures/send-response.php'], [1 => ['file', $null, 'w'], 2 => ['file', $null, 'w']], $pipes) ?: null;
        register_shutdown_function(static function (): void {
            if (is_resource(self::$server)) {
                proc_terminate(self::$server);
                proc_close(self::$server);
            }
        });
        for ($i = 0; $i < 100; $i++) {
            $socket = @fsockopen('127.0.0.1', self::$port, $errno, $errstr, 0.1);
            if ($socket !== false) {
                fclose($socket);
                return;
            }
            usleep(50_000);
        }
    }

    private function statusFor(int $status, string $header = ''): string
    {
        self::serve();
        $url = 'http://127.0.0.1:' . self::$port . '/?' . http_build_query(['status' => $status, 'header' => $header]);
        $context = stream_context_create(['http' => ['ignore_errors' => true, 'follow_location' => 0, 'timeout' => 5]]);
        file_get_contents($url, false, $context);

        return (string) explode(' ', (string) ($http_response_header[0] ?? ''), 3)[1];
    }

    public function testAMissingScopeIsForbiddenNotUnauthenticated(): void
    {
        $this->assertSame('403', $this->statusFor(403, 'WWW-Authenticate: Bearer error="insufficient_scope", scope="notices:read"'));
    }

    public function testNoCredentialsIsStillUnauthenticated(): void
    {
        $this->assertSame('401', $this->statusFor(401, 'WWW-Authenticate: Bearer'));
    }

    public function testACreatedResponseKeepsItsStatusWithALocation(): void
    {
        $this->assertSame('201', $this->statusFor(201, 'Location: /api/v1/notices/notices/1'));
    }

    public function testAnErrorWithALocationIsNotTurnedIntoARedirect(): void
    {
        $this->assertSame('409', $this->statusFor(409, 'Location: /elsewhere'));
    }
}
