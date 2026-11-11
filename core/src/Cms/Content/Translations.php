<?php

declare(strict_types=1);

namespace Kestello\Cms\Content;

use Kestello\Framework\Database\QueryBuilder;

/**
 * The translations of entries and terms, for lists and the editor: for
 * each one, the others in its translation group, read in one query.
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

    /**
     * @param list<Term> $terms
     * @return array<int, list<array{locale: string, id: int, title: string}>> by term id, without the term itself, ordered by language (title: the name)
     */
    public static function ofTerms(array $terms): array
    {
        $groups = [];
        foreach ($terms as $term) {
            if (($term->translation_group ?? '') !== '') {
                $groups[(string) $term->translation_group][] = (int) $term->getKey();
            }
        }
        if ($groups === []) {
            return [];
        }
        $out = [];
        foreach (QueryBuilder::table('terms')->whereIn('translation_group', array_keys($groups))->orderBy('locale')->select('id', 'translation_group', 'locale', 'name')->get() as $row) {
            foreach ($groups[(string) $row['translation_group']] ?? [] as $id) {
                if ((int) $row['id'] !== $id) {
                    $out[$id][] = ['locale' => (string) $row['locale'], 'id' => (int) $row['id'], 'title' => (string) $row['name']];
                }
            }
        }

        return $out;
    }

    /**
     * Each of $ids as it is in $language: its translation's id where it has
     * one (published, for entries), else the id itself. For links stored in
     * one language and shown in another.
     *
     * @param 'entries'|'terms' $table
     * @param list<int> $ids
     * @return array<int, int> id => the id to show
     */
    public static function idsIn(string $table, array $ids, string $language): array
    {
        $map = array_combine($ids, $ids);
        $rows = $ids === [] ? [] : QueryBuilder::table($table)->whereIn('id', $ids)->whereNotNull('translation_group')->where('locale', '!=', $language)->select('id', 'translation_group')->get();
        $groups = [];
        foreach ($rows as $row) {
            $groups[(string) $row['translation_group']][] = (int) $row['id'];
        }
        if ($groups === []) {
            return $map;
        }
        $q = QueryBuilder::table($table)->whereIn('translation_group', array_keys($groups))->where('locale', $language);
        if ($table === 'entries') {
            $q->whereNull('deleted_at')->where('status', 'published')->where('published_at', '<=', \Kestello\Framework\Database\Model::freshTimestamp());
        }
        foreach ($q->select('id', 'translation_group')->get() as $found) {
            foreach ($groups[(string) $found['translation_group']] ?? [] as $id) {
                $map[$id] = (int) $found['id'];
            }
        }

        return $map;
    }
}
