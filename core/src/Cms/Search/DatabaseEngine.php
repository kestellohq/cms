<?php

declare(strict_types=1);

namespace Kestello\Cms\Search;

use Kestello\Contracts\Search\Engine;
use Kestello\Contracts\Search\SearchDocument;
use Kestello\Contracts\Search\SearchException;
use Kestello\Contracts\Search\SearchHit;
use Kestello\Contracts\Search\SearchQuery;
use Kestello\Contracts\Search\SearchResults;
use Kestello\Framework\Database\Connection;
use Kestello\Framework\Database\DatabaseManager;
use Kestello\Support\Json;
use Throwable;

/**
 * The default engine: the site's own database, no extra service.
 *
 * - MySQL/MariaDB: InnoDB FULLTEXT (boolean mode, prefix matching, title weighted ×3)
 * - Postgres: a stored tsvector (title weight A, body B) with a GIN index, ranked by ts_rank
 * - SQLite: an FTS5 table, ranked by bm25 (title ×10)
 * - otherwise (or when the full-text parts are missing): LIKE on title and body
 *
 * Words shorter than MySQL's minimum token size are matched with LIKE.
 * Good for thousands of entries; larger sites use Meilisearch or Typesense.
 */
final class DatabaseEngine implements Engine
{
    public const string TABLE = 'search_documents';
    public const string FTS = 'search_documents_fts';
    private const int MYSQL_MIN_TOKEN = 3;
    private const int FACET_SCAN = 5000;

    private ?string $mode = null;

    public function __construct(private ?Connection $connection = null, private readonly string $language = 'simple')
    {
        if (preg_match('/^[a-z_]{1,40}$/', $language) !== 1) {
            throw new SearchException('The text search language is a Postgres configuration name such as "simple" or "english".');
        }
    }

    public function name(): string
    {
        return 'database';
    }

    private function db(): Connection
    {
        return $this->connection ??= DatabaseManager::getConnection();
    }

    /**
     * The full-text parts the table needs on this database (called by the
     * migration; safe to run twice).
     */
    public static function install(Connection $db): void
    {
        $t = self::TABLE;
        match ($db->driver()) {
            'mysql' => self::tryAll($db, [
                "ALTER TABLE {$t} ADD FULLTEXT INDEX {$t}_ft (title, body)",
                "ALTER TABLE {$t} ADD FULLTEXT INDEX {$t}_ft_title (title)",
            ]),
            'pgsql' => self::tryAll($db, [
                "ALTER TABLE {$t} ADD COLUMN IF NOT EXISTS vector tsvector",
                "CREATE INDEX IF NOT EXISTS {$t}_vector ON {$t} USING GIN (vector)",
            ]),
            'sqlite' => self::tryAll($db, [
                'CREATE VIRTUAL TABLE IF NOT EXISTS ' . self::FTS . " USING fts5(title, body, tokenize='unicode61 remove_diacritics 2')",
            ]),
            default => null,
        };
    }

    public static function uninstall(Connection $db): void
    {
        if ($db->driver() === 'sqlite') {
            self::tryAll($db, ['DROP TABLE IF EXISTS ' . self::FTS]);
        }
    }

    /** @param list<string> $statements */
    private static function tryAll(Connection $db, array $statements): void
    {
        foreach ($statements as $sql) {
            try {
                $db->raw($sql);
            } catch (Throwable $e) {
                // already there (MySQL has no IF NOT EXISTS for indexes), or FTS5 isn't compiled in: LIKE still works
                if (!str_contains(strtolower($e->getMessage()), 'duplicate')) {
                    error_log('[search] ' . $e->getMessage());
                }
            }
        }
    }

    /** "mysql", "pgsql", "sqlite" (full-text available) or "like". */
    public function mode(): string
    {
        if ($this->mode !== null) {
            return $this->mode;
        }
        $db = $this->db();
        try {
            $this->mode = match ($db->driver()) {
                'mysql' => $db->select('SHOW INDEX FROM ' . self::TABLE . ' WHERE Key_name = ?', [self::TABLE . '_ft']) !== [] ? 'mysql' : 'like',
                'pgsql' => $db->select('SELECT 1 FROM information_schema.columns WHERE table_name = ? AND column_name = ?', [self::TABLE, 'vector']) !== [] ? 'pgsql' : 'like',
                'sqlite' => $db->select("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ?", [self::FTS]) !== [] ? 'sqlite' : 'like',
                default => 'like',
            };
        } catch (Throwable) {
            $this->mode = 'like';
        }

        return $this->mode;
    }

    // ── writing ─────────────────────────────────────────────────────

    public function index(array $documents): void
    {
        if ($documents === []) {
            return;
        }
        $db = $this->db();
        try {
            $db->transaction(function () use ($db, $documents): void {
                foreach ($documents as $doc) {
                    $doc = $doc->trimmed();
                    $row = [
                        'title' => $doc->title, 'body' => $doc->body, 'url' => $doc->url, 'type' => mb_substr($doc->type, 0, 32),
                        'locale' => $doc->locale, 'tags' => self::tagString($doc->tags), 'meta' => Json::encode((object) $doc->meta),
                        'published_at' => $doc->publishedAt,
                    ];
                    $existing = $db->table(self::TABLE)->where('collection', $doc->collection)->where('doc_id', $doc->id)->value('id');
                    if ($existing !== null) {
                        $db->table(self::TABLE)->where('id', $existing)->update($row);
                        $id = (int) $existing;
                    } else {
                        $id = (int) $db->table(self::TABLE)->insert($row + ['collection' => $doc->collection, 'doc_id' => $doc->id]);
                    }
                    $this->syncText($id, $doc);
                }
            });
        } catch (Throwable $e) {
            throw new SearchException('Could not index: ' . $e->getMessage(), previous: $e);
        }
    }

    private function syncText(int $id, SearchDocument $doc): void
    {
        $db = $this->db();
        match ($this->mode()) {
            'pgsql' => $db->execute(
                'UPDATE ' . self::TABLE . " SET vector = setweight(to_tsvector(CAST(? AS regconfig), title), 'A') || setweight(to_tsvector(CAST(? AS regconfig), body), 'B') WHERE id = ?",
                [$this->language, $this->language, $id],
            ),
            'sqlite' => (function () use ($db, $id, $doc): void {
                $db->execute('DELETE FROM ' . self::FTS . ' WHERE rowid = ?', [$id]);
                $db->execute('INSERT INTO ' . self::FTS . ' (rowid, title, body) VALUES (?, ?, ?)', [$id, $doc->title, $doc->body]);
            })(),
            default => null,
        };
    }

    public function remove(string $collection, array $ids): void
    {
        if ($ids === []) {
            return;
        }
        try {
            $rows = $this->db()->table(self::TABLE)->where('collection', $collection)->whereIn('doc_id', array_values($ids))->pluck('id');
            $this->deleteRows(array_map('intval', $rows));
        } catch (Throwable $e) {
            throw new SearchException('Could not remove: ' . $e->getMessage(), previous: $e);
        }
    }

    public function flush(string $collection): void
    {
        try {
            $rows = $this->db()->table(self::TABLE)->where('collection', $collection)->pluck('id');
            foreach (array_chunk(array_map('intval', $rows), 500) as $chunk) {
                $this->deleteRows($chunk);
            }
        } catch (Throwable $e) {
            throw new SearchException('Could not clear the index: ' . $e->getMessage(), previous: $e);
        }
    }

    /** @param list<int> $rows */
    private function deleteRows(array $rows): void
    {
        if ($rows === []) {
            return;
        }
        if ($this->mode() === 'sqlite') {
            $marks = implode(',', array_fill(0, count($rows), '?'));
            $this->db()->execute('DELETE FROM ' . self::FTS . " WHERE rowid IN ({$marks})", $rows);
        }
        $this->db()->table(self::TABLE)->whereIn('id', $rows)->delete();
    }

    // ── searching ───────────────────────────────────────────────────

    public function search(SearchQuery $query): SearchResults
    {
        $terms = $query->terms();
        if ($terms === []) {
            return new SearchResults([], 0, $query->page, $query->perPage, [], $this->name());
        }
        try {
            return $this->run($query, $terms, $this->mode());
        } catch (Throwable $e) {
            if ($this->mode() === 'like') {
                throw new SearchException('Search failed: ' . $e->getMessage(), previous: $e);
            }
            error_log('[search] full-text query failed, using LIKE: ' . $e->getMessage());

            return $this->run($query, $terms, 'like');
        }
    }

    /** @param list<string> $terms */
    private function run(SearchQuery $query, array $terms, string $mode): SearchResults
    {
        $db = $this->db();
        [$matchSql, $matchBind, $scoreSql, $scoreBind, $join] = $this->match($terms, $mode);

        // filters other than the match: collection, schedule, locale, tags
        $filters = ['d.collection = ?', '(d.published_at IS NULL OR d.published_at <= ?)'];
        $bind = [$query->collection, $query->now()];
        if ($query->locale !== '') {
            $filters[] = 'd.locale = ?';
            $bind[] = $query->locale;
        }
        $groups = $query->tagGroups();
        $tagSql = [];
        foreach ($groups as $name => $values) {
            [$sql, $b] = self::tagFilter($name, $values);
            $tagSql[$name] = [$sql, $b];
        }

        $where = static function (?string $without = null) use ($filters, $bind, $tagSql, $matchSql, $matchBind): array {
            $parts = [...$filters, $matchSql];
            $b = [...$bind, ...$matchBind];
            foreach ($tagSql as $name => [$sql, $tb]) {
                if ($name !== $without) {
                    $parts[] = $sql;
                    $b = [...$b, ...$tb];
                }
            }

            return [implode(' AND ', $parts), $b];
        };

        [$w, $wb] = $where();
        $from = self::TABLE . ' d' . $join;
        $total = (int) ($db->select("SELECT COUNT(*) AS n FROM {$from} WHERE {$w}", $wb)[0]['n'] ?? 0);
        $limit = $query->perPage;
        $offset = $query->offset();
        $rows = $total === 0 ? [] : $db->select(
            "SELECT d.doc_id, d.title, d.body, d.url, d.type, d.published_at, d.meta, {$scoreSql} AS score FROM {$from} WHERE {$w} ORDER BY score DESC, d.published_at DESC, d.id DESC LIMIT {$limit} OFFSET {$offset}",
            [...$scoreBind, ...$wb],
        );

        $hits = array_map(static fn(array $r) => new SearchHit(
            (string) $r['doc_id'],
            (string) $r['title'],
            $r['url'] !== null ? (string) $r['url'] : null,
            (string) $r['type'],
            $r['published_at'] !== null ? (int) $r['published_at'] : null,
            (array) (json_decode((string) ($r['meta'] ?? '{}'), true) ?: []),
            (string) $r['body'],
            (float) $r['score'],
        ), $rows);

        $facets = [];
        foreach ($query->facets as $name) {
            [$fw, $fb] = $where($name);
            $counts = [];
            foreach ($db->select("SELECT d.tags FROM {$from} WHERE {$fw} LIMIT " . self::FACET_SCAN, $fb) as $r) {
                foreach (explode(' ', trim((string) $r['tags'])) as $tag) {
                    if (str_starts_with($tag, $name . ':')) {
                        $value = substr($tag, strlen($name) + 1);
                        $counts[$value] = ($counts[$value] ?? 0) + 1;
                    }
                }
            }
            arsort($counts);
            $facets[$name] = $counts;
        }

        return new SearchResults($hits, $total, $query->page, $query->perPage, $facets, $this->name());
    }

    /**
     * The match condition and score for one dialect.
     *
     * @param list<string> $terms letters and digits only (SearchQuery::terms)
     * @return array{0: string, 1: list<mixed>, 2: string, 3: list<mixed>, 4: string}
     */
    private function match(array $terms, string $mode): array
    {
        $like = static function (array $terms): array {
            $sql = [];
            $bind = [];
            $score = [];
            $scoreBind = [];
            foreach ($terms as $term) {
                $pattern = '%' . self::escapeLike($term) . '%';
                $sql[] = "(LOWER(d.title) LIKE ? ESCAPE '!' OR LOWER(d.body) LIKE ? ESCAPE '!')";
                array_push($bind, $pattern, $pattern);
                $score[] = "(CASE WHEN LOWER(d.title) LIKE ? ESCAPE '!' THEN 3 ELSE 1 END)";
                $scoreBind[] = $pattern;
            }

            return [implode(' AND ', $sql), $bind, implode(' + ', $score), $scoreBind];
        };

        switch ($mode) {
            case 'mysql':
                $long = array_values(array_filter($terms, static fn($t) => mb_strlen($t) >= self::MYSQL_MIN_TOKEN));
                $short = array_values(array_diff($terms, $long));
                if ($long === []) {
                    return [...$like($terms), ''];
                }
                $expr = implode(' ', array_map(static fn($t) => '+' . $t . '*', $long));
                $sql = 'MATCH(d.title, d.body) AGAINST (? IN BOOLEAN MODE)';
                $bind = [$expr];
                $score = '(MATCH(d.title) AGAINST (? IN BOOLEAN MODE) * 3 + MATCH(d.title, d.body) AGAINST (? IN BOOLEAN MODE))';
                $scoreBind = [$expr, $expr];
                if ($short !== []) {
                    [$s, $b] = $like($short);
                    $sql .= " AND {$s}";
                    $bind = [...$bind, ...$b];
                }

                return [$sql, $bind, $score, $scoreBind, ''];
            case 'pgsql':
                $expr = implode(' & ', array_map(static fn($t) => $t . ':*', $terms));

                return [
                    'd.vector @@ to_tsquery(CAST(? AS regconfig), ?)', [$this->language, $expr],
                    'ts_rank(d.vector, to_tsquery(CAST(? AS regconfig), ?))', [$this->language, $expr], '',
                ];
            case 'sqlite':
                $expr = implode(' ', array_map(static fn($t) => '"' . $t . '"*', $terms));

                return [self::FTS . ' MATCH ?', [$expr], '-bm25(' . self::FTS . ', 10.0, 1.0)', [], ' JOIN ' . self::FTS . ' ON ' . self::FTS . '.rowid = d.id'];
            default:
                return [...$like($terms), ''];
        }
    }

    /** @param list<string> $values @return array{0: string, 1: list<string>} */
    private static function tagFilter(string $name, array $values): array
    {
        $parts = [];
        $bind = [];
        foreach ($values as $value) {
            $parts[] = "d.tags LIKE ? ESCAPE '!'";
            $bind[] = '% ' . self::escapeLike($name . ':' . $value) . ' %';
        }

        return ['(' . implode(' OR ', $parts) . ')', $bind];
    }

    private static function escapeLike(string $value): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
    }

    /** @param list<string> $tags stored space-delimited with edge spaces, so " name:value " matches whole tags */
    private static function tagString(array $tags): string
    {
        $s = ' ' . implode(' ', $tags) . ' ';

        return strlen($s) > 2000 ? substr($s, 0, (int) strrpos(substr($s, 0, 1999), ' ') + 1) : $s;
    }

    public function ping(): void
    {
        try {
            $this->db()->select('SELECT COUNT(*) AS n FROM ' . self::TABLE);
            if ($this->db()->driver() === 'pgsql') {
                $this->db()->select('SELECT to_tsvector(CAST(? AS regconfig), ?) AS v', [$this->language, 'probe']);
            }
        } catch (Throwable $e) {
            throw new SearchException($e->getMessage(), previous: $e);
        }
    }
}
