<?php

declare(strict_types=1);

namespace Kestello\Cms\Package\Registry;

use Closure;
use Kestello\Framework\Http\Client\HttpClient;
use Throwable;

/**
 * The site's copy of the registry (registry-format.md §5.1): keys.json and the index, fetched and
 * checked, and the files the index pins, fetched when needed and kept by hash.
 *
 *   $client->refresh()            fetch keys.json and the index; keeps the last good copy on failure
 *   $client->index()              the checked index, or null (status() says why)
 *   $client->package('events')    the package file, from the index's entry
 *   $client->organisation('acme') the organisation file, with its certified keys
 *   $client->advisories()
 *   $client->keys()               keys.json: index keys and revocations
 *
 * Requests carry no site address, no version and no id (User-Agent "Kestello"): every site gets
 * the same files, so the registry can't tell sites apart. Everything read from the cache is checked
 * again, so an index that expires while the registry can't be reached stops being used.
 *
 * State (in the site's settings): the highest keys.json and index serials accepted, so a rolled
 * back copy is refused; when the last refresh ran; and its error, if any.
 *
 * @internal
 */
final class RegistryClient
{
    private const string USER_AGENT = 'Kestello';

    private ?RegistryKeys $keys = null;
    private ?RegistryIndex $index = null;

    /**
     * @param array<string, string> $rootKeys key id => raw public key (RootKeys::fromConfig)
     * @param Closure(): array<string, mixed> $load the stored state
     * @param Closure(array<string, mixed>): void $save
     * @param Closure(): int $clock
     */
    public function __construct(
        private readonly HttpClient $http,
        private readonly string $baseUrl,
        private readonly array $rootKeys,
        private readonly string $cacheDir,
        private readonly Closure $load,
        private readonly Closure $save,
        private readonly Closure $clock,
    ) {}

    /**
     * Fetch keys.json and the index and keep them when they pass every check. On any failure the
     * previous copy stays in use (until it expires) and the reason is kept for Site Health.
     *
     * @return array{checked_at: int, keys_serial: ?int, index_serial: ?int, expires_at: ?int, packages: int, error: ?string}
     */
    public function refresh(): array
    {
        $state = ($this->load)();
        $now = ($this->clock)();
        $state['checked_at'] = $now;
        try {
            if (Fields::https($this->baseUrl) === null || !str_ends_with($this->baseUrl, '/')) {
                throw new RegistryException('registry.url must be an https address ending in "/".');
            }
            if ($this->rootKeys === []) {
                throw new RegistryException('Kestello has no registry root keys (registry.root_keys).');
            }
            [$keysBody, $keysTag] = $this->fetchLatest('keys.json', RegistryKeys::MAX_BYTES, $state['keys_etag'] ?? null);
            $keys = RegistryKeys::verify($keysBody, $this->rootKeys, $now, self::int($state['keys_serial'] ?? null));
            [$indexBody, $indexTag] = $this->fetchLatest('index.json', RegistryIndex::MAX_BYTES, $state['index_etag'] ?? null);
            $index = RegistryIndex::verify($indexBody, $keys, $this->rootKeys, $now, self::int($state['index_serial'] ?? null));

            $this->store('keys.json', $keysBody);
            $this->store('index.json', $indexBody);
            $this->keys = $keys;
            $this->index = $index;
            $state = ['checked_at' => $now, 'keys_serial' => $keys->serial, 'index_serial' => $index->serial, 'keys_etag' => $keysTag, 'index_etag' => $indexTag, 'error' => null];
        } catch (Throwable $e) {
            $state['error'] = $e instanceof RegistryException ? $e->getMessage() : 'The registry could not be reached: ' . $e->getMessage();
            // a 304 relies on the copy we hold; after a failure, ask for the whole files next time
            unset($state['keys_etag'], $state['index_etag']);
        }
        ($this->save)($state);

        return $this->status();
    }

    /**
     * Where the site's copy stands, for Site Health and the admin.
     *
     * @return array{checked_at: ?int, keys_serial: ?int, index_serial: ?int, expires_at: ?int, packages: int, error: ?string}
     */
    public function status(): array
    {
        $state = ($this->load)();
        $index = null;
        $error = is_string($state['error'] ?? null) ? $state['error'] : null;
        try {
            $index = $this->index(throw: true);
        } catch (RegistryException $e) {
            $error ??= $e->getMessage();
        }

        return [
            'checked_at' => self::int($state['checked_at'] ?? null),
            'keys_serial' => self::int($state['keys_serial'] ?? null),
            'index_serial' => self::int($state['index_serial'] ?? null),
            'expires_at' => $index?->expiresAt,
            'packages' => $index === null ? 0 : count($index->packages),
            'error' => $error,
        ];
    }

    /** @throws RegistryException */
    public function keys(): RegistryKeys
    {
        if ($this->keys === null) {
            $state = ($this->load)();
            $this->keys = RegistryKeys::verify($this->cached('keys.json'), $this->rootKeys, ($this->clock)(), self::int($state['keys_serial'] ?? null));
        }

        return $this->keys;
    }

    /**
     * The checked index from the site's copy, or null when there is none or it can't be used.
     *
     * @throws RegistryException only when $throw
     */
    public function index(bool $throw = false): ?RegistryIndex
    {
        try {
            if ($this->index === null || ($this->clock)() > $this->index->expiresAt + Fields::CLOCK_TOLERANCE) {
                $state = ($this->load)();
                $this->index = RegistryIndex::verify($this->cached('index.json'), $this->keys(), $this->rootKeys, ($this->clock)(), self::int($state['index_serial'] ?? null));
            }

            return $this->index;
        } catch (RegistryException $e) {
            $this->index = null;
            if ($throw) {
                throw $e;
            }

            return null;
        }
    }

    /**
     * A package in full, or null when the registry doesn't list it.
     *
     * @return array<string, mixed>|null PackageFile::parse()
     * @throws RegistryException when the registry can't be used or the file can't be fetched or read
     */
    public function package(string $slug): ?array
    {
        $entry = $this->requireIndex()->packages[$slug] ?? null;

        return $entry === null ? null : PackageFile::parse($this->pinned($entry['file']), $entry);
    }

    /** @throws RegistryException */
    public function organisation(string $id): ?OrganisationFile
    {
        $entry = $this->requireIndex()->organisations[$id] ?? null;

        return $entry === null ? null : OrganisationFile::parse($this->pinned($entry['file']), $entry, $this->rootKeys);
    }

    /** @throws RegistryException */
    public function advisories(): Advisories
    {
        $index = $this->requireIndex();

        return Advisories::parse($this->pinned($index->advisories), $index->advisories);
    }

    /** The full address of a file in the registry (a download, an image). */
    public function url(string $path): string
    {
        return Fields::path($path) !== null ? $this->baseUrl . $path : throw new RegistryException("Not a registry path: {$path}.");
    }

    /** @throws RegistryException */
    private function requireIndex(): RegistryIndex
    {
        return $this->index(throw: true) ?? throw new RegistryException('The registry has not been fetched yet.');
    }

    /**
     * A file the index pins: from the cache when we have those exact bytes, else fetched.
     *
     * @throws RegistryException
     */
    private function pinned(FileRef $ref): string
    {
        $cache = 'files/' . $ref->sha256 . '.json';
        $file = $this->cacheDir . '/' . $cache;
        if (is_file($file)) {
            $bytes = (string) file_get_contents($file);
            if (strlen($bytes) === $ref->size && hash_equals($ref->sha256, hash('sha256', $bytes))) {
                return $bytes;
            }
        }
        $bytes = $ref->check($this->get($ref->path, $ref->size)->body);
        $this->store($cache, $bytes);

        return $bytes;
    }

    /**
     * keys.json or the index, with If-None-Match: a 304 means the copy we hold is still current.
     *
     * @return array{0: string, 1: ?string} the body and its ETag
     * @throws RegistryException
     */
    private function fetchLatest(string $path, int $maxBytes, mixed $etag): array
    {
        $held = is_file($this->cacheDir . '/' . $path) ? (string) file_get_contents($this->cacheDir . '/' . $path) : null;
        $headers = is_string($etag) && $held !== null ? ['If-None-Match' => $etag] : [];
        $response = $this->get($path, $maxBytes, $headers, allow304: true);
        if ($response->status === 304 && $held !== null) {
            return [$held, is_string($etag) ? $etag : null];
        }

        return [$response->body, $response->header('ETag')];
    }

    /**
     * @param array<string, string> $headers
     * @throws RegistryException
     */
    private function get(string $path, int $maxBytes, array $headers = [], bool $allow304 = false): \Kestello\Framework\Http\Client\HttpResponse
    {
        try {
            $response = $this->http->get($this->url($path), [
                'timeout' => 30,
                'max_redirects' => 3,
                'max_bytes' => $maxBytes,
                'headers' => ['User-Agent' => self::USER_AGENT, 'Accept' => 'application/json'] + $headers,
            ]);
        } catch (RegistryException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new RegistryException("The registry could not be reached ({$path}): {$e->getMessage()}");
        }
        if ($response->status !== 200 && !($allow304 && $response->status === 304)) {
            throw new RegistryException("The registry answered HTTP {$response->status} for {$path}.");
        }

        return $response;
    }

    private function cached(string $path): string
    {
        $file = $this->cacheDir . '/' . $path;

        return is_file($file) ? (string) file_get_contents($file) : throw new RegistryException('The registry has not been fetched yet.');
    }

    /** Write to the cache through a temporary file, so a reader never sees half a file. */
    private function store(string $path, string $bytes): void
    {
        $file = $this->cacheDir . '/' . $path;
        if (!is_dir(dirname($file)) && !mkdir(dirname($file), 0o755, true) && !is_dir(dirname($file))) {
            throw new RegistryException('Could not create ' . dirname($file) . '.');
        }
        $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (file_put_contents($tmp, $bytes) === false || !rename($tmp, $file)) {
            @unlink($tmp);
            throw new RegistryException("Could not write {$file}.");
        }
    }

    private static function int(mixed $v): ?int
    {
        return is_int($v) ? $v : null;
    }
}
