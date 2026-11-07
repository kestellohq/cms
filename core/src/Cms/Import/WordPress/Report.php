<?php

declare(strict_types=1);

namespace Kestello\Cms\Import\WordPress;

use Kestello\Framework\Database\QueryBuilder;
use Kestello\Support\Date;

/**
 * What an import found and did, read from its staged items: counts per kind
 * and result, the problems with their reasons, and what conversion could not
 * carry over exactly. The same shape for the admin screen, the API and the CLI.
 *
 * @internal
 */
final class Report
{
    private const int LISTED = 200;

    /** Kinds in the order a person reads them. */
    public const array KINDS = ['entry', 'attachment', 'file', 'term', 'menu', 'redirect', 'reusable'];

    /**
     * @param array<string, mixed> $import the imports row
     * @return array<string, mixed>
     */
    public static function for(array $import): array
    {
        $id = (int) $import['id'];
        $counts = [];
        foreach (QueryBuilder::table('import_items')->selectRaw('kind, state, result, COUNT(*) AS n')->where('import_id', $id)->groupBy('kind', 'state', 'result')->get() as $row) {
            $kind = (string) $row['kind'];
            $counts[$kind] ??= ['total' => 0, 'pending' => 0] + array_fill_keys(Outcome::RESULTS, 0);
            $counts[$kind]['total'] += (int) $row['n'];
            if ($row['state'] === 'pending') {
                $counts[$kind]['pending'] += (int) $row['n'];
            } elseif (isset($counts[$kind][(string) $row['result']])) {
                $counts[$kind][(string) $row['result']] += (int) $row['n'];
            }
        }
        uksort($counts, static fn(string $a, string $b) => array_search($a, self::KINDS, true) <=> array_search($b, self::KINDS, true));

        $total = 0;
        $done = 0;
        foreach ($counts as $kind => $c) {
            if ($kind !== 'reusable') {
                $total += $c['total'];
                $done += $c['total'] - $c['pending'];
            }
        }

        return [
            'id' => $id,
            'status' => (string) $import['status'],
            'phase' => $import['phase'],
            'error' => $import['error'] ?? null,
            'site' => ['title' => (string) $import['site_title'], 'url' => (string) $import['site_url']],
            'summary' => self::json($import['summary']),
            'options' => self::json($import['options']),
            'notes' => self::json($import['notes']),
            'conversion' => self::conversion(self::json($import['notes'])),
            'counts' => $counts,
            'progress' => ['done' => $done, 'total' => $total],
            'problems' => self::items($id, ['failed', 'kept']),
            'notices' => self::items($id, ['created', 'updated']),
            'skipped' => self::skipped($id),
            'created_at' => Date::isoUtc($import['created_at']),
            'started_at' => Date::isoUtc($import['started_at']),
            'finished_at' => Date::isoUtc($import['finished_at']),
        ];
    }

    /**
     * Items with these results that carry a message, one by one, so each
     * can be checked: failures and kept edits, or entries that came over
     * with a change worth knowing (a new address, made private).
     *
     * @param list<string> $results
     * @return list<array{kind: string, label: string, result: string, message: string, target_id: ?int}>
     */
    private static function items(int $id, array $results): array
    {
        $rows = QueryBuilder::table('import_items')->select('kind', 'label', 'result', 'message', 'target_id')
            ->where('import_id', $id)->whereIn('result', $results)->whereNotNull('message')->orderBy('id')->limit(self::LISTED)->get();

        return array_map(static fn(array $r) => [
            'kind' => (string) $r['kind'], 'label' => (string) $r['label'], 'result' => (string) $r['result'],
            'message' => (string) $r['message'], 'target_id' => $r['target_id'] === null ? null : (int) $r['target_id'],
        ], $rows);
    }

    /**
     * Skipped items grouped by reason, with a few names of each.
     *
     * @return list<array{kind: string, reason: string, count: int, examples: list<string>}>
     */
    private static function skipped(int $id): array
    {
        $groups = [];
        $rows = QueryBuilder::table('import_items')->select('kind', 'label', 'message')
            ->where('import_id', $id)->where('result', 'skipped')->where('kind', '!=', 'reusable')->orderBy('id')->limit(5000)->get();
        foreach ($rows as $r) {
            $key = $r['kind'] . "\0" . $r['message'];
            $groups[$key] ??= ['kind' => (string) $r['kind'], 'reason' => (string) $r['message'], 'count' => 0, 'examples' => []];
            $groups[$key]['count']++;
            if (count($groups[$key]['examples']) < 5) {
                $groups[$key]['examples'][] = (string) $r['label'];
            }
        }

        return array_values($groups);
    }

    /**
     * What conversion could not carry over exactly, as sentences.
     *
     * @param array<string, mixed> $notes
     * @return list<array{key: string, count: int, text: string}>
     */
    private static function conversion(array $notes): array
    {
        $out = [];
        foreach ($notes as $key => $count) {
            $out[] = ['key' => (string) $key, 'count' => (int) $count, 'text' => self::note((string) $key)];
        }

        return $out;
    }

    /** A conversion note key ("embed:twitter.com") as a sentence. */
    public static function note(string $key): string
    {
        [$kind, $what] = array_pad(explode(':', $key, 2), 2, '');

        return match ($kind) {
            'block' => "The block {$what} has no match here: kept as its HTML.",
            'dropped' => "The block {$what} showed other things on the old site (a list of posts, a page break): left out.",
            'shortcode' => "The shortcode [{$what}] belongs to a WordPress plugin: kept as text.",
            'embed' => "An embed from {$what} became a link.",
            'frame' => "An embedded frame from {$what} was left out.",
            'removed' => "A <{$what}> element was left out.",
            'media-missing' => 'An image or file that was not imported still points at the old site.',
            'html' => 'Custom HTML was kept, without scripts, styles and frames.',
            'fallback' => 'A post was kept as text: its layout did not fit the blocks here.',
            default => $key,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private static function json(mixed $value): array
    {
        $decoded = is_string($value) ? json_decode($value, true) : null;

        return is_array($decoded) ? $decoded : [];
    }
}
