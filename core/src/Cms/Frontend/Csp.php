<?php

declare(strict_types=1);

namespace Kestello\Cms\Frontend;

use Kestello\Framework\Events\Filters;

/**
 * Per-request Content-Security-Policy with a script nonce (frontend-vue.md §6).
 *
 * Every <script> the page renders carries this nonce (PageRenderer adds it;
 * PHP code gets it from the container: $container->make(Csp::class)->nonce()). With
 * 'strict-dynamic', scripts loaded by a nonce'd script (Vue, islands) are
 * trusted; inline handlers (onclick=…) and injected <script> tags are not.
 *
 * Plugins adjust directives through the "security.csp" filter:
 *   $cms->filters->add('security.csp', fn(array $d) => [...$d, 'connect-src' => "'self' https://api.example.com"]);
 */
final class Csp
{
    private readonly string $nonce;

    public function __construct(
        private readonly ?Filters $filters = null,
    ) {
        $this->nonce = rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '=');
    }

    public function nonce(): string
    {
        return $this->nonce;
    }

    public function header(): string
    {
        $directives = [
            // 'unsafe-inline' and https: are ignored by CSP3 browsers once a nonce is present;
            // they only keep very old browsers working (Google's "strict CSP" recipe).
            'script-src' => "'nonce-{$this->nonce}' 'strict-dynamic' https: 'unsafe-inline'",
            'object-src' => "'none'",
            'base-uri' => "'none'",
            'frame-ancestors' => "'self'",
            'form-action' => "'self'",
        ];

        if ($this->filters !== null) {
            $filtered = $this->filters->apply('security.csp', $directives);
            $directives = is_array($filtered) ? $filtered : $directives;
        }

        $parts = [];
        foreach ($directives as $name => $value) {
            // Directive names/values may not smuggle extra directives or header lines.
            if (!is_string($name) || preg_match('/^[a-z-]+$/', $name) !== 1 || !is_string($value) || preg_match('/[;\r\n]/', $value) === 1) {
                continue;
            }
            $parts[] = trim("{$name} {$value}");
        }

        return implode('; ', $parts);
    }
}
