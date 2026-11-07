<?php

declare(strict_types=1);

namespace Kestello\Cms\Content;

use Kestello\Framework\Database\QueryBuilder;
use Kestello\Support\TextDiff;

/**
 * What changed between two revisions of an entry (or a revision and the
 * entry as it is now), field by field, as people read it: titles of
 * linked entries rather than ids, block labels and text rather than JSON.
 * Fields a person may not see in the editor (internal fields) are left
 * out, and so are fields the content type no longer has.
 */
final class RevisionDiff
{
    private const array LABELS = [
        'title' => 'Title', 'slug' => 'Address', 'status' => 'Status', 'excerpt' => 'Excerpt', 'template' => 'Template',
        'parent_id' => 'Parent', 'author_id' => 'Author', 'menu_order' => 'Page order', 'published_at' => 'Publish date',
    ];

    public function __construct(private readonly ContentRegistry $content) {}

    /**
     * @param array<string, mixed> $before a revision snapshot (Entries::snapshot)
     * @param array<string, mixed> $after
     * @return list<array{key: string, label: string, lines: list<array<string, mixed>>}> changed fields only, in editor order
     */
    public function between(ContentType $type, array $before, array $after): array
    {
        $texts = [];
        $texts['title'] = [(string) ($before['title'] ?? ''), (string) ($after['title'] ?? '')];
        $texts['slug'] = [(string) ($before['slug'] ?? ''), (string) ($after['slug'] ?? '')];
        $types = $this->content->fields;
        foreach ($type->getFields() as $field) {
            if ($field->isInternal() || !$types->has($field->type)) {
                continue;
            }
            $ft = $types->get($field->type);
            $texts['data.' . $field->name] = [
                $ft->text(((array) ($before['data'] ?? []))[$field->name] ?? null, $field, $types),
                $ft->text(((array) ($after['data'] ?? []))[$field->name] ?? null, $field, $types),
            ];
        }
        if ($type->hasExcerpt()) {
            $texts['excerpt'] = [(string) ($before['excerpt'] ?? ''), (string) ($after['excerpt'] ?? '')];
        }
        foreach ($this->content->taxonomies() as $tax) {
            if ($tax->appliesTo($type->name)) {
                $texts['terms.' . $tax->name] = [self::termNames((array) ((array) ($before['terms'] ?? []))[$tax->name] ?? []), self::termNames((array) ((array) ($after['terms'] ?? []))[$tax->name] ?? [])];
            }
        }
        $texts['status'] = [(string) ($before['status'] ?? ''), (string) ($after['status'] ?? '')];
        $texts['published_at'] = [(string) ($before['published_at'] ?? ''), (string) ($after['published_at'] ?? '')];
        $texts['parent_id'] = [self::name('entries', 'title', $before['parent_id'] ?? null), self::name('entries', 'title', $after['parent_id'] ?? null)];
        $texts['author_id'] = [self::name('users', 'username', $before['author_id'] ?? null), self::name('users', 'username', $after['author_id'] ?? null)];
        $texts['template'] = [(string) ($before['template'] ?? ''), (string) ($after['template'] ?? '')];
        $texts['menu_order'] = [(string) ($before['menu_order'] ?? '0'), (string) ($after['menu_order'] ?? '0')];

        $out = [];
        foreach ($texts as $key => [$old, $new]) {
            if ($old === $new) {
                continue;
            }
            $out[] = ['key' => $key, 'label' => $this->label($type, $key), 'lines' => TextDiff::lines($old, $new)];
        }

        return $out;
    }

    private function label(ContentType $type, string $key): string
    {
        if (str_starts_with($key, 'data.')) {
            foreach ($type->getFields() as $field) {
                if ($field->name === substr($key, 5)) {
                    return $field->getLabel();
                }
            }
        }
        if (str_starts_with($key, 'terms.')) {
            return $this->content->getTaxonomy(substr($key, 6))?->plural() ?? substr($key, 6);
        }

        return self::LABELS[$key] ?? $key;
    }

    /** @param list<int|string> $ids */
    private static function termNames(array $ids): string
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if ($ids === []) {
            return '';
        }
        $names = array_column(QueryBuilder::table('terms')->whereIn('id', $ids)->select('id', 'name')->get(), 'name', 'id');
        $list = array_map(static fn(int $id) => (string) ($names[$id] ?? '#' . $id), $ids);
        sort($list, SORT_NATURAL | SORT_FLAG_CASE);

        return implode("\n", $list);
    }

    private static function name(string $table, string $column, mixed $id): string
    {
        if (!is_numeric($id)) {
            return '';
        }
        $name = QueryBuilder::table($table)->where('id', (int) $id)->value($column);

        return $name === null ? '#' . $id : ((string) $name !== '' ? (string) $name : '(untitled)');
    }
}
