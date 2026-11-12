<?php

declare(strict_types=1);

namespace Kestello\Tests\Framework;

use Kestello\Framework\Testing\TestCase;

/**
 * A class-wide check: core never sorts by a time alone. Rows written in the
 * same second come back in whatever order the database likes (SQLite keeps
 * them as inserted, PostgreSQL does not), so a list could change order
 * between loads and a page could repeat or skip rows. A unique column after
 * the time (usually the id) keeps the order the same everywhere.
 */
final class StableOrderTest extends TestCase
{
    public function testNoQueryInCoreSortsByATimeAlone(): void
    {
        $core = dirname(__DIR__, 2) . '/core/src';
        $found = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($core, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            $path = (string) $file;
            if (!str_ends_with($path, '.php')) {
                continue;
            }
            $source = (string) file_get_contents($path);
            // each run of chained orderBy()/orderByDesc() calls: the last one decides ties
            preg_match_all('/(?:->\s*orderBy(?:Desc)?\(\s*\'[a-z_]+\'(?:\s*,\s*\'(?:asc|desc)\')?\s*\)\s*)+/i', $source, $chains, PREG_OFFSET_CAPTURE);
            foreach ($chains[0] as [$chain, $offset]) {
                preg_match_all('/orderBy(?:Desc)?\(\s*\'([a-z_]+)\'/i', $chain, $columns);
                $last = end($columns[1]);
                // a statement that adds the tie-break on its own line ($q = $q->orderBy('id'…)) counts too
                $after = substr($source, $offset + strlen($chain), 200);
                if (str_ends_with($last, '_at') && preg_match('/\A[^;]*;\s*\$\w+\s*=\s*\$\w+->orderBy(?:Desc)?\(/', $after) !== 1) {
                    $found[] = substr($path, strlen($core) + 1) . ':' . (substr_count($source, "\n", 0, $offset) + 1) . " ends on {$last}";
                }
            }
        }
        $this->assertSame([], $found, "add ->orderByDesc('id') (or another unique column) after the time");
    }
}
