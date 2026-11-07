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
     * @param array<string, mixed> $page
     * @return array{head: string, body: string, title: string}|null
     */
    public function render(array $page, PageRegistry $registry): ?array
    {
        $entry = $registry->get((string) $page['component']);
        $runtime = $registry->runtime();

        if ($entry === null || $entry['ssr'] === null || $runtime === null || $runtime['ssr'] === null) {
            return null;
        }

        $modules = [(string) $page['component'] => $entry['ssr']];
        foreach ($registry->layouts() as $layout) {
            $ssr = $registry->get($layout)['ssr'] ?? null;
            if ($ssr !== null) {
                $modules[$layout] = $ssr;
            }
        }

        // the messages the browser will load before hydrating (BrowserCatalogue), so both draw the same text
        $locale = \Kestello\Support\Translator::locale();
        $messages = ($page['props']['i18n']['translations'] ?? null) !== null ? \Kestello\Cms\I18n\BrowserCatalogue::messages($locale, site: true) : [];
        $body = json_encode(['page' => $page, 'modules' => $modules, 'runtime' => $runtime['ssr'], 'messages' => (object) $messages], JSON_UNESCAPED_SLASHES);
        $context = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\n",
            'content' => $body,
            'timeout' => $this->timeout,
            'ignore_errors' => true,
        ]]);

        $response = @file_get_contents(rtrim($this->url, '/') . '/render', false, $context);
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
