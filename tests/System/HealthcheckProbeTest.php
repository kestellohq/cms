<?php

declare(strict_types=1);

namespace Kestello\Tests\System;

use Kestello\Framework\Testing\TestCase;

/** The Docker HEALTHCHECK's FastCGI client (docker/php/healthcheck.php). */
final class HealthcheckProbeTest extends TestCase
{
    protected function setUp(): void
    {
        require_once dirname(__DIR__, 2) . '/docker/php/healthcheck.php';
    }

    public function testTheRequestIsWhatTheFastCgiSpecDescribes(): void
    {
        $request = kst_fcgi_request(['REQUEST_URI' => '/up']);
        // BEGIN_REQUEST: version 1, type 1, id 1, 8 bytes; role 1 (responder), flags 0
        $this->assertSame("\x01\x01\x00\x01\x00\x08\x00\x00" . "\x00\x01\x00\x00\x00\x00\x00\x00", substr($request, 0, 16));
        // PARAMS: name length 11, value length 3
        $this->assertSame("\x01\x04\x00\x01\x00\x10\x00\x00" . "\x0b\x03REQUEST_URI/up", substr($request, 16, 24));
        // the empty PARAMS that ends them, then an empty STDIN
        $this->assertSame("\x01\x04\x00\x01\x00\x00\x00\x00" . "\x01\x05\x00\x01\x00\x00\x00\x00", substr($request, 40));
    }

    public function testLongValuesUseFourByteLengths(): void
    {
        $pair = kst_fcgi_pair('X', str_repeat('a', 200));
        $this->assertSame("\x01\x80\x00\x00\xc8X", substr($pair, 0, 6));
    }

    public function testItReadsTheStatusFromTheResponse(): void
    {
        $stdout = static fn(string $s): string => kst_fcgi_record(6, $s);
        $end = kst_fcgi_record(3, pack('NCx3', 0, 0));

        $this->assertSame(200, kst_fcgi_status($stdout("Content-Type: application/json\r\n\r\n{\"status\":\"ok\"}") . $end), 'no Status header means 200');
        $this->assertSame(503, kst_fcgi_status($stdout("Status: 503 Service Unavailable\r\nContent-Type: application/json\r\n\r\n{}") . $end));
        $this->assertSame(503, kst_fcgi_status($stdout('Status: 503 Service') . $stdout(" Unavailable\r\n\r\n") . $end), 'STDOUT split over records');
        $this->assertSame(200, kst_fcgi_status($stdout("X: y\r\n\r\nStatus: 500 in the body") . $end), 'only headers count');
        $this->assertSame(0, kst_fcgi_status($stdout("Status: 200 OK\r\n\r\n")), 'a response that never ended');
        $this->assertSame(0, kst_fcgi_status(''));
    }
}
