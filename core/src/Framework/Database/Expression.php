<?php

declare(strict_types=1);

namespace Kestello\Framework\Database;

/**
 * A raw SQL fragment with its own bindings. Only for SQL written by the
 * developer; never build one from user input.
 *
 *   QueryBuilder::table('posts')->update(['views' => DB::raw('views + ?', [1])]);
 *
 * @api
 */
final readonly class Expression
{
    /** @param list<mixed> $bindings */
    public function __construct(
        public string $sql,
        public array $bindings = [],
    ) {}
}
