<?php

declare(strict_types=1);

namespace Kestello\Cms\Design;

use Kestello\Framework\Database\QueryBuilder;
use Kestello\Support\Date;

/**
 * Site changes to a theme, stored as JSON documents keyed by theme, kind
 * and key. The theme's files are the defaults; a published document
 * overrides one, and reset() goes back to the file. Each publish and
 * reset keeps a revision (the last KEEP per document) that can be restored.
 */
final class ThemeDocuments
{
    public const int KEEP = 50;
    public const array KINDS = ['styles', 'template', 'part', 'pattern'];

    /** @var array<string, ?array<string, mixed>> per request */
    private array $memo = [];

    /** @return array<string, mixed>|null the document, or null when the theme's file applies */
    public function get(string $theme, string $kind, string $key, string $status = 'published'): ?array
    {
        $id = "{$theme}|{$kind}|{$key}|{$status}";
        if (array_key_exists($id, $this->memo)) {
            return $this->memo[$id];
        }
        $row = QueryBuilder::table('theme_documents')->where('theme', $theme)->where('kind', $kind)->where('doc_key', $key)->where('status', $status)->first();
        $data = $row === null ? null : json_decode((string) ((array) $row)['data'], true);

        return $this->memo[$id] = is_array($data) ? $data : null;
    }

    /** @return array{revision: int, updated_at: string, updated_by: ?int, base_hash: ?string}|null */
    public function info(string $theme, string $kind, string $key, string $status = 'published'): ?array
    {
        $row = QueryBuilder::table('theme_documents')->where('theme', $theme)->where('kind', $kind)->where('doc_key', $key)->where('status', $status)->first();
        if ($row === null) {
            return null;
        }
        $row = (array) $row;

        return ['revision' => (int) $row['revision'], 'updated_at' => (string) Date::isoUtc($row['updated_at']), 'updated_by' => $row['updated_by'] === null ? null : (int) $row['updated_by'], 'base_hash' => isset($row['base_hash']) ? (string) $row['base_hash'] : null];
    }

    /** Publish (or save a draft of) a document. Returns its revision number. */
    /** @param string|null $baseHash the theme file this version started from (theme updates are compared against it) */
    public function save(string $theme, string $kind, string $key, array $data, ?int $userId, string $status = 'published', string $note = '', ?string $baseHash = null): int
    {
        self::check($kind, $status);
        $json = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $now = gmdate('Y-m-d H:i:s');
        $revision = $this->nextRevision($theme, $kind, $key);
        $existing = QueryBuilder::table('theme_documents')->where('theme', $theme)->where('kind', $kind)->where('doc_key', $key)->where('status', $status)->first();
        if ($existing === null) {
            QueryBuilder::table('theme_documents')->insert(['theme' => $theme, 'kind' => $kind, 'doc_key' => $key, 'status' => $status, 'data' => $json, 'revision' => $revision, 'updated_by' => $userId, 'base_hash' => $baseHash, 'created_at' => $now, 'updated_at' => $now]);
        } else {
            QueryBuilder::table('theme_documents')->where('id', ((array) $existing)['id'])->update(['data' => $json, 'revision' => $revision, 'updated_by' => $userId, 'base_hash' => $baseHash, 'updated_at' => $now]);
        }
        if ($status === 'published') {
            $this->keep($theme, $kind, $key, $revision, $json, $userId, $note);
            QueryBuilder::table('theme_documents')->where('theme', $theme)->where('kind', $kind)->where('doc_key', $key)->where('status', 'draft')->delete();
        }
        $this->memo = [];

        return $revision;
    }

    /** Mark a published document as based on this theme file ("keep mine" after a theme update). */
    public function rebase(string $theme, string $kind, string $key, string $baseHash): void
    {
        QueryBuilder::table('theme_documents')->where('theme', $theme)->where('kind', $kind)->where('doc_key', $key)->update(['base_hash' => $baseHash]);
        $this->memo = [];
    }

    /** Every published document of a theme (export, copying a theme). @return list<array{kind: string, key: string, data: array<string, mixed>}> */
    public function allPublished(string $theme): array
    {
        $out = [];
        foreach (QueryBuilder::table('theme_documents')->where('theme', $theme)->where('status', 'published')->orderBy('kind')->orderBy('doc_key')->get() as $row) {
            $row = (array) $row;
            $data = json_decode((string) $row['data'], true);
            if (is_array($data)) {
                $out[] = ['kind' => (string) $row['kind'], 'key' => (string) $row['doc_key'], 'data' => $data];
            }
        }

        return $out;
    }

    /** Drop a draft; the published version (or the theme's file) stays. */
    public function discardDraft(string $theme, string $kind, string $key): bool
    {
        $n = QueryBuilder::table('theme_documents')->where('theme', $theme)->where('kind', $kind)->where('doc_key', $key)->where('status', 'draft')->delete();
        $this->memo = [];

        return $n > 0;
    }

    /** Go back to the theme's file. Kept as a revision, so it can be undone. */
    public function reset(string $theme, string $kind, string $key, ?int $userId): void
    {
        self::check($kind, 'published');
        $had = QueryBuilder::table('theme_documents')->where('theme', $theme)->where('kind', $kind)->where('doc_key', $key)->exists();
        QueryBuilder::table('theme_documents')->where('theme', $theme)->where('kind', $kind)->where('doc_key', $key)->delete();
        if ($had) {
            $this->keep($theme, $kind, $key, $this->nextRevision($theme, $kind, $key), 'null', $userId, 'Back to the theme\'s defaults');
        }
        $this->memo = [];
    }

    /** @return list<array{id: int, revision: int, author: ?int, note: string, created_at: string, reset: bool}> newest first */
    public function revisions(string $theme, string $kind, string $key, int $limit = 20): array
    {
        $out = [];
        foreach (QueryBuilder::table('theme_document_revisions')->where('theme', $theme)->where('kind', $kind)->where('doc_key', $key)->orderBy('revision', 'desc')->limit($limit)->get() as $row) {
            $row = (array) $row;
            $out[] = ['id' => (int) $row['id'], 'revision' => (int) $row['revision'], 'author' => $row['author'] === null ? null : (int) $row['author'], 'note' => (string) $row['note'], 'created_at' => (string) Date::isoUtc($row['created_at']), 'reset' => $row['data'] === 'null'];
        }

        return $out;
    }

    /**
     * One revision's data (null: it was a reset to the theme's file).
     *
     * @return array{theme: string, kind: string, key: string, data: ?array<string, mixed>}|null
     */
    public function revision(int $id): ?array
    {
        $row = QueryBuilder::table('theme_document_revisions')->where('id', $id)->first();
        if ($row === null) {
            return null;
        }
        $row = (array) $row;
        $data = json_decode((string) $row['data'], true);

        return ['theme' => (string) $row['theme'], 'kind' => (string) $row['kind'], 'key' => (string) $row['doc_key'], 'data' => is_array($data) ? $data : null];
    }

    private function nextRevision(string $theme, string $kind, string $key): int
    {
        $a = (int) QueryBuilder::table('theme_document_revisions')->where('theme', $theme)->where('kind', $kind)->where('doc_key', $key)->max('revision');
        $b = (int) QueryBuilder::table('theme_documents')->where('theme', $theme)->where('kind', $kind)->where('doc_key', $key)->max('revision');

        return max($a, $b) + 1;
    }

    private function keep(string $theme, string $kind, string $key, int $revision, string $json, ?int $userId, string $note): void
    {
        $now = gmdate('Y-m-d H:i:s');
        QueryBuilder::table('theme_document_revisions')->insert(['theme' => $theme, 'kind' => $kind, 'doc_key' => $key, 'revision' => $revision, 'data' => $json, 'author' => $userId, 'note' => mb_substr($note, 0, 200), 'created_at' => $now, 'updated_at' => $now]);
        $old = QueryBuilder::table('theme_document_revisions')->where('theme', $theme)->where('kind', $kind)->where('doc_key', $key)
            ->orderBy('revision', 'desc')->offset(self::KEEP)->limit(1000)->pluck('id');
        if ($old !== []) {
            QueryBuilder::table('theme_document_revisions')->whereIn('id', $old)->delete();
        }
    }

    private static function check(string $kind, string $status): void
    {
        if (!in_array($kind, self::KINDS, true) || !in_array($status, ['published', 'draft'], true)) {
            throw new \InvalidArgumentException("Unknown document kind or status: {$kind}/{$status}.");
        }
    }
}
