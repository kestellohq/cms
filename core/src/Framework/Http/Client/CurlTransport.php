<?php

declare(strict_types=1);

namespace Kestello\Framework\Http\Client;

/** The default transport (ext-curl). TLS is always verified. */
final class CurlTransport implements Transport
{
    public function send(PendingRequest $request): HttpResponse
    {
        if (!function_exists('curl_init')) {
            throw new HttpException('The curl extension is required for outgoing HTTP requests.');
        }

        $headers = [];
        $body = '';
        $tooLarge = false;
        $ch = curl_init($request->url);

        $written = 0;
        $options = [
            CURLOPT_CUSTOMREQUEST => $request->method,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_TIMEOUT_MS => (int) ($request->timeout * 1000),
            CURLOPT_CONNECTTIMEOUT_MS => (int) ($request->connectTimeout * 1000),
            CURLOPT_NOPROXY => '*', // a proxy would bypass the vetted IP
            CURLOPT_ENCODING => '',
            CURLOPT_HTTPHEADER => array_map(static fn($k, $v) => "{$k}: {$v}", array_keys($request->headers), $request->headers),
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$headers): int {
                if (str_contains($line, ':')) {
                    [$name, $value] = explode(':', $line, 2);
                    $headers[strtolower(trim($name))][] = trim($value);
                } elseif (preg_match('#^HTTP/\S+\s+\d{3}#', $line) === 1) {
                    $headers = []; // a new response (after 100 Continue)
                }
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use (&$body, &$tooLarge, &$written, $request): int {
                if ($request->sink !== null) {
                    $written += strlen($chunk);
                    if ($written > $request->maxBytes) {
                        $tooLarge = true;
                        return 0; // abort
                    }
                    return (int) fwrite($request->sink, $chunk);
                }
                if (strlen($body) + strlen($chunk) > $request->maxBytes) {
                    $tooLarge = true;
                    return 0; // abort
                }
                $body .= $chunk;
                return strlen($chunk);
            },
        ];
        if ($request->ip !== null) {
            $ip = str_contains($request->ip, ':') ? '[' . $request->ip . ']' : $request->ip;
            $options[CURLOPT_RESOLVE] = ["{$request->host}:{$request->port}:{$ip}"];
        }
        if ($request->method === 'HEAD') {
            $options[CURLOPT_NOBODY] = true;
        }
        if ($request->bodyStream !== null) {
            $options[CURLOPT_UPLOAD] = true;
            $options[CURLOPT_INFILE] = $request->bodyStream;
            if ($request->bodyLength !== null) {
                $options[CURLOPT_INFILESIZE] = $request->bodyLength;
            }
            $options[CURLOPT_CUSTOMREQUEST] = $request->method; // UPLOAD defaults to PUT
        } elseif ($request->body !== null) {
            $options[CURLOPT_POSTFIELDS] = $request->body;
        }
        curl_setopt_array($ch, $options);

        $ok = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        unset($ch);                       // closes the handle (curl_close() has done nothing since PHP 8.0)

        if ($tooLarge) {
            throw new HttpTooLarge("Response from {$request->host} is larger than {$request->maxBytes} bytes.");
        }
        if ($ok === false) {
            throw new HttpException("Request to {$request->host} failed: {$error}");
        }

        return new HttpResponse($status, $headers, $body, $request->url);
    }
}
