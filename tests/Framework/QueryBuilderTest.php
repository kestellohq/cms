<?php

declare(strict_types=1);

namespace Kestello\Tests\Framework;

use InvalidArgumentException;
use Kestello\Framework\Database\Connection;
use Kestello\Framework\Database\QueryBuilder;
use Kestello\Framework\Testing\TestCase;

final class QueryBuilderTest extends TestCase
{
    private function table(string $name): QueryBuilder
    {
        // Connection is lazy: building SQL never connects.
        return QueryBuilder::table($name, new Connection([]));
    }

    public function testIdentifiersAreQuotedSoReservedWordsWork(): void
    {
        $sql = $this->table('settings')->select('key', 'value')->where('key', 'LIKE', 'x%')->orderBy('order', 'desc')->toSql();

        $this->assertSame('SELECT `key`, `value` FROM `settings` WHERE `key` LIKE ? ORDER BY `order` DESC', $sql);
    }

    public function testExpressionsPassThroughUnchanged(): void
    {
        $sql = $this->table('users')->select('users.*', new \Kestello\Framework\Database\Expression('COUNT(*) as total'), '*')->join('posts', 'posts.user_id', '=', 'users.id')->toSql();

        $this->assertSame('SELECT `users`.*, COUNT(*) as total, * FROM `users` INNER JOIN `posts` ON `posts`.`user_id` = `users`.`id`', $sql);
    }

    public function testNamesThatAreNotNamesAreRefusedNotPassedThrough(): void
    {
        foreach (['id; DROP TABLE t', 'name) OR (1=1', 'COUNT(*) as total', 'a b', '`x`', 'a.b.c', ''] as $bad) {
            $this->assertThrows(InvalidArgumentException::class, fn() => $this->table('t')->orderBy($bad)->toSql(), $bad);
            $this->assertThrows(InvalidArgumentException::class, fn() => $this->table('t')->select($bad)->toSql(), $bad);
        }
        $this->assertThrows(InvalidArgumentException::class, fn() => $this->table('t')->where('x = 1 OR 1', 1)->toSql());
        $this->assertSame('SELECT `t`.`a` AS `b`, * FROM `t`', $this->table('t')->select('t.a as b', '*')->toSql());
    }

    public function testOperatorsAndJoinTypesAreAllowlisted(): void
    {
        $this->assertThrows(InvalidArgumentException::class, fn() => $this->table('t')->where('id', '= 1 OR 1=1 --', 'x'));
        $this->assertThrows(InvalidArgumentException::class, fn() => $this->table('t')->orWhere('id', 'UNION', 'x'));
        $this->assertThrows(InvalidArgumentException::class, fn() => $this->table('t')->join('u', 'a', '=', 'b', 'LEFT; DROP TABLE t'));
        $this->assertContains('`id` NOT LIKE ?', $this->table('t')->where('id', 'not like', 'x')->toSql());
    }

    public function testEscapeLike(): void
    {
        $this->assertSame('plugin.p\_x.100\%', QueryBuilder::escapeLike('plugin.p_x.100%'));
    }
}
