<?php

declare(strict_types=1);

namespace Kestello\Cms\View;

use Closure;
use Kestello\Framework\Core\ExecutionContext;

/**
 * Data shared with every page, available in Vue through usePage().props.
 *
 *   $cms->pages->share('cartCount', fn() => Cart::count());
 *
 * Values are sent to the browser: never share secrets. Closures run lazily,
 * once per page render, as the package that registered them.
 *
 * @api
 */
final class Pages
{
    /** @var array<string, array{value: mixed, owner: string}> */
    private array $shared = [];

    public function share(string $key, mixed $value): void
    {
        $this->shared[$key] = ['value' => $value, 'owner' => ExecutionContext::current()];
    }

    /**
     * @return array<string, mixed>
     */
    public function resolve(): array
    {
        $out = [];

        foreach ($this->shared as $key => $entry) {
            $out[$key] = $entry['value'] instanceof Closure
                ? ExecutionContext::run($entry['owner'], $entry['value'])
                : $entry['value'];
        }

        return $out;
    }
}
