<?php

declare(strict_types=1);

namespace Kestello\Framework\Core;

use ErrorException;
use Throwable;

/**
 * Centralised error and exception handler.
 *
 * Debug mode renders a Whoops-style page with source code snippets,
 * stack trace, request details, and environment info.
 * Production mode shows a clean error page and logs the details.
 * API requests always receive JSON error responses.
 */
final class ErrorHandler
{
    public function __construct(
        private readonly bool $debug = false,
        private readonly string $logPath = '',
    ) {}

    /**
     * Register as the global error, exception, and shutdown handler.
     */
    public function register(): void
    {
        set_error_handler([$this, 'handleError']);
        set_exception_handler([$this, 'handleException']);
        register_shutdown_function([$this, 'handleShutdown']);
    }

    /**
     * Convert PHP errors into ErrorException instances.
     *
     * @throws ErrorException
     */
    public function handleError(int $level, string $message, string $file = '', int $line = 0): bool
    {
        if (!(error_reporting() & $level)) {
            return false;
        }

        throw new ErrorException($message, 0, $level, $file, $line);
    }

    /**
     * Handle an uncaught exception.
     */
    /**
     * The response for an exception a request did not handle, logged: JSON
     * for the API, the debug page with app.debug on, else the plain error
     * page. Application::run sends it through the same steps as any other
     * error response (the site's branded error page, its security headers),
     * so a page that throws is answered like one that returns a 500.
     */
    public function response(Throwable $exception, Request $request): Response
    {
        $this->logException($exception);
        $status = $this->resolveStatusCode($exception);
        if ($status < 400 || $status > 599) {
            $status = 500;
        }

        if (str_starts_with($request->path, '/api/') || str_contains($request->header('Accept'), 'application/json')) {
            return new Response(json_encode($this->formatJsonError($exception, $status), JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE), $status, [
                'Content-Type' => 'application/json; charset=UTF-8',
                'Cache-Control' => 'no-store',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        }

        return new Response($this->debug ? $this->renderDebugPage($exception) : $this->productionPage($status), $status, self::pageHeaders());
    }

    /**
     * Security headers for the error pages this class writes itself: their
     * styles are inline and they load nothing else. Pages the site renders
     * get the site's own policy instead.
     *
     * @return array<string, string>
     */
    public static function pageHeaders(): array
    {
        return [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'no-store',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; img-src data:; base-uri 'none'; form-action 'none'; frame-ancestors 'none'",
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
        ];
    }

    public function handleException(Throwable $exception): void
    {
        $this->logException($exception);

        if (PHP_SAPI === 'cli') {
            fwrite(STDERR, $this->formatForCli($exception));
            return;
        }

        $statusCode = $this->resolveStatusCode($exception);
        if (!headers_sent()) {
            http_response_code($statusCode);
        }

        // JSON response for API requests
        if ($this->isApiRequest()) {
            if (!headers_sent()) {
                header('Content-Type: application/json; charset=UTF-8');
            }
            echo json_encode($this->formatJsonError($exception, $statusCode), JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
            return;
        }

        if (!headers_sent()) {
            foreach (self::pageHeaders() as $name => $value) {
                header("{$name}: {$value}");
            }
        }
        echo $this->debug
            ? $this->renderDebugPage($exception)
            : $this->productionPage($statusCode);
    }

    /**
     * Handle fatal errors during shutdown.
     */
    public function handleShutdown(): void
    {
        $error = error_get_last();

        if ($error !== null && in_array($error['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE], true)) {
            $this->handleException(
                new ErrorException($error['message'], 0, $error['type'], $error['file'], $error['line']),
            );
        }
    }

    // ------------------------------------------------------------------
    // Logging
    // ------------------------------------------------------------------

    private function logException(Throwable $exception): void
    {
        if ($this->logPath === '') {
            return;
        }

        if (!is_dir($this->logPath)) {
            @mkdir($this->logPath, 0o755, recursive: true);
        }

        $file = $this->logPath . '/' . date('Y-m-d') . '.log';

        // Name the package the failure came from, so "a plugin broke the site"
        // is answerable from the log alone.
        $owner = null;
        for ($e = $exception; $e !== null && $owner === null; $e = $e->getPrevious()) {
            $owner = ExecutionContext::blamed($e);
        }

        $entry = sprintf(
            "[%s]%s %s: %s in %s:%d\n%s\n\n",
            date('Y-m-d H:i:s'),
            $owner !== null && $owner !== 'core' ? " [{$owner}]" : '',
            $exception::class,
            $exception->getMessage(),
            $exception->getFile(),
            $exception->getLine(),
            $exception->getTraceAsString(),
        );

        @file_put_contents($file, $entry, FILE_APPEND | LOCK_EX);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function isApiRequest(): bool
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';

        return str_starts_with($uri, '/api/') || str_contains($accept, 'application/json');
    }

    private function resolveStatusCode(Throwable $exception): int
    {
        if (method_exists($exception, 'getStatusCode')) {
            return (int) $exception->getStatusCode();
        }

        return match ($exception::class) {
            \RuntimeException::class => 500,
            \InvalidArgumentException::class => 400,
            default => 500,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function formatJsonError(Throwable $exception, int $statusCode): array
    {
        $response = [
            'error' => [
                'status' => $statusCode,
                'message' => $this->debug ? $exception->getMessage() : 'Internal Server Error',
            ],
        ];

        if ($this->debug) {
            $response['error']['exception'] = $exception::class;
            $response['error']['file'] = $exception->getFile();
            $response['error']['line'] = $exception->getLine();
            $response['error']['trace'] = array_map(
                fn(array $frame): array => [
                    'file' => $frame['file'] ?? null,
                    'line' => $frame['line'] ?? null,
                    'function' => ($frame['class'] ?? '') . ($frame['type'] ?? '') . ($frame['function'] ?? ''),
                ],
                $exception->getTrace(),
            );
        }

        return $response;
    }

    // ------------------------------------------------------------------
    // Debug page (Whoops-style)
    // ------------------------------------------------------------------

    private function renderDebugPage(Throwable $exception): string
    {
        $class = $this->esc($exception::class);
        $message = $this->esc($exception->getMessage());
        $file = $exception->getFile();
        $line = $exception->getLine();
        $escFile = $this->esc($file);

        // Source code snippet
        $sourceHtml = $this->renderSourceCode($file, $line);

        // Stack trace
        $traceHtml = $this->renderStackTrace($exception);

        // Request info
        $requestHtml = $this->renderRequestInfo();

        // Environment info
        $envHtml = $this->renderEnvironmentInfo();

        return <<<HTML
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>{$class}: {$message}</title>
            <style>
                * { margin: 0; padding: 0; box-sizing: border-box; }
                body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, monospace; background: #1a1a2e; color: #e0e0e0; font-size: 14px; }

                .error-header { background: #e74c3c; padding: 2rem 2.5rem; color: white; }
                .error-header .exception-class { font-size: 0.9rem; opacity: 0.85; font-weight: 400; margin-bottom: 0.5rem; }
                .error-header .exception-message { font-size: 1.4rem; font-weight: 600; line-height: 1.4; word-break: break-word; }
                .error-header .exception-location { font-size: 0.85rem; opacity: 0.75; margin-top: 0.75rem; font-family: monospace; }

                .content { display: flex; min-height: calc(100vh - 140px); }

                .sidebar { width: 340px; background: #16213e; border-right: 1px solid #0f3460; overflow-y: auto; flex-shrink: 0; }
                .sidebar .frame { padding: 0.75rem 1rem; border-bottom: 1px solid #0f3460; cursor: pointer; transition: background 0.15s; }
                .sidebar .frame:hover { background: #1a1a40; }
                .sidebar .frame.active { background: #0f3460; border-left: 3px solid #e74c3c; }
                .sidebar .frame-index { color: #666; font-size: 0.75rem; margin-right: 0.5rem; }
                .sidebar .frame-method { color: #7ec8e3; font-weight: 500; font-size: 0.85rem; word-break: break-all; }
                .sidebar .frame-file { color: #888; font-size: 0.75rem; margin-top: 0.2rem; font-family: monospace; }

                .main { flex: 1; overflow-y: auto; }

                .section { margin-bottom: 0; }
                .section-title { padding: 0.75rem 1.5rem; background: #16213e; color: #7ec8e3; font-weight: 600; font-size: 0.85rem; text-transform: uppercase; letter-spacing: 0.05em; border-bottom: 1px solid #0f3460; cursor: pointer; user-select: none; }
                .section-title:hover { background: #1a1a40; }

                .source-code { background: #0d1117; overflow-x: auto; }
                .source-code table { width: 100%; border-collapse: collapse; }
                .source-code .line-number { width: 60px; text-align: right; padding: 0 12px; color: #484f58; font-family: monospace; font-size: 13px; user-select: none; border-right: 1px solid #21262d; vertical-align: top; }
                .source-code .line-code { padding: 0 16px; font-family: 'SF Mono', 'Fira Code', monospace; font-size: 13px; white-space: pre-wrap; word-break: break-all; }
                .source-code tr { line-height: 1.6; }
                .source-code tr.highlight { background: rgba(231, 76, 60, 0.2); }
                .source-code tr.highlight .line-number { color: #e74c3c; font-weight: bold; background: rgba(231, 76, 60, 0.1); }

                .info-table { width: 100%; border-collapse: collapse; }
                .info-table td { padding: 0.5rem 1.5rem; border-bottom: 1px solid #16213e; font-family: monospace; font-size: 0.85rem; vertical-align: top; }
                .info-table td:first-child { color: #7ec8e3; width: 200px; white-space: nowrap; }
                .info-table td:last-child { color: #c9d1d9; word-break: break-all; }

                .empty-msg { padding: 1rem 1.5rem; color: #666; font-style: italic; }

                @media (max-width: 768px) {
                    .content { flex-direction: column; }
                    .sidebar { width: 100%; max-height: 250px; }
                }
            </style>
        </head>
        <body>
            <div class="error-header">
                <div class="exception-class">{$class}</div>
                <div class="exception-message">{$message}</div>
                <div class="exception-location">{$escFile}:{$line}</div>
            </div>

            <div class="content">
                <div class="sidebar">
                    {$traceHtml}
                </div>
                <div class="main">
                    <div class="section">
                        <div class="section-title">Source Code</div>
                        <div class="source-code">{$sourceHtml}</div>
                    </div>
                    <div class="section">
                        <div class="section-title">Request</div>
                        {$requestHtml}
                    </div>
                    <div class="section">
                        <div class="section-title">Environment</div>
                        {$envHtml}
                    </div>
                </div>
            </div>
        </body>
        </html>
        HTML;
    }

    private function renderSourceCode(string $file, int $errorLine, int $range = 15): string
    {
        if (!is_readable($file)) {
            return '<div class="empty-msg">Source not available</div>';
        }

        $lines = file($file);
        if ($lines === false) {
            return '<div class="empty-msg">Could not read file</div>';
        }

        $start = max(0, $errorLine - $range - 1);
        $end = min(count($lines), $errorLine + $range);

        $html = '<table>';
        for ($i = $start; $i < $end; $i++) {
            $lineNum = $i + 1;
            $isHighlight = $lineNum === $errorLine;
            $class = $isHighlight ? ' class="highlight"' : '';
            $code = $this->esc(rtrim($lines[$i]));
            $html .= "<tr{$class}><td class=\"line-number\">{$lineNum}</td><td class=\"line-code\">{$code}</td></tr>";
        }
        $html .= '</table>';

        return $html;
    }

    private function renderStackTrace(Throwable $exception): string
    {
        $trace = $exception->getTrace();
        $html = '';

        // Add the exception location as the first frame
        $html .= '<div class="frame active">';
        $html .= '<span class="frame-index">0</span>';
        $html .= '<span class="frame-method">' . $this->esc($exception::class) . '</span>';
        $html .= '<div class="frame-file">' . $this->esc($this->shortenPath($exception->getFile())) . ':' . $exception->getLine() . '</div>';
        $html .= '</div>';

        foreach ($trace as $i => $frame) {
            $method = '';
            if (isset($frame['class'])) {
                $method .= $this->esc($frame['class'] . ($frame['type'] ?? '::'));
            }
            $method .= $this->esc($frame['function'] ?? '');

            $file = isset($frame['file'])
                ? $this->esc($this->shortenPath($frame['file'])) . ':' . ($frame['line'] ?? '?')
                : '<internal>';

            $html .= '<div class="frame">';
            $html .= '<span class="frame-index">' . ($i + 1) . '</span>';
            $html .= '<span class="frame-method">' . $method . '</span>';
            $html .= '<div class="frame-file">' . $file . '</div>';
            $html .= '</div>';
        }

        return $html;
    }

    private function renderRequestInfo(): string
    {
        $rows = [
            'Method' => $_SERVER['REQUEST_METHOD'] ?? '-',
            'URI' => $_SERVER['REQUEST_URI'] ?? '-',
            'IP' => $_SERVER['REMOTE_ADDR'] ?? '-',
            'User-Agent' => $_SERVER['HTTP_USER_AGENT'] ?? '-',
            'Content-Type' => $_SERVER['CONTENT_TYPE'] ?? '-',
            'Accept' => $_SERVER['HTTP_ACCEPT'] ?? '-',
        ];

        // Add GET params
        foreach ($_GET as $key => $value) {
            $rows['GET[' . $key . ']'] = is_array($value) ? json_encode($value) : (string) $value;
        }

        // Add POST params (mask sensitive fields)
        $sensitive = ['password', 'password_confirm', 'secret', 'token', 'credit_card'];
        foreach ($_POST as $key => $value) {
            if (in_array(strtolower($key), $sensitive, true)) {
                $rows['POST[' . $key . ']'] = '********';
            } else {
                $rows['POST[' . $key . ']'] = is_array($value) ? json_encode($value) : (string) $value;
            }
        }

        // Cookies
        foreach ($_COOKIE as $key => $value) {
            $rows['Cookie[' . $key . ']'] = (string) $value;
        }

        return $this->renderInfoTable($rows);
    }

    private function renderEnvironmentInfo(): string
    {
        $rows = [
            'PHP Version' => PHP_VERSION,
            'PHP SAPI' => PHP_SAPI,
            'Server Software' => $_SERVER['SERVER_SOFTWARE'] ?? '-',
            'Document Root' => $_SERVER['DOCUMENT_ROOT'] ?? '-',
            'Memory Usage' => $this->formatBytes(memory_get_usage(true)),
            'Peak Memory' => $this->formatBytes(memory_get_peak_usage(true)),
            'Loaded Extensions' => implode(', ', get_loaded_extensions()),
        ];

        return $this->renderInfoTable($rows);
    }

    /**
     * @param array<string, string> $data
     */
    private function renderInfoTable(array $data): string
    {
        if (empty($data)) {
            return '<div class="empty-msg">No data</div>';
        }

        $html = '<table class="info-table">';
        foreach ($data as $key => $value) {
            $html .= '<tr><td>' . $this->esc($key) . '</td><td>' . $this->esc((string) $value) . '</td></tr>';
        }
        $html .= '</table>';

        return $html;
    }

    private function shortenPath(string $path): string
    {
        $base = $_SERVER['DOCUMENT_ROOT'] ?? '';
        $projectRoot = dirname($base);

        if ($projectRoot !== '' && str_starts_with($path, $projectRoot)) {
            return substr($path, strlen($projectRoot) + 1);
        }

        return $path;
    }

    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        $value = (float) $bytes;
        while ($value >= 1024 && $i < count($units) - 1) {
            $value /= 1024;
            $i++;
        }
        return round($value, 2) . ' ' . $units[$i];
    }

    private function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    // ------------------------------------------------------------------
    // Production page
    // ------------------------------------------------------------------

    /**
     * The plain page for visitors when an error escapes before the site can draw
     * its own: ErrorWords, in the current language (English before boot).
     *
     * @internal public for tests
     */
    public function productionPage(int $statusCode): string
    {
        [$title, $message] = array_map($this->esc(...), ErrorWords::for($statusCode));
        $home = $this->esc(ErrorWords::home());
        $lang = $this->esc(str_replace('_', '-', \Kestello\Support\Translator::locale()));

        return <<<HTML
        <!DOCTYPE html>
        <html lang="{$lang}">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>{$title}</title>
            <style>
                * { margin: 0; padding: 0; box-sizing: border-box; }
                body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; display: flex; justify-content: center; align-items: center; min-height: 100vh; background: #f3f4f6; color: #111827; }
                @media (prefers-color-scheme: dark) { body { background: #0b0f17; color: #eef0f4; } .home-link { background: #eef0f4; color: #0b0f17; } }
                .error-box { text-align: center; max-width: 420px; padding: 2rem; }
                .status { font-size: 5rem; font-weight: 800; opacity: 0.25; line-height: 1; }
                h1 { font-size: 1.5rem; margin: 1rem 0 0.5rem; }
                p { opacity: 0.75; line-height: 1.6; }
                .home-link { display: inline-block; margin-top: 1.5rem; padding: 0.6rem 1.5rem; background: #111827; color: #fff; border-radius: 6px; text-decoration: none; font-weight: 500; }
            </style>
        </head>
        <body>
            <div class="error-box">
                <div class="status">{$statusCode}</div>
                <h1>{$title}</h1>
                <p>{$message}</p>
                <a href="/" class="home-link">{$home}</a>
            </div>
        </body>
        </html>
        HTML;
    }

    private function formatForCli(Throwable $exception): string
    {
        return sprintf(
            "\n\033[31m[%s]\033[0m %s\n  \033[90min %s:%d\033[0m\n\n%s\n",
            $exception::class,
            $exception->getMessage(),
            $exception->getFile(),
            $exception->getLine(),
            $exception->getTraceAsString(),
        );
    }
}
