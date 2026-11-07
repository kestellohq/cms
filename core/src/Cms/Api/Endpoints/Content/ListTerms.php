<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Content;

use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Api\Schema;
use Kestello\Cms\Content\Taxonomy;
use Kestello\Cms\Content\Term;
use Kestello\Framework\Database\QueryBuilder;

/**
 * Terms of a taxonomy, by name (hierarchical taxonomies: parents before
 * children, with depth). ?search= or ?ids= narrow it (pickers); counts
 * are published entries.
 */
final class ListTerms extends TermEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::get('/taxonomies/{taxonomy}/terms')->where('taxonomy', self::TYPE);
    }
    public function summary(): string
    {
        return 'List terms of a taxonomy';
    }

    public function query(): ?Schema
    {
        return Schema::object([
            'search' => Schema::string()->max(100)->optional(),
            'ids' => Schema::string()->max(1000)->pattern('[0-9,]*')->optional(),
            'limit' => Schema::int()->min(1)->max(2000)->default(1000),
        ]);
    }

    public function handle(ApiRequest $request): mixed
    {
        $tax = self::taxonomy($request);
        $q = Term::query()->of($tax->name);
        $ids = array_slice(array_values(array_unique(array_map('intval', array_filter(explode(',', (string) $request->query('ids', '')))))), 0, 200);
        $narrowed = false;
        if ($ids !== []) {
            $q->whereIn('id', $ids);
            $narrowed = true;
        } elseif (($search = trim((string) $request->query('search', ''))) !== '') {
            $q->whereLike('name', '%' . QueryBuilder::escapeLike($search) . '%');
            $narrowed = true;
        }
        $terms = $q->orderBy('menu_order')->orderBy('name')->limit((int) $request->query('limit'))->get()->all();
        $counts = self::terms()->counts($tax);
        $rows = array_map(static fn(Term $t) => self::row($t, $tax, $counts[$t->getKey()] ?? 0), $terms);

        return $tax->isHierarchical() && !$narrowed ? self::tree($rows) : array_map(static fn($r) => $r + ['depth' => 0], $rows);
    }

    /**
     * Depth-first order with depth; orphans (parent outside the list) start at the top.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private static function tree(array $rows): array
    {
        $byParent = [];
        $known = array_column($rows, 'id', 'id');
        foreach ($rows as $row) {
            $byParent[$row['parent_id'] !== null && isset($known[$row['parent_id']]) ? $row['parent_id'] : 0][] = $row;
        }
        $out = [];
        $walk = static function (int $parent, int $depth) use (&$walk, &$out, $byParent): void {
            foreach ($byParent[$parent] ?? [] as $row) {
                $out[] = $row + ['depth' => $depth];
                if ($depth < 10) {
                    $walk((int) $row['id'], $depth + 1);
                }
            }
        };
        $walk(0, 0);

        return $out;
    }
}
