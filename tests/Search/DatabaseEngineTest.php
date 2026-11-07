<?php

declare(strict_types=1);

namespace Kestello\Tests\Search;

use Kestello\Cms\Search\DatabaseEngine;
use Kestello\Contracts\Search\SearchDocument;
use Kestello\Contracts\Search\SearchQuery;
use Kestello\Framework\Database\Connection;
use Kestello\Framework\Database\DatabaseManager;
use Kestello\Framework\Testing\TestCase;

/** The database search engine on SQLite (FTS5), plus MySQL and Postgres from KESTELLO_TEST_DATABASES. */
final class DatabaseEngineTest extends TestCase
{
    /** @return array<string, Connection> */
    private function connections(): array
    {
        $out = ['sqlite' => Connection::sqliteMemory()];
        foreach (json_decode((string) getenv('KESTELLO_TEST_DATABASES'), true) ?: [] as $config) {
            $c = new Connection($config);
            $out[$c->driver()] = $c;
        }

        return $out;
    }

    /** @param callable(DatabaseEngine, string, Connection): void $test */
    private function each(callable $test, bool $fulltext = true): void
    {
        $migration = require dirname(__DIR__, 2) . '/core/database/migrations/2026_11_05_000006_create_search_documents_table.php';
        foreach ($this->connections() as $name => $c) {
            DatabaseManager::setConnection($c);
            $migration->down();
            $migration->up();
            if (!$fulltext) {
                DatabaseEngine::uninstall($c);
                if ($name !== 'sqlite') {
                    $migration->down();
                    $c->schema()->create(DatabaseEngine::TABLE, function ($t) {
                        $t->id();
                        $t->string('collection', 32);
                        $t->string('doc_id', 64);
                        $t->string('title', 255)->default('');
                        $t->mediumText('body')->nullable();
                        $t->string('url', 500)->nullable();
                        $t->string('type', 32)->default('');
                        $t->string('locale', 12)->default('');
                        $t->string('tags', 2000)->default('');
                        $t->text('meta')->nullable();
                        $t->bigInteger('published_at')->nullable();
                    });
                }
            }
            try {
                $test(new DatabaseEngine($c), $name, $c);
            } finally {
                $migration->down();
                DatabaseManager::reset();
            }
        }
    }

    private static function docs(): array
    {
        $t = time();
        return [
            new SearchDocument('content', 'entry:1', 'Harbour lights at night', 'The old harbour glows after sunset. Boats rest.', '/blog/harbour', 'post', $t - 300, ['type:post', 'category:travel'], ['excerpt' => 'Glow']),
            new SearchDocument('content', 'entry:2', 'Visiting the lighthouse', 'A walk to the harbour lighthouse along the pier.', '/blog/lighthouse', 'post', $t - 200, ['type:post', 'category:walks']),
            new SearchDocument('content', 'entry:3', 'About us', 'We write about harbours, boats and the sea.', '/about', 'page', $t - 100, ['type:page']),
            new SearchDocument('content', 'entry:4', 'Harbour festival', 'Next month at the harbour.', '/blog/festival', 'post', $t + 86400, ['type:post']),
            new SearchDocument('products', 'p:1', 'Harbour print', 'A poster.', '/shop/print', 'product', $t - 50),
        ];
    }

    public function testMatchesRanksFiltersAndCountsOnEveryDatabase(): void
    {
        $this->each(function (DatabaseEngine $engine, string $db): void {
            $this->assertSame($db === 'sqlite' ? 'sqlite' : $db, $engine->mode(), "{$db}: full-text installed");
            $engine->index(self::docs());

            $r = $engine->search(new SearchQuery('content', 'harbour', facets: ['type', 'category']));
            $ids = array_map(static fn($h) => $h->id, $r->hits);
            $this->assertSame(3, $r->total, "{$db}: the scheduled post and other collections are left out");
            $this->assertSame('entry:1', $ids[0], "{$db}: a title match ranks first");
            $this->assertNotContains('entry:4', $ids, $db);
            $this->assertSame(['post' => 2, 'page' => 1], $r->facets['type'], $db);
            $this->assertSame('Glow', $r->hits[0]->meta['excerpt'], $db);
            $this->assertSame('database', $r->engine);

            $this->assertSame(['entry:2'], array_map(static fn($h) => $h->id, $engine->search(new SearchQuery('content', 'harb light', ['category:walks']))->hits), "{$db}: prefixes, every word, tags");
            $this->assertSame(2, $engine->search(new SearchQuery('content', 'harbour', ['type:post', 'type:page'], facets: ['type']))->facets['type']['post'] ?? 0, "{$db}: a facet ignores its own filter");
            $this->assertSame(1, $engine->search(new SearchQuery('content', 'harbour', ['type:page']))->total, $db);
            $this->assertSame(0, $engine->search(new SearchQuery('content', '"; DROP TABLE x; -- +(*)'))->total, "{$db}: operators are stripped");
            $this->assertSame(0, $engine->search(new SearchQuery('content', '   '))->total);
            $this->assertSame(['entry:1'], array_map(static fn($h) => $h->id, $engine->search(new SearchQuery('content', 'harbour', perPage: 1))->hits));
            $second = $engine->search(new SearchQuery('content', 'harbour', page: 2, perPage: 1));
            $this->assertTrue(in_array($second->hits[0]->id, ['entry:2', 'entry:3'], true) && $second->lastPage() === 3, "{$db}: pages");
            $this->assertSame(1, $engine->search(new SearchQuery('content', 'harbour festival', now: time() + 2 * 86400))->total, "{$db}: due once its time comes");
            $this->assertSame(1, $engine->search(new SearchQuery('content', 'we sea'))->total, "{$db}: short words match too");

            $engine->index([new SearchDocument('content', 'entry:1', 'Night market', 'Stalls and food.', '/blog/market', 'post', time() - 10, ['type:post'])]);
            $this->assertSame(2, $engine->search(new SearchQuery('content', 'harbour'))->total, "{$db}: re-indexing replaces");
            $this->assertSame(1, $engine->search(new SearchQuery('content', 'market'))->total, $db);
            $engine->remove('content', ['entry:2']);
            $this->assertSame(1, $engine->search(new SearchQuery('content', 'harbour'))->total, "{$db}: removed");
            $engine->flush('content');
            $this->assertSame(0, $engine->search(new SearchQuery('content', 'market'))->total);
            $this->assertSame(1, $engine->search(new SearchQuery('products', 'harbour'))->total, "{$db}: flush is per collection");
            $engine->ping();
        });
    }

    public function testLikeFallbackWithoutFullText(): void
    {
        $this->each(function (DatabaseEngine $engine, string $db): void {
            $this->assertSame('like', $engine->mode(), $db);
            $engine->index(self::docs());
            $r = $engine->search(new SearchQuery('content', 'HARB light'));
            $this->assertSame(['entry:1', 'entry:2'], array_map(static fn($h) => $h->id, $r->hits), "{$db}: case-insensitive, more title matches first");
            $this->assertSame(0, $engine->search(new SearchQuery('content', '100%_x'))->total, "{$db}: LIKE wildcards are escaped");
        }, fulltext: false);
    }
}
