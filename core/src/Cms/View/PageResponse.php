<?php

declare(strict_types=1);

namespace Kestello\Cms\View;

/**
 * A page to be rendered by Vue. The frontend middleware turns it into the
 * HTML shell (first visit) or page JSON (client navigation).
 */
final readonly class PageResponse
{
    /**
     * @param array<string, mixed> $props
     * @param list<string> $preload other registered components the page renders (blocks):
     *                              their scripts are preloaded and their styles linked
     */
    public function __construct(
        public string $component,
        public array $props = [],
        public int $status = 200,
        public array $preload = [],
    ) {}
}
