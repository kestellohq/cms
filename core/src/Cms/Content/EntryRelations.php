<?php

declare(strict_types=1);

namespace Kestello\Cms\Content;

use Kestello\Framework\Database\QueryBuilder;

/**
 * Two-way relationships. Relationship fields store the ids an entry links to;
 * this keeps the other direction in the entry_relations table, so an entry
 * knows which entries link to it:
 *
 *   sync($entryId, $byField)   on every save (Entries::reindex), from FieldTypes::relationsIn()
 *   linkingTo($targetId)       the entries that link here, with the fields they use
 *   rebuild()                  every entry again, for links saved before the table existed
 *
 * Links are recorded per top-level field, so a link inside a group, repeater
 * or block counts under the field that holds it.
 */
final class EntryRelations
{
    public const string TABLE = 'entry_relations';

    public function __construct(private readonly ContentRegistry $content) {}

    /**
     * Record what an entry links to, replacing what it linked to before. Ids
     * that are not entries, and the entry itself, are left out.
     *
     * @param array<string, list<int>> $byField
     */
    public static function sync(int $entryId, array $byField): void
    {
        QueryBuilder::table(self::TABLE)->where('source_id', $entryId)->delete();
        $rows = [];
        foreach ($byField as $field => $ids) {
            foreach (array_unique(array_map('intval', $ids)) as $id) {
                if ($id > 0 && $id !== $entryId) {
                    $rows[] = ['source_id' => $entryId, 'target_id' => $id, 'field' => mb_substr((string) $field, 0, 64)];
                }
            }
        }
        if ($rows === []) {
            return;
        }
        $existing = array_map('intval', QueryBuilder::table('entries')->whereIn('id', array_values(array_unique(array_column($rows, 'target_id'))))->pluck('id'));
        $rows = array_values(array_filter($rows, static fn(array $r): bool => in_array($r['target_id'], $existing, true)));
        $rows !== [] && QueryBuilder::table(self::TABLE)->insertMany($rows);
    }

    /**
     * Entries that link to $targetId (not those in the trash), newest change
     * first, each with the top-level fields that hold the link.
     *
     * @param list<string>|null $types only entries of these types
     * @return list<array{id: int, type: string, title: string, status: string, author_id: ?int, fields: list<string>}>
     */
    public function linkingTo(int $targetId, ?array $types = null, int $limit = 200): array
    {
        $q = QueryBuilder::table(self::TABLE)->join('entries', 'entries.id', '=', self::TABLE . '.source_id')
            ->where(self::TABLE . '.target_id', $targetId)->whereNull('entries.deleted_at')
            ->select('entries.id', 'entries.type', 'entries.title', 'entries.status', 'entries.author_id', self::TABLE . '.field')
            ->orderByDesc('entries.updated_at')->orderBy('entries.id')->orderBy(self::TABLE . '.field');
        if ($types !== null) {
            if ($types === []) {
                return [];
            }
            $q->whereIn('entries.type', $types);
        }
        $out = [];
        foreach ($q->get() as $row) {
            $row = (array) $row;
            $id = (int) $row['id'];
            if (!isset($out[$id])) {
                if (count($out) >= $limit) {
                    break;
                }
                $out[$id] = [
                    'id' => $id, 'type' => (string) $row['type'], 'title' => (string) $row['title'], 'status' => (string) $row['status'],
                    'author_id' => $row['author_id'] === null ? null : (int) $row['author_id'], 'fields' => [],
                ];
            }
            $out[$id]['fields'][] = (string) $row['field'];
        }

        return array_values($out);
    }

    /**
     * Record every entry's links again (after an upgrade, an import or a
     * restore from a backup made before this table existed).
     *
     * @return int the number of links recorded
     */
    public function rebuild(int $chunk = 200): int
    {
        $count = 0;
        $lastId = 0;
        do {
            $rows = QueryBuilder::table('entries')->where('id', '>', $lastId)->orderBy('id')->limit($chunk)->select('id', 'type', 'data')->get();
            foreach ($rows as $row) {
                $row = (array) $row;
                $lastId = (int) $row['id'];
                $type = $this->content->get((string) $row['type']);
                $data = json_decode((string) $row['data'], true);
                $links = $type === null || !is_array($data) ? [] : $this->content->fields->relationsIn($type->getFields(), $data);
                self::sync($lastId, $links);
                $count += QueryBuilder::table(self::TABLE)->where('source_id', $lastId)->count();
            }
        } while (count($rows) === $chunk);

        return $count;
    }

    /**
     * A top-level field was renamed in the content model builder: its links follow.
     *
     * @param list<int> $entryIds
     */
    public static function renameField(array $entryIds, string $from, string $to): void
    {
        if ($entryIds !== [] && $from !== $to) {
            QueryBuilder::table(self::TABLE)->whereIn('source_id', $entryIds)->where('field', $from)->update(['field' => mb_substr($to, 0, 64)]);
        }
    }
}
