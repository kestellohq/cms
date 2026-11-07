<?php

declare(strict_types=1);

namespace Kestello\Framework\Storage\S3;

use Kestello\Contracts\Storage\Disk;
use Kestello\Contracts\Storage\FileInfo;
use Kestello\Contracts\Storage\FileNotFound;
use Kestello\Contracts\Storage\StorageException;
use Kestello\Framework\Core\ExecutionContext;
use Kestello\Framework\Http\Client\HttpClient;
use Kestello\Framework\Http\Client\HttpException;
use Kestello\Framework\Http\Client\HttpResponse;
use Kestello\Framework\Storage\StoragePath;
use Kestello\Support\Mime;

/**
 * S3 and S3-compatible object storage (AWS, Cloudflare R2, Backblaze B2,
 * DigitalOcean Spaces, Wasabi, Hetzner, Scaleway, MinIO, Garage, GCS
 * interop…) over the built-in SigV4 signer and HTTP client.
 *
 * Config:
 *   bucket, key, secret             required
 *   region                          'us-east-1' (R2: 'auto')
 *   endpoint                        null = AWS (https://s3.<region>.amazonaws.com)
 *   path_style                      true for MinIO and most self-hosted stores
 *   url                             public base or CDN URL for url()
 *   visibility                      default for new objects: private | public
 *   acl                             false: send x-amz-acl (only for buckets with ACLs enabled)
 *   token                           session token (temporary credentials)
 *
 * Requests run as core (an admin-configured endpoint, not a plugin's
 * network call); a custom endpoint may be on a private network.
 */
final class S3Disk implements Disk, \Kestello\Contracts\Storage\DirectUploads
{
    private readonly SigV4 $signer;
    private readonly string $endpoint;
    private readonly bool $pathStyle;

    /** @param array<string, mixed> $config */
    public function __construct(private readonly array $config, private readonly HttpClient $http = new HttpClient())
    {
        foreach (['bucket', 'key', 'secret'] as $required) {
            if (($config[$required] ?? '') === '') {
                throw new StorageException("S3 disk: \"{$required}\" is required.");
            }
        }
        $region = (string) ($config['region'] ?? 'us-east-1');
        $this->endpoint = rtrim((string) ($config['endpoint'] ?? "https://s3.{$region}.amazonaws.com"), '/');
        $this->pathStyle = (bool) ($config['path_style'] ?? false);
        $this->signer = new SigV4((string) $config['key'], (string) $config['secret'], $region, 's3', isset($config['token']) ? (string) $config['token'] : null);
    }

    public function put(string $path, string $contents, array $options = []): void
    {
        $key = StoragePath::file($path);
        $this->request('PUT', $key, headers: $this->writeHeaders($key, $options), body: $contents)->ok()
            || throw new StorageException("Could not write \"{$path}\".");
    }

    public function putStream(string $path, mixed $stream, array $options = []): void
    {
        $key = StoragePath::file($path);
        $stat = fstat($stream);
        $size = is_array($stat) && ($stat['size'] ?? 0) > 0 && stream_get_meta_data($stream)['seekable'] ? $stat['size'] - (int) ftell($stream) : null;
        if ($size === null) {
            // Unknown length (non-seekable stream): buffer to a temp file so the length can be sent.
            $tmp = fopen('php://temp/maxmemory:8388608', 'w+');
            stream_copy_to_stream($stream, $tmp);
            $size = (int) ftell($tmp);
            rewind($tmp);
            $stream = $tmp;
        }
        $headers = $this->writeHeaders($key, $options) + ['Content-Length' => (string) $size];
        $this->request('PUT', $key, headers: $headers, stream: $stream, length: $size)->ok()
            || throw new StorageException("Could not write \"{$path}\".");
    }

    public function get(string $path): string
    {
        return $this->request('GET', StoragePath::file($path), missingIsError: true)->body;
    }

    public function readStream(string $path): mixed
    {
        $sink = fopen('php://temp/maxmemory:8388608', 'w+');
        $this->request('GET', StoragePath::file($path), sink: $sink, missingIsError: true);
        rewind($sink);

        return $sink;
    }

    public function exists(string $path): bool
    {
        return $this->head(StoragePath::file($path)) !== null;
    }

    public function delete(string $path): bool
    {
        $key = StoragePath::file($path);
        $existed = $this->exists($path);
        $this->request('DELETE', $key);

        return $existed;
    }

    public function deleteDirectory(string $path): int
    {
        $prefix = StoragePath::directory($path);
        if ($prefix === '') {
            throw new StorageException('Refusing to delete the whole bucket.');
        }
        $count = 0;
        $batch = [];
        foreach ($this->list($prefix) as $file) {
            $batch[] = $file->path;
            if (count($batch) === 1000) {
                $count += $this->deleteMany($batch);
                $batch = [];
            }
        }

        return $count + ($batch !== [] ? $this->deleteMany($batch) : 0);
    }

    public function copy(string $from, string $to): void
    {
        $source = '/' . $this->config['bucket'] . '/' . StoragePath::encode(StoragePath::file($from));
        $headers = ['x-amz-copy-source' => $source];
        if ($this->usesAcl()) {
            $headers['x-amz-acl'] = $this->visibility($from) === 'public' ? 'public-read' : 'private';
        }
        $res = $this->request('PUT', StoragePath::file($to), headers: $headers, missingIsError: true);
        if (str_contains($res->body, '<Error>')) { // S3 can report copy failures inside a 200
            throw new StorageException('Copy failed: ' . self::errorMessage($res));
        }
    }

    public function move(string $from, string $to): void
    {
        $this->copy($from, $to);
        $this->delete($from);
    }

    public function size(string $path): int
    {
        return (int) $this->metadata($path)->header('content-length');
    }

    public function mimeType(string $path): ?string
    {
        return $this->head(StoragePath::file($path))?->header('content-type');
    }

    public function lastModified(string $path): int
    {
        return (int) strtotime((string) $this->metadata($path)->header('last-modified'));
    }

    public function checksum(string $path): string
    {
        return trim((string) $this->metadata($path)->header('etag'), '"');
    }

    public function list(string $prefix = '', bool $recursive = true): iterable
    {
        $prefix = StoragePath::directory($prefix);
        $token = null;
        do {
            $query = ['list-type' => '2', 'max-keys' => '1000', 'prefix' => $prefix];
            if (!$recursive) {
                $query['delimiter'] = '/';
            }
            if ($token !== null) {
                $query['continuation-token'] = $token;
            }
            $xml = self::xml($this->request('GET', null, query: $query)->body);
            foreach ($xml->Contents ?? [] as $item) {
                yield new FileInfo((string) $item->Key, (int) $item->Size, (int) strtotime((string) $item->LastModified));
            }
            $token = (string) $xml->IsTruncated === 'true' ? (string) $xml->NextContinuationToken : null;
        } while ($token !== null && $token !== '');
    }

    public function visibility(string $path): string
    {
        if (!$this->usesAcl()) {
            $this->metadata($path); // FileNotFound for missing files
            return $this->defaultVisibility();
        }
        $res = $this->request('GET', StoragePath::file($path), query: ['acl' => ''], missingIsError: true);

        return str_contains($res->body, 'http://acs.amazonaws.com/groups/global/AllUsers') ? 'public' : 'private';
    }

    public function setVisibility(string $path, string $visibility): void
    {
        if (!$this->usesAcl()) {
            throw new StorageException('This bucket manages visibility with its bucket policy (set "acl" to true to use object ACLs).');
        }
        $this->request('PUT', StoragePath::file($path), query: ['acl' => ''], headers: ['x-amz-acl' => $visibility === 'public' ? 'public-read' : 'private'], missingIsError: true);
    }

    public function url(string $path): string
    {
        $key = StoragePath::encode(StoragePath::file($path));
        if (($this->config['url'] ?? '') !== '') {
            return rtrim((string) $this->config['url'], '/') . '/' . $key;
        }
        if ($this->defaultVisibility() !== 'public') {
            throw new StorageException('This disk is private: set its "url" (public bucket or CDN) or use temporaryUrl().');
        }

        return $this->objectUrl($key);
    }

    public function temporaryUrl(string $path, int $seconds, array $options = []): string
    {
        $url = $this->objectUrl(StoragePath::encode(StoragePath::file($path)));
        $query = [];
        if (isset($options['download_name'])) {
            $name = str_replace(['"', '\\', "\r", "\n"], '', $options['download_name']);
            $query[] = 'response-content-disposition=' . rawurlencode('attachment; filename="' . $name . '"');
        }
        if (isset($options['content_type'])) {
            $query[] = 'response-content-type=' . rawurlencode($options['content_type']);
        }
        if ($query !== []) {
            $url .= '?' . implode('&', $query);
        }

        return $this->signer->presign('GET', $url, $seconds, time());
    }

    public function temporaryUploadUrl(string $path, int $seconds, array $constraints = []): string
    {
        $url = $this->objectUrl(StoragePath::encode(StoragePath::file($path)));
        $signed = isset($constraints['content_type']) ? ['content-type' => $constraints['content_type']] : [];

        return $this->signer->presign('PUT', $url, $seconds, time(), $signed);
    }

    public function supportsDirectUploads(): bool
    {
        return (bool) ($this->config['direct_uploads'] ?? false);
    }

    public function presignedPost(string $path, int $seconds, int $size, string $contentType): array
    {
        $key = StoragePath::file($path);
        $fields = ['key' => $key, 'Content-Type' => $contentType];
        if ($this->usesAcl()) {
            $fields['acl'] = $this->defaultVisibility() === 'public' ? 'public-read' : 'private';
        }
        $conditions = [['bucket' => (string) $this->config['bucket']], ['content-length-range', $size, $size]];

        return [
            'url' => rtrim($this->objectUrl(''), '/') . '/',
            'fields' => $this->signer->postFields($conditions, $fields, $seconds, time()),
        ];
    }

    public function readRange(string $path, int $offset, int $length): string
    {
        $res = $this->request('GET', StoragePath::file($path), headers: ['Range' => 'bytes=' . max(0, $offset) . '-' . (max(0, $offset) + max(1, $length) - 1)], missingIsError: true);

        return substr($res->body, 0, $length);
    }

    /** Health check: write, read, delete a probe object. */
    public function probe(): void
    {
        $key = '.kestello-probe-' . bin2hex(random_bytes(6));
        $this->put($key, 'probe');
        $ok = $this->get($key) === 'probe';
        $this->delete($key);
        if (!$ok) {
            throw new StorageException('Probe object did not read back.');
        }
    }

    // ── Internals ───────────────────────────────────────────────────

    /**
     * @param array<string, string> $query
     * @param array<string, string> $headers
     * @param resource|null         $stream
     * @param resource|null         $sink
     */
    private function request(
        string $method,
        ?string $key,
        array $query = [],
        array $headers = [],
        ?string $body = null,
        mixed $stream = null,
        ?int $length = null,
        mixed $sink = null,
        bool $missingIsError = false,
    ): HttpResponse {
        $url = $this->objectUrl($key === null ? '' : StoragePath::encode($key));
        if ($query !== []) {
            ksort($query);
            $url .= '?' . implode('&', array_map(static fn($k, $v) => rawurlencode($k) . ($v === '' && $k === 'acl' ? '' : '=' . rawurlencode($v)), array_keys($query), $query));
            if (isset($query['acl'])) {
                $url = str_replace('acl&', 'acl=&', $url);
            }
        }

        $payload = $stream !== null ? SigV4::UNSIGNED : ($body !== null ? hash('sha256', $body) : SigV4::EMPTY_SHA256);
        $signed = $this->signer->sign($method, $url, $headers, $payload, time());
        $options = ['headers' => $signed, 'timeout' => (float) ($this->config['timeout'] ?? 60), 'max_redirects' => 0, 'max_bytes' => 268_435_456];
        if (($this->config['endpoint'] ?? null) !== null) {
            $options['allow_private'] = true;
        }
        if ($body !== null) {
            $options['body'] = $body;
        }
        if ($stream !== null) {
            $options['body_stream'] = $stream;
            $options['body_length'] = $length;
        }
        if ($sink !== null) {
            $options['sink'] = $sink;
        }

        try {
            $res = ExecutionContext::run('core', fn() => $this->http->request($method, $url, $options));
        } catch (HttpException $e) {
            throw new StorageException('Storage request failed: ' . $e->getMessage(), 0, $e);
        }

        if ($res->status === 404 && $key !== null) {
            if ($missingIsError) {
                throw FileNotFound::at($key);
            }
            return $res;
        }
        if ($res->status >= 300) {
            if ($sink !== null) {
                rewind($sink);
                $res = new HttpResponse($res->status, $res->headers, (string) stream_get_contents($sink, 4096), $res->url);
            }
            throw new StorageException("S3 {$method} failed (HTTP {$res->status}): " . self::errorMessage($res));
        }

        return $res;
    }

    private function head(string $key): ?HttpResponse
    {
        $res = $this->request('HEAD', $key);

        return $res->status === 404 ? null : $res;
    }

    private function metadata(string $path): HttpResponse
    {
        return $this->head(StoragePath::file($path)) ?? throw FileNotFound::at($path);
    }

    /** @param list<string> $keys */
    private function deleteMany(array $keys): int
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?><Delete><Quiet>true</Quiet>';
        foreach ($keys as $key) {
            $xml .= '<Object><Key>' . htmlspecialchars($key, ENT_XML1) . '</Key></Object>';
        }
        $xml .= '</Delete>';
        $res = $this->request('POST', null, query: ['delete' => ''], headers: [
            'Content-Type' => 'application/xml',
            'Content-MD5' => base64_encode(md5($xml, true)),
        ], body: $xml);
        if (str_contains($res->body, '<Error>')) {
            throw new StorageException('Some objects could not be deleted: ' . self::errorMessage($res));
        }

        return count($keys);
    }

    /**
     * @param array<string, string> $options
     * @return array<string, string>
     */
    private function writeHeaders(string $key, array $options): array
    {
        $headers = ['Content-Type' => $options['content_type'] ?? Mime::fromExtension($key)];
        if (isset($options['cache_control'])) {
            $headers['Cache-Control'] = $options['cache_control'];
        }
        if (isset($options['content_disposition'])) {
            $headers['Content-Disposition'] = $options['content_disposition'];
        }
        if ($this->usesAcl()) {
            $headers['x-amz-acl'] = ($options['visibility'] ?? $this->defaultVisibility()) === 'public' ? 'public-read' : 'private';
        }
        if (($this->config['sse'] ?? '') !== '') {
            $headers['x-amz-server-side-encryption'] = (string) $this->config['sse'];
        }

        return $headers;
    }

    private function objectUrl(string $encodedKey): string
    {
        $bucket = (string) $this->config['bucket'];
        if ($this->pathStyle) {
            return $this->endpoint . '/' . rawurlencode($bucket) . '/' . $encodedKey;
        }
        $p = parse_url($this->endpoint);

        return $p['scheme'] . '://' . $bucket . '.' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '') . '/' . $encodedKey;
    }

    private function usesAcl(): bool
    {
        return (bool) ($this->config['acl'] ?? false);
    }

    private function defaultVisibility(): string
    {
        return ($this->config['visibility'] ?? 'private') === 'public' ? 'public' : 'private';
    }

    private static function xml(string $body): \SimpleXMLElement
    {
        $xml = @simplexml_load_string($body, \SimpleXMLElement::class, LIBXML_NONET);

        return $xml === false ? throw new StorageException('Unexpected response from the storage service.') : $xml;
    }

    private static function errorMessage(HttpResponse $res): string
    {
        $xml = @simplexml_load_string($res->body, \SimpleXMLElement::class, LIBXML_NONET);
        if ($xml === false) {
            return 'no details';
        }
        $error = $xml->getName() === 'Error' ? $xml : ($xml->Error ?? $xml);

        return trim((string) $error->Code . ': ' . (string) $error->Message, ': ');
    }
}
