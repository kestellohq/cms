<?php

declare(strict_types=1);

namespace Kestello\Framework\Http\Client;

use Closure;
use Kestello\Framework\Core\ExecutionContext;
use Kestello\Framework\Http\TrustedProxies;
use Kestello\Support\Json;
use Kestello\Support\Url;

/**
 * Outgoing HTTP with SSRF protection.
 *
 *   $res = $http->get('https://api.github.com/user', ['bearer' => $token, 'headers' => ['Accept' => 'application/json']]);
 *   $res->throw()->json();
 *   $http->post($url, ['form' => ['code' => $code]]);
 *
 * Every request (and every redirect hop) is checked:
 * - http/https only, ports 80/443 only;
 * - the host resolves to public addresses only (no loopback, private,
 *   link-local, CGNAT, multicast, metadata endpoints), and the connection
 *   is pinned to the vetted address so DNS can't change between check and
 *   connect;
 * - plugins and themes may only reach hosts listed under "permissions.network"
 *   in their manifest ("api.github.com", "*.googleapis.com");
 * - redirects never downgrade https→http and drop credentials across hosts.
 *
 * Core code can pass 'allow_private' => true for admin-configured internal
 * services; plugins can't.
 *
 * @api
 */
final class HttpClient
{
    public const string USER_AGENT = 'Kestello-HttpClient/1';

    private const array BLOCKED_RANGES = [
        '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16', '172.16.0.0/12',
        '192.0.0.0/24', '192.0.2.0/24', '192.88.99.0/24', '192.168.0.0/16', '198.18.0.0/15', '198.51.100.0/24',
        '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4', '255.255.255.255/32',
        '::/128', '::1/128', '::ffff:0:0/96', '64:ff9b::/96', '100::/64', '2001::/32', '2001:db8::/32',
        '2002::/16', 'fc00::/7', 'fe80::/10', 'ff00::/8',
    ];

    /** @var Closure(string): list<string> */
    private Closure $resolver;

    /**
     * @param (Closure(string $host): list<string>)|null           $resolver  DNS lookup (tests inject one)
     * @param (Closure(string $owner): (list<string>|null))|null    $allowlist hosts a package may reach; null = unrestricted
     */
    public function __construct(
        private readonly Transport $transport = new CurlTransport(),
        ?Closure $resolver = null,
        private readonly ?Closure $allowlist = null,
    ) {
        $this->resolver = $resolver ?? self::dnsResolver(...);
    }

    /** @param array<string, mixed> $options */
    public function get(string $url, array $options = []): HttpResponse
    {
        return $this->request('GET', $url, $options);
    }
    /** @param array<string, mixed> $options */
    public function post(string $url, array $options = []): HttpResponse
    {
        return $this->request('POST', $url, $options);
    }
    /** @param array<string, mixed> $options */
    public function put(string $url, array $options = []): HttpResponse
    {
        return $this->request('PUT', $url, $options);
    }
    /** @param array<string, mixed> $options */
    public function patch(string $url, array $options = []): HttpResponse
    {
        return $this->request('PATCH', $url, $options);
    }
    /** @param array<string, mixed> $options */
    public function delete(string $url, array $options = []): HttpResponse
    {
        return $this->request('DELETE', $url, $options);
    }

    private bool $asService = false;

    /**
     * @internal ServiceClient: a request to the service address an administrator configured. It runs with
     * core's network policy (private hosts and other ports allowed, no plugin allowlist); ServiceClient has
     * already pinned the URL to that address and turned redirects off.
     *
     * @param array<string, mixed> $options
     */
    public function requestAsService(string $method, string $url, array $options): HttpResponse
    {
        $previous = $this->asService;
        $this->asService = true;
        try {
            return $this->request($method, $url, ['allow_private' => true, 'max_redirects' => 0] + $options);
        } finally {
            $this->asService = $previous;
        }
    }

    /**
     * Options: headers, query, json, form, body, bearer, basic [user, pass],
     * timeout (10 s), connect_timeout (5 s), max_redirects (3),
     * max_bytes (5 MB; not applied with a sink), allow_private (core only),
     * sink (resource: stream the response body into it),
     * body_stream + body_length (stream the request body; no redirects).
     *
     * @param array<string, mixed> $options
     * @throws HttpBlocked|HttpException
     */
    public function request(string $method, string $url, array $options = []): HttpResponse
    {
        $owner = $this->asService ? 'core' : ExecutionContext::current();
        $allowPrivate = (bool) ($options['allow_private'] ?? false);
        if ($allowPrivate && $owner !== 'core') {
            throw new HttpBlocked("{$owner} may not use allow_private.");
        }

        $method = strtoupper($method);
        if (isset($options['query']) && is_array($options['query'])) {
            $url = Url::withQuery($url, $options['query']);
        }

        $headers = ['User-Agent' => self::USER_AGENT, 'Accept' => 'application/json, */*;q=0.5'];
        foreach ((array) ($options['headers'] ?? []) as $name => $value) {
            if (preg_match('/[\r\n]/', (string) $name . (string) $value) === 1) {
                throw new HttpBlocked('Header names and values may not contain line breaks.');
            }
            $headers[(string) $name] = (string) $value;
        }
        if (isset($options['bearer'])) {
            $headers['Authorization'] = 'Bearer ' . $options['bearer'];
        }
        if (isset($options['basic'])) {
            [$user, $pass] = $options['basic'];
            $headers['Authorization'] = 'Basic ' . base64_encode($user . ':' . $pass);
        }

        $body = null;
        if (array_key_exists('json', $options)) {
            $body = Json::encode($options['json']);
            $headers['Content-Type'] = 'application/json';
        } elseif (isset($options['form'])) {
            $body = http_build_query((array) $options['form'], '', '&', PHP_QUERY_RFC1738);
            $headers['Content-Type'] = 'application/x-www-form-urlencoded';
        } elseif (isset($options['body'])) {
            $body = (string) $options['body'];
        }

        $sink = $options['sink'] ?? null;
        $bodyStream = $options['body_stream'] ?? null;
        if (($sink !== null && !is_resource($sink)) || ($bodyStream !== null && !is_resource($bodyStream))) {
            throw new \InvalidArgumentException('sink and body_stream must be stream resources.');
        }
        $redirects = $bodyStream !== null ? 0 : max(0, (int) ($options['max_redirects'] ?? 3));
        // a redirect's own body must not end up in front of the file it points to
        $sinkStart = $sink !== null ? (int) ftell($sink) : 0;
        $originalHost = null;

        for ($hop = 0; ; $hop++) {
            [$host, $port, $ip] = $this->vet($url, $owner, $allowPrivate);
            $originalHost ??= $host;
            if ($host !== $originalHost) {
                unset($headers['Authorization'], $headers['Cookie']);
            }

            $response = $this->transport->send(new PendingRequest(
                method: $method,
                url: $url,
                host: $host,
                port: $port,
                ip: $ip,
                headers: $headers,
                body: $body,
                timeout: (float) ($options['timeout'] ?? 10),
                connectTimeout: (float) ($options['connect_timeout'] ?? 5),
                maxBytes: (int) ($options['max_bytes'] ?? ($sink !== null ? PHP_INT_MAX : 5_242_880)),
                sink: $sink,
                bodyStream: $bodyStream,
                bodyLength: isset($options['body_length']) ? (int) $options['body_length'] : null,
            ));

            if (!$response->isRedirect() || $hop >= $redirects) {
                return $response;
            }
            if ($sink !== null) {
                ftruncate($sink, $sinkStart);
                fseek($sink, $sinkStart);
            }

            $next = self::resolveLocation($url, (string) $response->header('location'));
            if (str_starts_with($url, 'https:') && !str_starts_with($next, 'https:')) {
                throw new HttpBlocked("Refused a redirect from https to {$next}.");
            }
            $url = $next;
            if (in_array($response->status, [301, 302, 303], true) && $method !== 'GET' && $method !== 'HEAD') {
                $method = 'GET';
                $body = null;
                unset($headers['Content-Type']);
            }
        }
    }

    /**
     * Check a URL against the policy. Returns [host, port, pinned ip].
     *
     * @return array{0: string, 1: int, 2: ?string}
     */
    public function vet(string $url, string $owner = 'core', bool $allowPrivate = false): array
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if (!is_array($parts) || !in_array($scheme, ['http', 'https'], true) || !isset($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            throw new HttpBlocked("Refused URL \"{$url}\": only plain http(s) URLs are allowed.");
        }

        $host = strtolower(trim($parts['host'], '[]'));
        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
        if (!$allowPrivate && !in_array($port, [80, 443], true)) {
            throw new HttpBlocked("Refused port {$port} for {$host}.");
        }

        if ($owner !== 'core' && $this->allowlist !== null) {
            $allowed = ($this->allowlist)($owner);
            if ($allowed !== null && !self::hostAllowed($host, $allowed)) {
                throw new HttpBlocked("{$owner} may not contact {$host}: add it to \"permissions.network\" in the manifest.");
            }
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            $ips = [$host];
        } else {
            $ips = ($this->resolver)($host);
            if ($ips === []) {
                throw new HttpException("Could not resolve {$host}.");
            }
        }

        if (!$allowPrivate) {
            foreach ($ips as $ip) {
                if (self::isPrivate($ip)) {
                    throw new HttpBlocked("Refused {$host}: it resolves to a non-public address ({$ip}).");
                }
            }
        }

        return [$host, $port, filter_var($host, FILTER_VALIDATE_IP) !== false ? null : $ips[0]];
    }

    public static function isPrivate(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return true;
        }
        foreach (self::BLOCKED_RANGES as $range) {
            if (TrustedProxies::matches($ip, $range)) {
                return true;
            }
        }

        return false;
    }

    /** @param list<string> $patterns "example.com" or "*.example.com" (subdomains only) */
    public static function hostAllowed(string $host, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            $pattern = strtolower(trim((string) $pattern));
            if ($pattern === $host || (str_starts_with($pattern, '*.') && str_ends_with($host, substr($pattern, 1)) && strlen($host) > strlen($pattern) - 1)) {
                return true;
            }
        }

        return false;
    }

    private static function resolveLocation(string $base, string $location): string
    {
        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $location) === 1) {
            return $location;
        }
        $b = parse_url($base);
        $origin = $b['scheme'] . '://' . $b['host'] . (isset($b['port']) ? ':' . $b['port'] : '');
        if (str_starts_with($location, '//')) {
            return $b['scheme'] . ':' . $location;
        }
        if (str_starts_with($location, '/')) {
            return $origin . $location;
        }
        $dir = preg_replace('#/[^/]*$#', '/', $b['path'] ?? '/');

        return $origin . $dir . $location;
    }

    /** @return list<string> */
    private static function dnsResolver(string $host): array
    {
        $ips = gethostbynamel($host) ?: [];
        $aaaa = @dns_get_record($host, DNS_AAAA) ?: [];
        foreach ($aaaa as $record) {
            if (isset($record['ipv6'])) {
                $ips[] = $record['ipv6'];
            }
        }

        return array_values(array_unique($ips));
    }
}
