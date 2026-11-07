<?php

declare(strict_types=1);

namespace Kestello\Cms\Api;

use Kestello\Contracts\Auth\Principal;

/**
 * Turns a row into API output with declared fields only. Rows and models
 * never reach JSON directly, so a new column can't leak by accident.
 *
 *   final class BookResource extends Resource
 *   {
 *       public const string NAME = 'acme-books';  // lets plugins extend it
 *       protected function fields(Principal $principal): array
 *       {
 *           return ['id' => (int) $this->row['id'], 'title' => $this->row['title']];
 *       }
 *   }
 *
 * @api
 */
abstract class Resource
{
    /** Name other plugins use with $cms->api->extend(NAME). Empty = not extensible. */
    public const string NAME = '';

    /** @param array<string, mixed> $row */
    final public function __construct(protected readonly array $row) {}

    /** @return array<string, mixed> */
    abstract protected function fields(Principal $principal): array;

    /** OpenAPI description of fields(): property => schema. */
    public static function properties(): array
    {
        return [];
    }

    /** @param iterable<array<string, mixed>> $rows */
    public static function collection(iterable $rows, array $meta = []): ResourceCollection
    {
        $items = [];
        foreach ($rows as $row) {
            $items[] = new static($row);
        }

        return new ResourceCollection($items, $meta);
    }

    /**
     * Core fields, then plugin fields (ResourceExtension), then the
     * "api.response.<NAME>" filter, which may add or redact but not remove.
     *
     * @return array<string, mixed>
     * @internal called by the dispatcher
     */
    final public function resolve(Principal $principal, ?ApiRegistry $api = null): array
    {
        $data = $this->fields($principal);

        if (static::NAME !== '' && $api !== null) {
            $data = $api->extensionFor(static::NAME)->apply($data, $this->row, $principal);
            $data = $api->filterResponse(static::NAME, $data, $this->row, $principal);
        }

        return $data;
    }

    /** ISO 8601 UTC, or null (Date::isoUtc). */
    protected static function date(mixed $value): ?string
    {
        return \Kestello\Support\Date::isoUtc($value);
    }
}
