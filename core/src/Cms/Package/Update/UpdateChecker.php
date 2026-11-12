<?php

declare(strict_types=1);

namespace Kestello\Cms\Package\Update;

use Closure;
use Kestello\Cms\Package\PackageSignature;
use Kestello\Framework\Http\Client\HttpClient;
use RuntimeException;
use Throwable;

/**
 * Finds new Kestello versions on GitHub and downloads them for review.
 *
 *   check()             asks the releases of release.repository, remembers the answer
 *   last()              that answer, without asking again
 *   download($version)  the release zip in storage/tmp, its SHA-256 checked against the
 *                       published checksum; CoreUpdater then checks the signature
 *
 * Nothing here installs anything: the zip goes through the same review and update as an
 * uploaded one (signature, requirements, backup, health check, switch back).
 *
 * @internal
 */
final class UpdateChecker
{
    public const string API = 'https://api.github.com';
    private const int MAX_ZIP_BYTES = 256 << 20;

    /**
     * @param array{repository?: mixed, prereleases?: mixed, check_every?: mixed} $config release.* config
     * @param Closure(): ?array<string, mixed> $load  the remembered answer
     * @param Closure(array<string, mixed>): void $save
     * @param Closure(): int $now  unix time
     */
    public function __construct(
        private readonly HttpClient $http,
        private readonly array $config,
        private readonly string $running,
        private readonly string $tmpDir,
        private readonly Closure $load,
        private readonly Closure $save,
        private readonly ?Closure $now = null,
    ) {}

    /** "owner/name" (release.repository), checked so it can only name a GitHub repository. */
    public function repository(): string
    {
        $repo = is_string($this->config['repository'] ?? null) ? $this->config['repository'] : '';
        if (preg_match('#^[A-Za-z0-9](?:[A-Za-z0-9-]{0,38})/[A-Za-z0-9._-]{1,100}$#', $repo) !== 1) {
            throw new RuntimeException("release.repository \"{$repo}\" is not a GitHub repository like kestellohq/cms.");
        }

        return $repo;
    }

    /** Is a scheduled check due? */
    public function due(): bool
    {
        $hours = (int) ($this->config['check_every'] ?? 12);
        if ($hours <= 0) {
            return false;
        }
        $last = ($this->load)();

        return !is_array($last) || (int) ($last['checked_at'] ?? 0) <= $this->time() - $hours * 3600;
    }

    /**
     * Ask GitHub now. A failure is remembered too (with the last good answer kept), so the
     * admin can say why there is no news.
     *
     * @return array{checked_at: int, running: string, latest: ?array<string, mixed>, error: ?string}
     */
    public function check(): array
    {
        $previous = ($this->load)();
        try {
            $latest = ReleaseFeed::newest($this->offerable($this->releases()), $this->running, true);
            $result = ['checked_at' => $this->time(), 'running' => $this->running, 'latest' => $latest, 'error' => null];
        } catch (Throwable $e) {
            $kept = is_array($previous['latest'] ?? null) && ($previous['running'] ?? null) === $this->running ? $previous['latest'] : null;
            $result = ['checked_at' => $this->time(), 'running' => $this->running, 'latest' => $kept, 'error' => 'Kestello could not check for updates: ' . $e->getMessage()];
        }
        ($this->save)($result);

        return $result;
    }

    /**
     * The last answer, if it still applies to the running version.
     *
     * @return array{checked_at: int, running: string, latest: ?array<string, mixed>, error: ?string}|null
     */
    public function last(): ?array
    {
        $last = ($this->load)();
        if (!is_array($last) || !isset($last['checked_at'])) {
            return null;
        }
        if (($last['running'] ?? null) !== $this->running) {
            // updated since: an older answer may offer the version already running
            $last['latest'] = null;
        }

        return [
            'checked_at' => (int) $last['checked_at'],
            'running' => $this->running,
            'latest' => is_array($last['latest'] ?? null) ? $last['latest'] : null,
            'error' => is_string($last['error'] ?? null) ? $last['error'] : null,
        ];
    }

    /**
     * Download a release, asking GitHub again for where it is (nothing remembered is trusted),
     * and check its SHA-256. Returns the zip's path in the temporary folder; the caller removes it.
     */
    public function download(string $version): string
    {
        $release = null;
        foreach ($this->releases() as $candidate) {
            if ($candidate['version'] === $version) {
                $release = $candidate;
                break;
            }
        }
        if ($release === null) {
            throw new RuntimeException("Kestello {$version} is not among the releases of {$this->repository()}.");
        }

        $sums = $this->http->get($release['sha256'], ['timeout' => 20, 'max_bytes' => 4096, 'max_redirects' => 5, 'headers' => $this->headers('application/octet-stream')]);
        if ($sums->status !== 200) {
            throw new RuntimeException("The checksum for Kestello {$version} could not be downloaded (HTTP {$sums->status}).");
        }
        $expected = ReleaseFeed::checksum($sums->body) ?? throw new RuntimeException("The checksum file of Kestello {$version} holds no SHA-256.");

        if (!is_dir($this->tmpDir) && !mkdir($this->tmpDir, 0700, true) && !is_dir($this->tmpDir)) {
            throw new RuntimeException("Could not create {$this->tmpDir}.");
        }
        $path = rtrim($this->tmpDir, '/') . '/kestello-core-' . bin2hex(random_bytes(6)) . '.zip';
        $sink = fopen($path, 'xb') ?: throw new RuntimeException("Could not write {$path}.");
        try {
            $response = $this->http->get($release['zip'], ['timeout' => 300, 'max_redirects' => 5, 'sink' => $sink, 'headers' => $this->headers('application/octet-stream')]);
        } catch (Throwable $e) {
            fclose($sink);
            @unlink($path);
            throw new RuntimeException("Kestello {$version} could not be downloaded: {$e->getMessage()}", 0, $e);
        }
        fclose($sink);
        if ($response->status !== 200 || (int) filesize($path) > self::MAX_ZIP_BYTES) {
            @unlink($path);
            throw new RuntimeException($response->status !== 200 ? "Kestello {$version} could not be downloaded (HTTP {$response->status})." : "The download of Kestello {$version} is larger than any release could be.");
        }
        if (!hash_equals($expected, (string) hash_file('sha256', $path))) {
            @unlink($path);
            throw new RuntimeException("The download of Kestello {$version} does not match its published checksum, so it was thrown away. Try again later.");
        }

        return $path;
    }

    /**
     * The repository's releases, newest first.
     *
     * @return list<array{version: string, name: string, notes: string, published_at: string, prerelease: bool, zip: string, sha256: string, page: string}>
     */
    public function releases(): array
    {
        $repo = $this->repository();
        $response = $this->http->get(self::API . "/repos/{$repo}/releases", [
            'query' => ['per_page' => 30],
            'timeout' => 15,
            'max_redirects' => 2,
            'headers' => $this->headers('application/vnd.github+json') + ['X-GitHub-Api-Version' => '2022-11-28'],
        ]);
        if ($response->status === 403 || $response->status === 429) {
            throw new RuntimeException('GitHub is limiting requests from this server for now; the next check will try again.');
        }
        if ($response->status !== 200) {
            throw new RuntimeException("GitHub answered HTTP {$response->status} for the releases of {$repo}.");
        }

        return ReleaseFeed::parse(json_decode($response->body, true), $repo);
    }

    /**
     * Release keys from config (release.keys), as PackageSignature::trustedKeys() returns them.
     *
     * @param mixed $keys
     * @return array<string, array{name: string, public_key: string}>
     */
    public static function releaseKeys(mixed $keys): array
    {
        return PackageSignature::trustedKeys($keys, 'release.keys');
    }

    /**
     * The releases this site may be offered: every one with release.prereleases on; otherwise
     * releases, plus, while a pre-release runs, later pre-releases of that same version. A site
     * testing 0.1.0-rc.1 gets rc.2, then 0.1.0, and is back to releases only from there.
     *
     * @param list<array{version: string, prerelease: bool}> $releases
     * @return list<array{version: string, prerelease: bool}>
     */
    private function offerable(array $releases): array
    {
        if ((bool) ($this->config['prereleases'] ?? false)) {
            return $releases;
        }
        $testing = str_contains($this->running, '-') ? explode('-', $this->running, 2)[0] : null;

        return array_values(array_filter($releases, static fn(array $r): bool => !$r['prerelease'] || ($testing !== null && explode('-', $r['version'], 2)[0] === $testing)));
    }

    /** @return array<string, string> */
    private function headers(string $accept): array
    {
        return ['Accept' => $accept, 'User-Agent' => 'Kestello/' . $this->running . ' (update check; +https://github.com/kestellohq/cms)'];
    }

    private function time(): int
    {
        return $this->now !== null ? ($this->now)() : time();
    }
}
