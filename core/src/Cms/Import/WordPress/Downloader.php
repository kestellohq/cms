<?php

declare(strict_types=1);

namespace Kestello\Cms\Import\WordPress;

use Kestello\Cms\Import\ImportException;
use Kestello\Framework\Http\Client\HttpBlocked;
use Kestello\Framework\Http\Client\HttpClient;
use Kestello\Framework\Http\Client\HttpTooLarge;
use Throwable;

/**
 * Fetches the old site's files into a temporary file: from a copy of its
 * wp-content/uploads folder on this server when one was given (the old site
 * may already be gone), otherwise over the web through the guarded HTTP
 * client (public addresses only, size and time limits).
 *
 * @internal
 */
final class Downloader
{
    public function __construct(
        private readonly HttpClient $http,
        private readonly string $tmpDir,
        private readonly int $maxBytes,
        private readonly ?string $uploadsDir = null,
    ) {}

    /**
     * A temporary file holding the first of $urls that can be fetched.
     *
     * @param list<string> $urls the same file, best address first
     * @throws ImportException with what went wrong, in words
     */
    public function fetch(array $urls): string
    {
        $urls = array_values(array_unique(array_filter($urls, static fn(string $u) => $u !== '')));
        if ($urls === []) {
            throw new ImportException('The export does not say where this file is.');
        }
        foreach ($urls as $url) {
            $local = $this->local($url);
            if ($local !== null) {
                return $this->copy($local);
            }
        }
        $problem = '';
        foreach ($urls as $url) {
            try {
                return $this->download($url);
            } catch (ImportException $e) {
                $problem = $problem === '' ? $e->getMessage() : $problem;   // the best address's reason
            }
        }

        throw new ImportException($problem);
    }

    /** The file in the given uploads folder for an address of the old uploads folder. */
    private function local(string $url): ?string
    {
        if ($this->uploadsDir === null) {
            return null;
        }
        $path = rawurldecode((string) parse_url($url, PHP_URL_PATH));
        $at = strpos($path, '/wp-content/uploads/');
        if ($at === false) {
            return null;
        }
        $relative = substr($path, $at + strlen('/wp-content/uploads/'));
        if ($relative === '' || str_contains($relative, '..') || str_contains($relative, "\0")) {
            return null;
        }
        $file = rtrim($this->uploadsDir, '/') . '/' . $relative;
        $real = realpath($file);
        $root = realpath($this->uploadsDir);

        return $real !== false && $root !== false && str_starts_with($real, $root . DIRECTORY_SEPARATOR) && is_file($real) ? $real : null;
    }

    private function copy(string $source): string
    {
        if ((int) filesize($source) > $this->maxBytes) {
            throw new ImportException(sprintf('The file is larger than %d MB, the most this site takes.', intdiv($this->maxBytes, 1_048_576)));
        }
        $tmp = $this->tmp();
        if (!@copy($source, $tmp)) {
            @unlink($tmp);
            throw new ImportException('The file could not be copied from the uploads folder.');
        }

        return $tmp;
    }

    private function download(string $url): string
    {
        if (preg_match('#^https?://#i', $url) !== 1) {
            throw new ImportException('The file has no web address to download it from.');
        }
        $tmp = $this->tmp();
        $sink = fopen($tmp, 'wb');
        if ($sink === false) {
            throw new ImportException('Could not write a temporary file.');
        }
        try {
            $response = $this->http->get($url, ['timeout' => 120, 'connect_timeout' => 10, 'max_redirects' => 3, 'sink' => $sink, 'max_bytes' => $this->maxBytes, 'headers' => ['Accept' => '*/*']]);
        } catch (HttpBlocked) {
            fclose($sink);
            @unlink($tmp);
            throw new ImportException('This server is not allowed to fetch that address (only public web addresses are).');
        } catch (HttpTooLarge) {
            fclose($sink);
            @unlink($tmp);
            throw new ImportException(sprintf('The file is larger than %d MB, the most this site takes.', intdiv($this->maxBytes, 1_048_576)));
        } catch (Throwable $e) {
            fclose($sink);
            @unlink($tmp);
            throw new ImportException('The old site did not answer: ' . $e->getMessage());
        }
        fclose($sink);
        clearstatcache(true, $tmp);
        if ($response->status !== 200) {
            @unlink($tmp);
            throw new ImportException($response->status === 404 ? 'The old site no longer has this file (404).' : sprintf('The old site answered %d instead of the file.', $response->status));
        }
        if ((int) filesize($tmp) > $this->maxBytes) {
            @unlink($tmp);
            throw new ImportException(sprintf('The file is larger than %d MB, the most this site takes.', intdiv($this->maxBytes, 1_048_576)));
        }
        if ((int) filesize($tmp) === 0) {
            @unlink($tmp);
            throw new ImportException('The old site sent an empty file.');
        }

        return $tmp;
    }

    private function tmp(): string
    {
        if (!is_dir($this->tmpDir) && !@mkdir($this->tmpDir, 0700, true) && !is_dir($this->tmpDir)) {
            throw new ImportException('Could not create a folder for downloads.');
        }

        return rtrim($this->tmpDir, '/') . '/' . bin2hex(random_bytes(8)) . '.part';
    }
}
