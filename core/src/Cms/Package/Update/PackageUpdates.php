<?php

declare(strict_types=1);

namespace Kestello\Cms\Package\Update;

use Closure;
use Kestello\Framework\Http\Client\HttpClient;
use Kestello\Framework\Http\Client\HttpResponse;
use RuntimeException;
use Throwable;

/**
 * New versions of installed plugins and themes, from where each one's manifest says ("updates"):
 * a GitHub repository's releases, or the vendor's feed (PackageFeed), with a license key when the
 * package needs one (PackageLicenses).
 *
 *   check()            asks every package's source (or only those due), remembers each answer
 *   results()          those answers, for packages still at the version they were checked at
 *   download($owner)   asks the source again, downloads the offered zip, checks its SHA-256;
 *                      the zip then goes through the same review and update as an uploaded one
 *
 * Nothing here installs anything, and nothing remembered is trusted for a download: the source is
 * asked again, and the zip must still pass the signature, the publisher's key (KeyPins) and the
 * code check before the update pipeline (Updater) takes it.
 *
 * @internal
 */
final class PackageUpdates
{
    private const int MAX_ZIP_BYTES = 256 << 20;
    private const int FEED_TIMEOUT = 10;

    /**
     * @param Closure(): list<array{owner: string, type: string, slug: string, name: string, version: string, updates: array<string, mixed>|null}> $packages the installed packages
     * @param array{check_every?: mixed, prereleases?: mixed} $config app.updates.*
     * @param Closure(): array<string, array<string, mixed>> $load  the remembered answers, by owner
     * @param Closure(array<string, array<string, mixed>>): void $save
     * @param Closure(): int|null $now
     */
    public function __construct(
        private readonly HttpClient $http,
        private readonly PackageLicenses $licenses,
        private readonly Closure $packages,
        private readonly string $core,
        private readonly string $php,
        private readonly string $site,
        private readonly array $config,
        private readonly string $tmpDir,
        private readonly Closure $load,
        private readonly Closure $save,
        private readonly ?Closure $now = null,
    ) {}

    /**
     * Ask the sources: every package with one, only $only, or only those whose last answer is
     * older than app.updates.check_every hours. A failing source never stops the others.
     *
     * @return array<string, array<string, mixed>> the answers, by owner (as results())
     */
    public function check(bool $dueOnly = false, ?string $only = null): array
    {
        $hours = (int) ($this->config['check_every'] ?? 12);
        $saved = ($this->load)();
        foreach (($this->packages)() as $package) {
            if ($package['updates'] === null) {
                unset($saved[$package['owner']]);
                continue;
            }
            $last = $saved[$package['owner']] ?? null;
            if ($only !== null && $package['owner'] !== $only) {
                continue;
            }
            if ($dueOnly && ($hours <= 0 || (is_array($last) && ($last['version'] ?? null) === $package['version'] && (int) ($last['checked_at'] ?? 0) > $this->time() - $hours * 3600))) {
                continue;
            }
            $saved[$package['owner']] = $this->checkOne($package, is_array($last) ? $last : null);
        }
        ($this->save)($saved);

        return $this->results();
    }

    /**
     * The last answers for the packages installed now. An answer about another version (the
     * package was updated since) offers nothing until the next check.
     *
     * @return array<string, array{checked_at: int, source: string, offer: ?array<string, mixed>, needs_core: ?array<string, mixed>, error: ?string, renew_url: ?string}>
     */
    public function results(): array
    {
        $saved = ($this->load)();
        $out = [];
        foreach (($this->packages)() as $package) {
            $r = $saved[$package['owner']] ?? null;
            if ($package['updates'] === null || !is_array($r)) {
                continue;
            }
            $current = ($r['version'] ?? null) === $package['version'];
            $out[$package['owner']] = [
                'checked_at' => (int) ($r['checked_at'] ?? 0),
                'source' => (string) ($r['source'] ?? ''),
                'offer' => $current && is_array($r['offer'] ?? null) ? $r['offer'] : null,
                'needs_core' => $current && is_array($r['needs_core'] ?? null) ? $r['needs_core'] : null,
                'error' => is_string($r['error'] ?? null) ? $r['error'] : null,
                'renew_url' => is_string($r['renew_url'] ?? null) ? $r['renew_url'] : null,
            ];
        }

        return $out;
    }

    /** How many installed packages have an update to offer (the menu badge). */
    public function waiting(): int
    {
        return count(array_filter($this->results(), static fn(array $r): bool => $r['offer'] !== null));
    }

    /**
     * Download the version the source offers now, check its SHA-256, and return where it is
     * (in the temporary folder; the caller removes it).
     *
     * @return array{path: string, version: string}
     */
    public function download(string $owner): array
    {
        $package = $this->package($owner);
        $choice = $this->choose($package, $this->releases($package));
        $release = $choice['offer'] ?? throw new RuntimeException("There is no update for {$package['name']} {$package['version']} right now.");

        $expected = $release['sha256'];
        if ($expected === null) {
            // GitHub: the checksum is a file next to the zip
            $sums = $this->http->get((string) $release['sha256_url'], ['timeout' => 20, 'max_bytes' => 4096, 'max_redirects' => 5, 'headers' => $this->headers('application/octet-stream')]);
            if ($sums->status !== 200) {
                throw new RuntimeException("The checksum of {$package['name']} {$release['version']} could not be downloaded (HTTP {$sums->status}).");
            }
            $expected = ReleaseFeed::checksum($sums->body) ?? throw new RuntimeException("The checksum file of {$package['name']} {$release['version']} holds no SHA-256.");
        }

        if (!is_dir($this->tmpDir) && !mkdir($this->tmpDir, 0700, true) && !is_dir($this->tmpDir)) {
            throw new RuntimeException("Could not create {$this->tmpDir}.");
        }
        $path = rtrim($this->tmpDir, '/') . '/' . $package['slug'] . '-' . bin2hex(random_bytes(6)) . '.zip';
        $sink = fopen($path, 'xb') ?: throw new RuntimeException("Could not write {$path}.");
        try {
            $response = $this->http->get($release['zip'], ['timeout' => 300, 'max_redirects' => 5, 'sink' => $sink] + $this->auth($package, $release['zip'], 'application/octet-stream'));
        } catch (Throwable $e) {
            fclose($sink);
            @unlink($path);
            throw new RuntimeException("{$package['name']} {$release['version']} could not be downloaded: {$e->getMessage()}", 0, $e);
        }
        fclose($sink);
        if ($response->status !== 200 || (int) filesize($path) > self::MAX_ZIP_BYTES) {
            @unlink($path);
            throw new RuntimeException($response->status !== 200 ? "{$package['name']} {$release['version']} could not be downloaded (HTTP {$response->status})." : "The download of {$package['name']} {$release['version']} is larger than any package could be.");
        }
        if (!hash_equals($expected, (string) hash_file('sha256', $path))) {
            @unlink($path);
            throw new RuntimeException("The download of {$package['name']} {$release['version']} does not match its published checksum, so it was thrown away. Try again later.");
        }

        return ['path' => $path, 'version' => $release['version']];
    }

    /**
     * @param array{owner: string, type: string, slug: string, name: string, version: string, updates: array<string, mixed>|null} $package
     * @param array<string, mixed>|null $last
     * @return array<string, mixed>
     */
    private function checkOne(array $package, ?array $last): array
    {
        $result = ['checked_at' => $this->time(), 'version' => $package['version'], 'source' => isset($package['updates']['github']) ? 'github' : 'url', 'offer' => null, 'needs_core' => null, 'error' => null, 'renew_url' => null, 'links' => $last['links'] ?? []];
        try {
            $releases = $this->releases($package, $result);
            $choice = $this->choose($package, $releases);
            $result['offer'] = $choice['offer'] !== null ? self::summary($choice['offer']) : null;
            $result['needs_core'] = $choice['needs_core'] !== null ? self::summary($choice['needs_core']) : null;
        } catch (LicenseRefused $e) {
            $result['error'] = $e->getMessage();
            $result['renew_url'] = $e->renewUrl;
        } catch (Throwable $e) {
            $result['error'] = $e->getMessage();
        }

        return $result;
    }

    /**
     * The source's releases, newest first, in one shape.
     *
     * @param array{owner: string, type: string, slug: string, name: string, version: string, updates: array<string, mixed>|null} $package
     * @param array<string, mixed>|null $result  receives the feed's "links"
     * @return list<array{version: string, notes: string, published_at: string, prerelease: bool, requires_core: ?string, requires_php: ?string, zip: string, sha256: ?string, sha256_url?: string}>
     */
    private function releases(array $package, ?array &$result = null): array
    {
        $updates = $package['updates'] ?? throw new RuntimeException("{$package['name']} does not say where its updates come from.");
        if (isset($updates['github'])) {
            $repo = (string) $updates['github'];
            $response = $this->http->get(UpdateChecker::API . "/repos/{$repo}/releases", [
                'query' => ['per_page' => 30],
                'timeout' => self::FEED_TIMEOUT,
                'max_redirects' => 2,
                'headers' => $this->headers('application/vnd.github+json') + ['X-GitHub-Api-Version' => '2022-11-28'],
            ]);
            if ($response->status === 403 || $response->status === 429) {
                throw new RuntimeException('GitHub is limiting requests from this server for now; the next check will try again.');
            }
            if ($response->status !== 200) {
                throw new RuntimeException("GitHub answered HTTP {$response->status} for the releases of {$repo}.");
            }

            return array_map(static fn(array $r): array => [
                'version' => $r['version'], 'notes' => $r['notes'], 'published_at' => $r['published_at'], 'prerelease' => $r['prerelease'],
                'requires_core' => null, 'requires_php' => null, 'zip' => $r['zip'], 'sha256' => null, 'sha256_url' => $r['sha256'],
            ], ReleaseFeed::parse(json_decode($response->body, true), $repo, $package['slug']));
        }

        $url = (string) $updates['url'];
        if (($updates['license'] ?? false) === true && $this->licenses->get($package['owner']) === null) {
            throw new RuntimeException("{$package['name']} needs a license key to look for updates.");
        }
        try {
            $response = $this->http->get($url, ['timeout' => self::FEED_TIMEOUT, 'max_bytes' => PackageFeed::MAX_BYTES, 'max_redirects' => 3] + $this->auth($package, $url, 'application/json'));
        } catch (Throwable $e) {
            throw new RuntimeException("The update feed of {$package['name']} could not be reached: {$e->getMessage()}", 0, $e);
        }
        if ($response->status === 401 || $response->status === 403 || $response->status === 402) {
            throw $this->refused($package, $url, $response, $result['links'] ?? []);
        }
        if ($response->status !== 200) {
            throw new RuntimeException("The update feed of {$package['name']} answered HTTP {$response->status}.");
        }
        $feed = PackageFeed::parse($response->body, $package['type'], $package['slug']);
        if ($result !== null) {
            $result['links'] = $feed['links'];
        }

        return $feed['releases'];
    }

    /**
     * @param array{version: string} $package
     * @param list<array{version: string, prerelease: bool, requires_core: ?string, requires_php: ?string}> $releases
     * @return array{offer: ?array<string, mixed>, needs_core: ?array<string, mixed>}
     */
    private function choose(array $package, array $releases): array
    {
        return PackageFeed::choose($releases, $package['version'], $this->core, $this->php, (bool) ($this->config['prereleases'] ?? false));
    }

    /**
     * Headers for a request to $url: the license key goes only to the package's own feed host.
     *
     * @param array{owner: string, updates: array<string, mixed>|null} $package
     * @return array<string, mixed>
     */
    private function auth(array $package, string $url, string $accept): array
    {
        $options = ['headers' => $this->headers($accept)];
        $feed = $package['updates']['url'] ?? null;
        $key = ($package['updates']['license'] ?? false) === true && is_string($feed) ? $this->licenses->get($package['owner']) : null;
        if ($key !== null && self::host($url) === self::host($feed)) {
            $options['bearer'] = $key;
            $options['headers']['X-Kestello-Site'] = $this->site;
        }

        return $options;
    }

    /**
     * The vendor said no to the key: its message as text, and its renew link when it points at
     * the feed's own host or one the feed listed.
     *
     * @param array{name: string, updates: array<string, mixed>|null} $package
     * @param list<string> $links
     */
    private function refused(array $package, string $url, HttpResponse $response, array $links): LicenseRefused
    {
        $body = json_decode($response->body, true);
        $said = is_array($body) && is_string($body['error'] ?? null) ? trim(mb_substr(strip_tags($body['error']), 0, 300)) : '';
        $renew = is_array($body) && is_string($body['renew_url'] ?? null) ? $body['renew_url'] : null;
        if ($renew !== null && (!PackageFeed::isHttps($renew) || !in_array(self::host($renew), [self::host($url), ...$links], true))) {
            $renew = null;
        }
        $message = ($package['updates']['license'] ?? false) === true ? "The license key for {$package['name']} was refused" : "The update feed of {$package['name']} refused this site";

        return new LicenseRefused($message . ($said !== '' ? ": {$said}" : '.'), $renew);
    }

    /** @return array{owner: string, type: string, slug: string, name: string, version: string, updates: array<string, mixed>|null} */
    private function package(string $owner): array
    {
        foreach (($this->packages)() as $package) {
            if ($package['owner'] === $owner) {
                return $package;
            }
        }

        throw new RuntimeException("{$owner} is not installed.");
    }

    /**
     * What is kept and shown of a release: never its download address (it may be short-lived).
     *
     * @param array<string, mixed> $release
     * @return array{version: string, notes: string, published_at: string, requires_core: ?string}
     */
    private static function summary(array $release): array
    {
        return ['version' => (string) $release['version'], 'notes' => (string) $release['notes'], 'published_at' => (string) $release['published_at'], 'requires_core' => $release['requires_core'] ?? null];
    }

    private static function host(string $url): string
    {
        return strtolower((string) parse_url($url, PHP_URL_HOST));
    }

    /** @return array<string, string> */
    private function headers(string $accept): array
    {
        return ['Accept' => $accept, 'User-Agent' => 'Kestello/' . $this->core . ' (package updates; +https://github.com/kestellohq/cms)'];
    }

    private function time(): int
    {
        return $this->now !== null ? ($this->now)() : time();
    }
}
