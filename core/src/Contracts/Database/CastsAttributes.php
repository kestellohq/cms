<?php

declare(strict_types=1);

namespace Kestello\Contracts\Database;

/**
 * A custom attribute cast: protected array $casts = ['price' => Money::class].
 *
 * @api
 */
interface CastsAttributes
{
    /** Database value → PHP value. */
    public function get(object $model, string $key, mixed $value): mixed;

    /** PHP value → database value (scalar or null). */
    public function set(object $model, string $key, mixed $value): mixed;
}
