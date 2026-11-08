<?php

declare(strict_types=1);

namespace Kestello\Cms\View;

/**
 * Optional server-side rendering through the Node renderer (core/ssr/server.mjs).
 *
 * Enabled by app.ssr.url (e.g. "http://ssr:13714"). Any failure (server down,
 * timeout, render error) returns null and the page is rendered client-side,
 * so SSR can never take the site down.
 */
final class SsrGateway
{
    public function __construct(
        private readonly string $url,
        private readonly float $timeout = 1.5,
    ) {}

    /**
     * The renderer's /render address, or null when app.ssr.url is not an
     * http(s) address: file_get_contents would read files or other streams
     * (file://, php://, phar://) just as readily.
     */
    public static function endpoint(string $url): ?string
    {
        $parts = parse_url(trim($url));
        if (!is_array($parts) || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) || ($parts['host'] ?? '') === '') {
            return null;
        }

        return rtrim(trim($url), '/') . '/render';
    }

    /**
     * The messages the browser loads before hydrating (BrowserCatalogue), so a
     * render draws the same text: none when the site's language needs none.
     *
     * @param array<string, mixed> $page
     * @return array<string, mixed>
     */
    public static function messages(array $page): array
    {
        if (($page['props']['i18n']['translations'] ?? null) === null) {
            return [];
        }

        return \Kestello\Cms\I18n\BrowserCatalogue::messages(\Kestello\Support\Translator::locale(), site: true);
    }

    /**
     * What the Node renderer is sent for a page: the page, the SSR build of
     * every component it may draw (the page, its layouts and every block:
     * without the blocks, Node drew an empty page), the runtime and the
     * messages. Null when core or the page has no SSR build.
     *
     * @param array<string, mixed> $page
     * @return array{page: array<string, mixed>, modules: array<string, string>, runtime: string, messages: array<string, mixed>}|null
     */
    public static function payload(array $page, PageRegistry $registry): ?array
    {
        $entry = $registry->get((string) $page['component']);
        $runtime = $registry->runtime();

        if ($entry === null || $entry['ssr'] === null || $runtime === null || $runtime['ssr'] === null) {
            return null;
        }
        $modules = [(string) $page['component'] => $entry['ssr']];
        foreach ($registry->siteSsr() as $name => $ssr) {
            $modules[$name] ??= $ssr['path'];
        }

        return ['page' => $page, 'modules' => $modules, 'runtime' => $runtime['ssr'], 'messages' => self::messages($page)];
    }

    /**
     * @param array<string, mixed> $page
     * @return array{head: string, body: string, title: string}|null
     */
    public function render(array $page, PageRegistry $registry): ?array
    {
        $endpoint = self::endpoint($this->url);
        $payload = $endpoint === null ? null : self::payload($page, $registry);
        if ($endpoint === null || $payload === null) {
            return null;
        }
        $body = json_encode(['messages' => (object) $payload['messages']] + $payload, JSON_UNESCAPED_SLASHES);
        $context = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\n",
            'content' => $body,
            'timeout' => $this->timeout,
            'ignore_errors' => true,
            'follow_location' => 0,     // the page data goes to the renderer, nowhere it points to
            'max_redirects' => 0,
        ]]);

        $response = @file_get_contents($endpoint, false, $context);
        $headers = http_get_last_response_headers() ?? [];
        $status = isset($headers[0]) && preg_match('#\s(\d{3})\s#', (string) $headers[0], $m) === 1 ? (int) $m[1] : 0;

        if ($response === false || $status !== 200) {
            error_log('[kestello] SSR unavailable (' . ($status ?: 'no response') . '), rendering client-side');
            // the SSR server is down: a page cached now would miss its server HTML for the whole cache lifetime
            \Kestello\Cms\Cache\PageTags::until(time() + 60);
            return null;
        }

        $data = json_decode($response, true);
        if (!is_array($data) || !is_string($data['body'] ?? null)) {
            // the SSR server is down: a page cached now would miss its server HTML for the whole cache lifetime
            \Kestello\Cms\Cache\PageTags::until(time() + 60);
            return null;
        }

        return [
            'head' => is_string($data['head'] ?? null) ? $data['head'] : '',
            'body' => $data['body'],
            'title' => is_string($data['title'] ?? null) ? $data['title'] : '',
        ];
    }
}
