<?php

declare(strict_types=1);

namespace Kestello\Framework\Storage\S3;

/**
 * AWS Signature Version 4 (header and presigned-URL forms), as used by S3
 * and every S3-compatible store. Paths must already be URI-encoded (S3 is
 * not double-encoded).
 *
 * @api
 */
final readonly class SigV4
{
    public const string UNSIGNED = 'UNSIGNED-PAYLOAD';
    public const string EMPTY_SHA256 = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';

    public function __construct(
        private string $accessKey,
        private string $secretKey,
        private string $region,
        private string $service = 's3',
        private ?string $sessionToken = null,
    ) {}

    /**
     * Headers to send, including Authorization.
     *
     * @param array<string, string> $headers
     * @return array<string, string>
     */
    public function sign(string $method, string $url, array $headers, string $payloadHash, int $time): array
    {
        $amzDate = gmdate('Ymd\THis\Z', $time);
        $headers['Host'] = self::host($url);
        $headers['x-amz-date'] = $amzDate;
        $headers['x-amz-content-sha256'] = $payloadHash;
        if ($this->sessionToken !== null && $this->sessionToken !== '') {
            $headers['x-amz-security-token'] = $this->sessionToken;
        }

        [$canonicalHeaders, $signed] = self::canonicalHeaders($headers);
        $canonical = implode("\n", [strtoupper($method), self::path($url), self::canonicalQuery($url), $canonicalHeaders, $signed, $payloadHash]);
        $scope = gmdate('Ymd', $time) . "/{$this->region}/{$this->service}/aws4_request";
        $signature = $this->signature($canonical, $amzDate, $scope, $time);

        $headers['Authorization'] = "AWS4-HMAC-SHA256 Credential={$this->accessKey}/{$scope}, SignedHeaders={$signed}, Signature={$signature}";
        unset($headers['Host']); // the HTTP client sets it from the URL

        return $headers;
    }

    /**
     * A presigned URL valid for $expires seconds (max 7 days).
     *
     * @param array<string, string> $signedHeaders headers the requester must send unchanged (e.g. content-type)
     */
    public function presign(string $method, string $url, int $expires, int $time, array $signedHeaders = []): string
    {
        $amzDate = gmdate('Ymd\THis\Z', $time);
        $scope = gmdate('Ymd', $time) . "/{$this->region}/{$this->service}/aws4_request";
        $headers = ['Host' => self::host($url)] + $signedHeaders;
        [$canonicalHeaders, $signed] = self::canonicalHeaders($headers);

        $params = [
            'X-Amz-Algorithm' => 'AWS4-HMAC-SHA256',
            'X-Amz-Credential' => "{$this->accessKey}/{$scope}",
            'X-Amz-Date' => $amzDate,
            'X-Amz-Expires' => (string) max(1, min(604800, $expires)),
            'X-Amz-SignedHeaders' => $signed,
        ];
        if ($this->sessionToken !== null && $this->sessionToken !== '') {
            $params['X-Amz-Security-Token'] = $this->sessionToken;
        }
        $query = (string) parse_url($url, PHP_URL_QUERY);
        $url = strtok($url, '?') . '?' . ($query !== '' ? $query . '&' : '') . self::encodeQuery($params);

        $canonical = implode("\n", [strtoupper($method), self::path($url), self::canonicalQuery($url), $canonicalHeaders, $signed, self::UNSIGNED]);

        return $url . '&X-Amz-Signature=' . $this->signature($canonical, $amzDate, $scope, $time);
    }

    /**
     * Form fields for a presigned POST (browser upload with a policy).
     *
     * @param list<mixed>           $conditions policy conditions besides the signing ones
     * @param array<string, string> $fields     fields the form sends (key, Content-Type...)
     * @return array<string, string> every field, ending with policy and signature
     */
    public function postFields(array $conditions, array $fields, int $expires, int $time): array
    {
        $amzDate = gmdate('Ymd\THis\Z', $time);
        $scope = gmdate('Ymd', $time) . "/{$this->region}/{$this->service}/aws4_request";
        $fields += ['x-amz-algorithm' => 'AWS4-HMAC-SHA256', 'x-amz-credential' => "{$this->accessKey}/{$scope}", 'x-amz-date' => $amzDate];
        if ($this->sessionToken !== null && $this->sessionToken !== '') {
            $fields['x-amz-security-token'] = $this->sessionToken;
        }
        foreach ($fields as $name => $value) {
            $conditions[] = [$name => $value];
        }
        $policy = base64_encode((string) json_encode([
            'expiration' => gmdate('Y-m-d\TH:i:s\Z', $time + max(1, min(604800, $expires))),
            'conditions' => $conditions,
        ], JSON_UNESCAPED_SLASHES));

        return $fields + ['policy' => $policy, 'x-amz-signature' => hash_hmac('sha256', $policy, $this->signingKey($time))];
    }

    private function signingKey(int $time): string
    {
        $key = hash_hmac('sha256', gmdate('Ymd', $time), 'AWS4' . $this->secretKey, true);
        $key = hash_hmac('sha256', $this->region, $key, true);
        $key = hash_hmac('sha256', $this->service, $key, true);

        return hash_hmac('sha256', 'aws4_request', $key, true);
    }

    private function signature(string $canonical, string $amzDate, string $scope, int $time): string
    {
        $stringToSign = "AWS4-HMAC-SHA256\n{$amzDate}\n{$scope}\n" . hash('sha256', $canonical);

        return hash_hmac('sha256', $stringToSign, $this->signingKey($time));
    }

    /**
     * @param array<string, string> $headers
     * @return array{0: string, 1: string} canonical header block, signed header list
     */
    private static function canonicalHeaders(array $headers): array
    {
        $out = [];
        foreach ($headers as $name => $value) {
            $out[strtolower(trim((string) $name))] = (string) preg_replace('/\s+/', ' ', trim((string) $value));
        }
        ksort($out, SORT_STRING);
        $block = '';
        foreach ($out as $name => $value) {
            $block .= "{$name}:{$value}\n";
        }

        return [$block, implode(';', array_keys($out))];
    }

    private static function canonicalQuery(string $url): string
    {
        $query = (string) parse_url($url, PHP_URL_QUERY);
        if ($query === '') {
            return '';
        }
        $pairs = [];
        foreach (explode('&', $query) as $pair) {
            [$k, $v] = array_pad(explode('=', $pair, 2), 2, '');
            $pairs[] = [rawurlencode(rawurldecode($k)), rawurlencode(rawurldecode($v))];
        }
        usort($pairs, static fn(array $a, array $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        return implode('&', array_map(static fn(array $p) => $p[0] . '=' . $p[1], $pairs));
    }

    /** @param array<string, string> $params */
    private static function encodeQuery(array $params): string
    {
        return implode('&', array_map(static fn($k, $v) => rawurlencode($k) . '=' . rawurlencode($v), array_keys($params), $params));
    }

    private static function path(string $url): string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);

        return $path === '' ? '/' : $path;
    }

    private static function host(string $url): string
    {
        $p = parse_url($url);
        $default = ($p['scheme'] ?? 'https') === 'https' ? 443 : 80;

        return strtolower((string) $p['host']) . (isset($p['port']) && (int) $p['port'] !== $default ? ':' . $p['port'] : '');
    }
}
