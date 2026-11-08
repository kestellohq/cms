<?php

declare(strict_types=1);

namespace Kestello\Cms\View\Prerender;

/**
 * Saved page HTML, for sites without the Node renderer.
 *
 * Every page a visitor sees without signing in has a key: the SHA-256 of
 * the exact page data the browser receives. When HTML was saved for that
 * key, the page is sent with it and Vue takes it over, so the page shows
 * before its scripts arrive and crawlers read it. When none was, the page
 * goes out as before (Vue draws it in the browser) and its data waits here
 * until an administrator's browser draws it to HTML and sends it back
 * (PrerenderController, POST /system/prerender/{key}).
 *
 * The key is the page data itself, so saved HTML never goes stale: when a
 * page, a menu or a list of posts changes, the data and so the key change,
 * and the old HTML is simply never asked for again (and pruned).
 *
 *   storage/framework/prerender/queue/<key>.json  waiting: {url, page, modules, messages}
 *   storage/framework/prerender/html/<key>.json   saved:   {url, body, title}
 *   storage/framework/prerender/refused/<key>.json refused: {url, reason}, never asked for again
 */
final class PrerenderStore
{
    /** Saved pages kept; the oldest go first. */
    public const int MAX_SAVED = 2000;

    /** Pages waiting at once; the oldest go first, so the pages visited now win. */
    public const int MAX_WAITING = 100;

    /** The largest HTML accepted for one page. */
    public const int MAX_BYTES = 1_000_000;

    public const string KEY_PATTERN = '/^[a-f0-9]{64}$/';

    public function __construct(private readonly string $dir) {}

    /** The key of a page: the SHA-256 of the page data exactly as the browser receives it. */
    public static function key(string $pageJson): string
    {
        return hash('sha256', $pageJson);
    }

    /** @return array{body: string, title: string}|null */
    public function find(string $key): ?array
    {
        $data = $this->read($this->file('html', $key));

        return is_string($data['body'] ?? null) ? ['body' => $data['body'], 'title' => (string) ($data['title'] ?? '')] : null;
    }

    /**
     * Ask for a page's HTML: it waits until an administrator's browser draws it.
     *
     * @param array{url: string, page: array<string, mixed>, modules: array<string, string>, messages: array<string, mixed>} $item
     */
    public function want(string $key, array $item): void
    {
        if (preg_match(self::KEY_PATTERN, $key) !== 1 || is_file($this->file('queue', $key)) || is_file($this->file('html', $key)) || is_file($this->file('refused', $key))) {
            return;
        }
        $this->write($this->file('queue', $key), $item);
        $this->prune('queue', self::MAX_WAITING);
    }

    /**
     * The pages waiting, newest first.
     *
     * @return list<array{key: string, url: string, page: array<string, mixed>, modules: array<string, string>, messages: array<string, mixed>}>
     */
    public function waiting(int $limit): array
    {
        $out = [];
        foreach ($this->files('queue') as $file) {
            if (count($out) >= $limit) {
                break;
            }
            $item = $this->read($file);
            if (!is_array($item['page'] ?? null) || !is_array($item['modules'] ?? null)) {
                @unlink($file);
                continue;
            }
            $out[] = [
                'key' => basename($file, '.json'),
                'url' => (string) ($item['url'] ?? '/'),
                'page' => $item['page'],
                'modules' => $item['modules'],
                'messages' => is_array($item['messages'] ?? null) ? $item['messages'] : [],
            ];
        }

        return $out;
    }

    /**
     * Save the HTML drawn for a waiting page.
     *
     * @return string the page's address
     * @throws \InvalidArgumentException when the page is not waiting or the HTML is refused
     */
    public function save(string $key, string $body, string $title): string
    {
        $queued = preg_match(self::KEY_PATTERN, $key) === 1 ? $this->read($this->file('queue', $key)) : null;
        if (!is_array($queued)) {
            throw new \InvalidArgumentException('This page is not waiting for HTML: it changed since, or was saved already.');
        }
        $problem = self::refuse($body);
        if ($problem !== null) {
            // never asked for again (until its data changes, which gives a new key): the page stays drawn in the browser
            $this->write($this->file('refused', $key), ['url' => (string) ($queued['url'] ?? '/'), 'reason' => $problem]);
            @unlink($this->file('queue', $key));
            $this->prune('refused', self::MAX_SAVED);
            throw new \InvalidArgumentException($problem);
        }
        $url = (string) ($queued['url'] ?? '/');
        $this->write($this->file('html', $key), ['url' => $url, 'body' => $body, 'title' => mb_substr($title, 0, 300)]);
        @unlink($this->file('queue', $key));
        $this->prune('html', self::MAX_SAVED);

        return $url;
    }

    /** Stop waiting for a page (its data no longer matches the site's build). */
    public function forget(string $key): void
    {
        if (preg_match(self::KEY_PATTERN, $key) === 1) {
            @unlink($this->file('queue', $key));
        }
    }

    /** @return array{saved: int, waiting: int} */
    public function counts(): array
    {
        return ['saved' => count($this->files('html')), 'waiting' => count($this->files('queue'))];
    }

    /**
     * Why HTML sent for a page is refused, or null.
     *
     * The HTML is parsed, not matched with patterns, and must hold only what
     * Vue draws for pages: allowlisted elements, no event handlers, safe
     * addresses (checked after the parser decodes entities), the video
     * players the embed block makes, forms posting to this site, and no id
     * the page shell itself uses. Anything else refuses the whole page; it is
     * then drawn in the browser as before, so a refusal never breaks a page.
     */
    public static function refuse(string $html): ?string
    {
        if ($html === '' || strlen($html) > self::MAX_BYTES) {
            return 'The HTML is empty or larger than ' . intdiv(self::MAX_BYTES, 1000) . ' kB.';
        }
        $doc = \Dom\HTMLDocument::createFromString('<!DOCTYPE html><html><head></head><body>' . $html . '</body></html>', LIBXML_NOERROR);
        $body = $doc->body;
        if ($body === null || $doc->head?->childNodes->length !== 0) {
            return 'The HTML holds elements that belong in the page head.';
        }
        foreach ($body->getElementsByTagName('*') as $el) {
            $problem = SavedHtmlRules::element($el);
            if ($problem !== null) {
                return $problem;
            }
        }

        return null;
    }

    private function file(string $kind, string $key): string
    {
        return "{$this->dir}/{$kind}/{$key}.json";
    }

    /** @return list<string> newest first */
    private function files(string $kind): array
    {
        $files = glob("{$this->dir}/{$kind}/*.json") ?: [];
        $times = [];
        foreach ($files as $file) {
            $times[$file] = (int) @filemtime($file);
        }
        arsort($times);

        return array_keys($times);
    }

    private function prune(string $kind, int $keep): void
    {
        foreach (array_slice($this->files($kind), $keep) as $file) {
            @unlink($file);
        }
    }

    /** @return array<string, mixed>|null */
    private function read(string $file): ?array
    {
        if (!is_file($file)) {
            return null;
        }
        $data = json_decode((string) @file_get_contents($file), true);

        return is_array($data) ? $data : null;
    }

    /** @param array<string, mixed> $data */
    private function write(string $file, array $data): void
    {
        $dir = dirname($file);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException("Cannot create {$dir}.");
        }
        $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (file_put_contents($tmp, json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)) === false || !rename($tmp, $file)) {
            @unlink($tmp);
            throw new \RuntimeException("Cannot write {$file}.");
        }
    }
}
