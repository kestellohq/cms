<?php

declare(strict_types=1);

/*
 * Docker's HEALTHCHECK for the PHP container: asks PHP-FPM for /up over
 * FastCGI, the way nginx would, and exits 0 when the site answers 200.
 * The container has no HTTP server of its own, and no FastCGI client
 * (cgi-fcgi) is installed, so this is one, in a few lines.
 *
 *   php healthcheck.php [host:port] [/path/to/public/index.php]
 */

/** One FastCGI record: version 1, the type, request id 1, the content, no padding. */
function kst_fcgi_record(int $type, string $content): string
{
    return pack('CCnnCx', 1, $type, 1, strlen($content), 0) . $content;
}

/** A name-value pair, lengths in one byte below 128, else in four. */
function kst_fcgi_pair(string $name, string $value): string
{
    $length = static fn(int $n): string => $n < 128 ? chr($n) : pack('N', $n | 0x80000000);

    return $length(strlen($name)) . $length(strlen($value)) . $name . $value;
}

/**
 * A whole GET request: BEGIN_REQUEST (responder role), PARAMS, an empty
 * PARAMS to end them, and an empty STDIN.
 *
 * @param array<string, string> $params
 */
function kst_fcgi_request(array $params): string
{
    $pairs = '';
    foreach ($params as $name => $value) {
        $pairs .= kst_fcgi_pair($name, $value);
    }

    return kst_fcgi_record(1, pack('nCx5', 1, 0))
        . ($pairs === '' ? '' : kst_fcgi_record(4, $pairs))
        . kst_fcgi_record(4, '')
        . kst_fcgi_record(5, '');
}

/**
 * The HTTP status in a FastCGI response: the Status header PHP sends in
 * STDOUT, or 200 when it sends none. 0 when the response never ended.
 */
function kst_fcgi_status(string $response): int
{
    $stdout = '';
    $ended = false;
    for ($at = 0; $at + 8 <= strlen($response);) {
        $header = unpack('Cversion/Ctype/nid/nlength/Cpadding', substr($response, $at, 8));
        if ($header === false) {
            break;
        }
        $content = substr($response, $at + 8, $header['length']);
        $at += 8 + $header['length'] + $header['padding'];
        if ($header['type'] === 6) {
            $stdout .= $content;
        } elseif ($header['type'] === 3) {
            $ended = true;
            break;
        }
    }
    if (!$ended) {
        return 0;
    }
    $headers = strstr($stdout, "\r\n\r\n", true) ?: $stdout;

    return preg_match('/^Status:\s*(\d{3})/mi', $headers, $m) === 1 ? (int) $m[1] : 200;
}

if (realpath((string) ($_SERVER['argv'][0] ?? '')) === __FILE__) {
    $address = $_SERVER['argv'][1] ?? '127.0.0.1:9000';
    $script = $_SERVER['argv'][2] ?? '/var/www/html/public/index.php';
    $socket = @stream_socket_client("tcp://{$address}", $errno, $error, 3);
    if ($socket === false) {
        fwrite(STDERR, "PHP-FPM does not answer on {$address}: {$error}\n");
        exit(1);
    }
    stream_set_timeout($socket, 10);
    fwrite($socket, kst_fcgi_request([
        'GATEWAY_INTERFACE' => 'FastCGI/1.0',
        'REQUEST_METHOD' => 'GET',
        'REQUEST_URI' => '/up',
        'SCRIPT_NAME' => '/index.php',
        'SCRIPT_FILENAME' => $script,
        'DOCUMENT_ROOT' => dirname($script),
        'SERVER_NAME' => 'localhost',
        'SERVER_PORT' => '80',
        'SERVER_PROTOCOL' => 'HTTP/1.1',
        'REMOTE_ADDR' => '127.0.0.1',
        'HTTP_HOST' => 'localhost',
    ]));
    $response = stream_get_contents($socket);
    fclose($socket);
    $status = kst_fcgi_status((string) $response);
    if ($status !== 200) {
        fwrite(STDERR, $status === 0 ? "PHP-FPM did not finish the request.\n" : "/up answered {$status}.\n");
        exit(1);
    }
    exit(0);
}
