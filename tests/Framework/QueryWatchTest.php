<?php

declare(strict_types=1);

namespace Kestello\Tests\Framework;

use Kestello\Framework\Database\Connection;
use Kestello\Framework\Database\QueryWatch;
use Kestello\Framework\Log\Log;
use Kestello\Framework\Log\Logger;
use Kestello\Framework\Testing\TestCase;

/** Slow and repeated statements reach the log, as they happen on a real connection. */
final class QueryWatchTest extends TestCase
{
    private ?Logger $previous = null;
    private string $logs = '';
    private Connection $db;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previous = Log::logger();
        $this->logs = $this->tempDir();
        Log::use(new Logger($this->logs));
        $this->db = Connection::sqliteMemory();
        $this->db->raw('CREATE TABLE items (id INTEGER PRIMARY KEY, name TEXT)');
        foreach (range(1, 30) as $i) {
            $this->db->query('INSERT INTO items (name) VALUES (?)', ["item {$i}"]);
        }
    }

    protected function tearDown(): void
    {
        Log::use($this->previous);
        parent::tearDown();
    }

    /** @return list<array<string, mixed>> */
    private function logged(): array
    {
        $text = (string) @file_get_contents($this->logs . '/' . date('Y-m-d') . '.log');

        return array_map(static fn(string $l): array => json_decode($l, true), array_values(array_filter(explode("\n", $text))));
    }

    public function testListenersHearEachStatementWithoutItsValues(): void
    {
        $heard = [];
        $this->db->listen(static function (string $sql, float $ms) use (&$heard): void {
            $heard[] = [$sql, $ms >= 0];
        });
        $this->db->listen(static fn() => throw new \RuntimeException('a broken tracer'));
        $this->db->select('SELECT name FROM items WHERE id = ?', [7]);
        $this->db->table('items')->where('name', 'secret value')->first();
        $this->db->raw('CREATE INDEX idx_items_name ON items (name)');

        $this->assertSame('SELECT name FROM items WHERE id = ?', $heard[0][0]);
        $this->assertStringNotContainsString('secret value', $heard[1][0], 'placeholders, not values');
        $this->assertSame('CREATE INDEX idx_items_name ON items (name)', $heard[2][0]);
        $this->assertSame([true, true, true], array_column($heard, 1));
        $this->assertSame(3, count($heard), 'a listener that throws stops nothing');

        $this->assertThrows(\PDOException::class, fn() => $this->db->select('SELECT nope FROM missing_table'));
        $this->assertThrows(\PDOException::class, fn() => $this->db->raw('DROP TABLE missing_table'));
        $this->assertSame(['SELECT nope FROM missing_table', 'DROP TABLE missing_table'], array_column(array_slice($heard, 3), 0), 'failed statements are heard too: a lock wait that timed out is a slow one');
    }

    public function testASlowStatementIsLoggedAtOnce(): void
    {
        $watch = new QueryWatch(slowMs: 100);
        $watch->record('SELECT * FROM items', 12.0);
        $watch->record("SELECT *\n  FROM items WHERE id IN (?, ?, ?)", 250.4);
        $logged = $this->logged();
        $this->assertSame(1, count($logged));
        $this->assertSame(['warning', 'slow query: 250 ms'], [$logged[0]['level'], $logged[0]['message']]);
        $this->assertSame(['sql' => 'SELECT * FROM items WHERE id IN (?, …)', 'ms' => 250.4], $logged[0]['context']);

        (new QueryWatch(slowMs: 0))->record('SELECT 1', 99_999.0);
        $this->assertSame(1, count($this->logged()), '0 turns it off');
    }

    public function testAQueryPerItemOfAListIsReportedOnceWhenTheRequestEnds(): void
    {
        $watch = (new QueryWatch(slowMs: 0, repeats: 20))->watch($this->db);
        $ids = array_column($this->db->select('SELECT id FROM items'), 'id');
        foreach ($ids as $id) {
            $this->db->select('SELECT name FROM items WHERE id = ?', [$id]);   // the N+1
        }
        $this->db->select('SELECT COUNT(*) FROM items WHERE id IN (' . implode(', ', array_fill(0, 3, '?')) . ')', [1, 2, 3]);
        $this->assertSame([], $this->logged(), 'nothing until the request ends');
        $this->assertSame(32, $watch->totals()['queries']);

        $this->assertSame(['SELECT name FROM items WHERE id = ?' => 30], $watch->report());
        $logged = $this->logged();
        $this->assertSame(1, count($logged));
        $this->assertStringStartsWith('the same query ran 30 times in one request', $logged[0]['message']);
        $this->assertSame(['sql' => 'SELECT name FROM items WHERE id = ?', 'times' => 30, 'queries' => 32], array_intersect_key($logged[0]['context'], ['sql' => 1, 'times' => 1, 'queries' => 1]));

        $this->assertSame(['queries' => 0, 'ms' => 0.0], $watch->totals(), 'the next request starts again');
        $this->assertSame([], $watch->report());
    }

    public function testListsOfDifferentLengthsAreTheSameStatement(): void
    {
        $watch = new QueryWatch(slowMs: 0, repeats: 3);
        foreach ([2, 3, 5] as $n) {
            $watch->record('SELECT * FROM items WHERE id IN (' . implode(',', array_fill(0, $n, '?')) . ')', 1.0);
        }
        $this->assertSame(['SELECT * FROM items WHERE id IN (?)' => 1, 'SELECT * FROM items WHERE id IN (?, …)' => 2], array_count_values(array_map(QueryWatch::shape(...), [
            'SELECT * FROM items WHERE id IN (?)', 'SELECT * FROM items WHERE id IN (?,?)', 'SELECT * FROM items WHERE id IN (?, ?, ?)',
        ])));
        $this->assertSame(['SELECT * FROM items WHERE id IN (?, …)' => 3], $watch->report(), 'three lists of different lengths: one statement, three times');
        $this->assertSame(1000, strlen(QueryWatch::shape('SELECT ' . str_repeat('x', 5000))), 'cut to a length a log line holds');
    }

    public function testFewerRepeatsAndLongRunningCommandsReportNothing(): void
    {
        $watch = (new QueryWatch(slowMs: 0, repeats: 40))->watch($this->db);
        foreach (range(1, 30) as $id) {
            $this->db->select('SELECT name FROM items WHERE id = ?', [$id]);
        }
        $this->assertSame([], $watch->report(), 'under the limit');

        $worker = (new QueryWatch(slowMs: 0, repeats: 5))->withoutRepeats()->watch($this->db);
        foreach (range(1, 30) as $id) {
            $this->db->select('SELECT name FROM items WHERE id = ?', [$id]);
        }
        $this->assertSame([], $worker->report());
        $this->assertSame([], $this->logged());
    }

    public function testTheEnvironmentSetsTheLimits(): void
    {
        $limits = static function (QueryWatch $w): array {
            $slow = new \ReflectionProperty($w, 'slowMs');
            $repeats = new \ReflectionProperty($w, 'repeats');

            return [$slow->getValue($w), $repeats->getValue($w)];
        };
        $this->assertSame([500.0, 20], $limits(QueryWatch::fromEnvironment([])));
        $this->assertSame([150.0, 0], $limits(QueryWatch::fromEnvironment(['KESTELLO_SLOW_QUERY_MS' => '150', 'KESTELLO_REPEATED_QUERIES' => '0'])));
        $this->assertSame([500.0, 20], $limits(QueryWatch::fromEnvironment(['KESTELLO_SLOW_QUERY_MS' => 'fast', 'KESTELLO_REPEATED_QUERIES' => 'many'])), 'what is not a number is ignored');
        $this->assertSame([0.0, 0], $limits(QueryWatch::fromEnvironment(['KESTELLO_SLOW_QUERY_MS' => '-5', 'KESTELLO_REPEATED_QUERIES' => '-1'])));
    }
}
