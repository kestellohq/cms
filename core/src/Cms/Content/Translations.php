<?php

declare(strict_types=1);

namespace Kestello\Cms\Content;

use Kestello\Framework\Database\QueryBuilder;

/**
 * The translations of entries, for lists and the editor: for each entry,
 * the other entries in its translation group, read in one query.
 *
 * @api
 */
final class Translations
{
    /**
     * @param list<Entry> $entries
     * @return array<int, list<array{locale: string, id: int, status: string, title: string}>> by entry id, without the entry itself, ordered by language
     */
    public static function of(array $entries): array
    {
        $groups = [];
        foreach ($entries as $entry) {
            if (($entry->translation_group ?? '') !== '') {
                $groups[(string) $entry->translation_group][] = (int) $entry->getKey();
            }
        }
        if ($groups === []) {
            return [];
        }
        $rows = QueryBuilder::table('entries')->whereIn('translation_group', array_keys($groups))->whereNull('deleted_at')
            ->orderBy('locale')->select('id', 'translation_group', 'locale', 'status', 'title')->get();
        $out = [];
        foreach ($rows as $row) {
            foreach ($groups[(string) $row['translation_group']] ?? [] as $id) {
                if ((int) $row['id'] !== $id) {
                    $out[$id][] = ['locale' => (string) $row['locale'], 'id' => (int) $row['id'], 'status' => (string) $row['status'], 'title' => (string) $row['title']];
                }
            }
        }

        return $out;
    }
}
