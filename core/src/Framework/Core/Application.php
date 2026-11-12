<?php

declare(strict_types=1);

namespace Kestello\Framework\Core;

use RuntimeException;

/**
 * Main application class.
 *
 * Bootstraps the framework, wires together the core services,
 * and dispatches the incoming HTTP request through the router.
 */
final class Application
{
    private static ?self $instance = null;

    private readonly Paths $paths;
    private readonly Container $container;
    private readonly Router $router;
    private readonly Config $config;
    private bool $booted = false;

    private function __construct(Paths $paths)
    {
        $this->paths = $paths;
        $this->container = new Container();
        $this->config = new Config([$paths->coreConfig(), $paths->config()]);
        $this->router = new Router();
    }

    /** Prevent cloning of the singleton. */
    private function __clone(): void {}

    /**
     * Return (and optionally create) the singleton instance.
     *
     * @param Paths|null $paths Application paths, required on first call.
     */
    public static function getInstance(?Paths $paths = null): self
    {
        if (self::$instance === null) {
            if ($paths === null) {
                throw new RuntimeException('Paths are required when creating the Application instance.');
            }

            self::$instance = new self($paths);
        }

        return self::$instance;
    }

    /**
     * Initialise all core services and error handling.
     */
    public function bootstrap(): self
    {
        if ($this->booted) {
            return $this;
        }

        $this->registerCoreBindings();
        $this->registerErrorHandler();

        $this->booted = true;

        return $this;
    }

    /**
     * Process the incoming request and send the response.
     */
    public function run(): void
    {
        $this->bootstrap();

        $request = Request::createFromGlobals();

        try {
            $response = $this->router->dispatch($request);

            // Branded error pages for 4xx/5xx (see renderErrorPage)
            if ($response->statusCode() >= 400) {
                $response = $this->renderErrorPage($request, $response);
            }
        } catch (\Throwable $e) {
            $response = $this->failed($request, $e);
        }

        Compression::apply($request, $response)->send();
    }

    /**
     * An exception the request didn't handle, as a response: the site's
     * branded error page with its security headers, like any 500 a page
     * returns. With app.debug on, the debug page; for the API, JSON; when
     * the branded page can't be rendered, ErrorHandler's plain page.
     */
    public function failed(Request $request, \Throwable $e): Response
    {
        $handler = $this->container->has(ErrorHandler::class)
            ? $this->container->make(ErrorHandler::class)
            : new ErrorHandler((bool) $this->config->get('app.debug', false), $this->paths->logs());
        $plain = $handler->response($e, $request);

        if ((bool) $this->config->get('app.debug', false)) {
            return $plain;
        }
        // the branded page sets its own policy: it gets the status alone, not the plain page's headers.
        // renderErrorPage gives the same object back for the API, JSON, and when it can't render.
        $bare = new Response('', $plain->statusCode());
        $branded = $this->renderErrorPage($request, $bare);

        return $branded === $bare ? $plain : $branded;
    }

    /**
     * Error pages for 4xx/5xx come from a renderer bound as "kestello.error_page"
     * (a Closure(Request, Response): Response, the CMS renders a Vue page).
     * Without one, or if it fails, the original response is sent.
     */
    private function renderErrorPage(Request $request, Response $response): Response
    {
        // Leave API/JSON, redirects-with-payload and already-rendered pages alone.
        if (str_starts_with($request->path, '/api/')
            || str_contains($request->header('Accept'), 'application/json')
            || $response->payload() !== null
            || !$this->container->has('kestello.error_page')) {
            return $response;
        }

        foreach ($response->headers() as $name => $value) {
            if (strtolower($name) === 'content-type' && !str_starts_with(strtolower($value), 'text/html')) {
                return $response;
            }
        }

        try {
            $render = $this->container->make('kestello.error_page');

            return $render($request, $response);
        } catch (\Throwable $e) {
            error_log('[kestello] error page failed: ' . $e->getMessage());
            return $response;
        }
    }

    /**
     * Return the absolute project root path, optionally appending a sub-path.
     */
    public function basePath(string $path = ''): string
    {
        return $this->paths->root($path);
    }

    public function paths(): Paths
    {
        return $this->paths;
    }

    public function container(): Container
    {
        return $this->container;
    }

    public function router(): Router
    {
        return $this->router;
    }

    public function config(): Config
    {
        return $this->config;
    }

    /**
     * Destroy the singleton (useful for testing).
     */
    public static function destroy(): void
    {
        self::$instance = null;
    }

    // ------------------------------------------------------------------
    // Internal helpers
    // ------------------------------------------------------------------

    private function registerCoreBindings(): void
    {
        $this->container->singleton(self::class, fn(): self => $this);
        $this->container->singleton(Container::class, fn(): Container => $this->container);
        $this->container->singleton(Router::class, fn(): Router => $this->router);
        $this->container->singleton(Config::class, fn(): Config => $this->config);
        $this->container->singleton(Paths::class, fn(): Paths => $this->paths);
    }

    private function registerErrorHandler(): void
    {
        $debug = (bool) $this->config->get('app.debug', false);
        $logPath = $this->paths->logs();

        $handler = new ErrorHandler(debug: $debug, logPath: $logPath);
        $handler->register();

        $this->container->singleton(ErrorHandler::class, fn(): ErrorHandler => $handler);
    }
}
