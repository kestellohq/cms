<?php

declare(strict_types=1);

namespace Kestello\Cms\Api;

/**
 * A list of resources plus pagination meta: {"data": [...], "meta": {...}}.
 *
 * @api
 */
final readonly class ResourceCollection
{
    /**
     * @param list<Resource>        $items
     * @param array<string, mixed>  $meta
     */
    public function __construct(
        public array $items,
        public array $meta = [],
    ) {}
}
