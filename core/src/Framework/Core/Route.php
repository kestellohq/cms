<?php

declare(strict_types=1);

namespace Kestello\Framework\Core;

use Closure;

/**
 * Immutable value object representing a single registered route.
 */
final readonly class Route
{
    /** @var string Regex pattern compiled from the path. */
    public string $pattern;

    /**
     * @param string                  $method      HTTP method (GET, POST, ...).
     * @param string                  $path        URI path template (e.g. /users/{id}).
     * @param Closure|array<string>   $handler     A closure or [ControllerClass, method] array.
     * @param string|null             $name        Optional route name.
     * @param list<string>            $middlewares  Middleware class names to apply.
     * @param array<string, string>   $constraints  Per-parameter regex constraints.
     */
    public function __construct(
        public string $method,
        public string $path,
        public Closure|array $handler,
        public ?string $name = null,
        public array $middlewares = [],
        public array $constraints = [],
        /** Extension that registered the route ("core", "plugin:acme-seo"); its handler runs as that owner. */
        public string $owner = 'core',
    ) {
        $this->pattern = $this->compilePattern();
    }

    /**
     * Test whether this route matches the given HTTP method and URI.
     *
     * @return array<string, string>|false Matched parameters, or false on no match.
     */
    public function matches(string $method, string $uri): array|false
    {
        $method = strtoupper($method);
        $own = strtoupper($this->method);

        // HEAD is answered by GET routes (the server drops the body).
        if ($method !== $own && !($method === 'HEAD' && $own === 'GET')) {
            return false;
        }

        $uri = '/' . trim($uri, '/');

        if (preg_match($this->pattern, $uri, $matches) !== 1) {
            return false;
        }

        // Return only the named captures.
        return array_filter($matches, fn(string|int $key): bool => is_string($key), ARRAY_FILTER_USE_KEY);
    }

    // ------------------------------------------------------------------
    // Internal
    // ------------------------------------------------------------------

    /**
     * Compile the path template into a regex pattern.
     */
    private function compilePattern(): string
    {
        $path = '/' . trim($this->path, '/');

        // Literal parts are quoted: "/feed.xml" must not match "/feedXxml".
        $pattern = '';
        foreach (preg_split('/(\{\w+\})/', $path, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [] as $part) {
            if (preg_match('/^\{(\w+)\}$/', $part, $m) === 1) {
                $pattern .= '(?P<' . $m[1] . '>' . ($this->constraints[$m[1]] ?? '[^/]+') . ')';
            } else {
                $pattern .= preg_quote($part, '#');
            }
        }

        return '#^' . $pattern . '$#u';
    }
}
