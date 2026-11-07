<?php

declare(strict_types=1);

namespace Kestello\Tests\Helpers;

use Kestello\Framework\Testing\TestCase;
use Kestello\Support\Arr;
use Kestello\Support\Collection;
use Kestello\Support\LazyCollection;

use function Kestello\{blank, collect, data_get, rescue, retry};

final class ArrCollectionTest extends TestCase
{
    private const array USERS = [
        ['id' => 1, 'name' => 'Ana', 'role' => 'admin', 'age' => 31, 'address' => ['city' => 'Novi Sad']],
        ['id' => 2, 'name' => 'Bojan', 'role' => 'editor', 'age' => 25, 'address' => ['city' => 'Beograd']],
        ['id' => 3, 'name' => 'Cveta', 'role' => 'editor', 'age' => 40, 'address' => ['city' => 'Niš']],
    ];

    public function testDotNotation(): void
    {
        $config = ['mail' => ['from' => ['address' => 'a@x.test']], 'flag' => false, 'nil' => null];

        $this->assertSame('a@x.test', Arr::get($config, 'mail.from.address'));
        $this->assertSame('d', Arr::get($config, 'mail.to', 'd'));
        $this->assertSame('lazy', Arr::get($config, 'missing', fn() => 'lazy'));
        $this->assertFalse(Arr::get($config, 'flag', true), 'falsy values are not defaults');
        $this->assertNull(Arr::get($config, 'nil', 'd'), 'null present');
        $this->assertSame(['Novi Sad', 'Beograd', 'Niš'], Arr::get(self::USERS, '*.address.city'));
        $this->assertSame('Ana', data_get((object) ['user' => (object) ['name' => 'Ana']], 'user.name'));

        $this->assertTrue(Arr::has($config, 'mail.from.address', 'flag'));
        $this->assertFalse(Arr::has($config, 'mail.from.name'));

        $set = Arr::set([], 'a.b.c', 1);
        $this->assertSame(['a' => ['b' => ['c' => 1]]], $set);
        $this->assertSame(['a' => ['b' => []]], Arr::forget($set, 'a.b.c'));
        $this->assertSame(['a.b.c' => 1, 'x' => 2], Arr::dot($set + ['x' => 2]));
        $this->assertSame($set, Arr::undot(['a.b.c' => 1]));
    }

    public function testArrayHelpers(): void
    {
        $this->assertSame(['a' => 1], Arr::only(['a' => 1, 'b' => 2], 'a'));
        $this->assertSame(['b' => 2], Arr::except(['a' => 1, 'b' => 2], ['a']));
        $this->assertSame([1 => 'Ana', 2 => 'Bojan', 3 => 'Cveta'], Arr::pluck(self::USERS, 'name', 'id'));
        $this->assertSame(['admin', 'editor'], array_keys(Arr::groupBy(self::USERS, 'role')));
        $this->assertSame([1, 2, 3, 4], Arr::flatten([1, [2, [3, [4]]]]));
        $this->assertSame([1, 2, [3]], Arr::flatten([1, [2, [3]]], 1));
        $this->assertSame([], Arr::wrap(null));
        $this->assertSame(['x'], Arr::wrap('x'));
        $this->assertSame(2, Arr::first([1, 2, 3], fn($v) => $v > 1));
        $this->assertSame(3, Arr::last([1, 2, 3]));
        $this->assertSame(['Bojan', 'Ana', 'Cveta'], array_column(Arr::sortBy(self::USERS, 'age'), 'name'));
        $this->assertSame(['Ana', 'Cveta', 'Bojan'], array_column(Arr::sortBy(self::USERS, ['role' => 'asc', 'age' => 'desc']), 'name'));
        [$adults, $young] = Arr::partition([15, 20, 30], fn($v) => $v >= 18);
        $this->assertSame([[1 => 20, 2 => 30], [0 => 15]], [$adults, $young]);
        $this->assertSame('a=1&b%5B0%5D=x&c=1', Arr::query(['a' => 1, 'b' => ['x'], 'skip' => null, 'c' => true]));
        $this->assertTrue(Arr::isAssoc(['a' => 1]) && Arr::isList([1, 2]));
        $this->assertCount(3, Arr::shuffle([1, 2, 3]));
        $this->assertContains(Arr::random([1, 2, 3]), [1, 2, 3]);
        $this->assertThrows(\InvalidArgumentException::class, fn() => Arr::random([]));
    }

    public function testCollectionIsImmutable(): void
    {
        $c = collect([3, 1, 2]);
        $sorted = $c->sort()->values();

        $this->assertSame([3, 1, 2], $c->all(), 'original unchanged');
        $this->assertSame([1, 2, 3], $sorted->all());
        $this->assertThrows(\LogicException::class, function () use ($c) {
            $c[0] = 9;
        });
        $this->assertSame([3, 1, 2, 4], $c->push(4)->all());
        $this->assertSame([3, 1, 2], $c->all());
    }

    public function testCollectionPipelines(): void
    {
        $users = collect(self::USERS);

        $this->assertSame(['Bojan', 'Cveta'], $users->where('role', 'editor')->pluck('name')->values()->all());
        $this->assertSame(['Cveta'], $users->where('age', '>', 30)->where('role', '!=', 'admin')->pluck('name')->values()->all());
        $this->assertSame(['Cveta', 'Ana', 'Bojan'], $users->sortByDesc('age')->pluck('name')->values()->all());
        $this->assertSame(['admin' => 1, 'editor' => 2], $users->countBy('role')->all());
        $this->assertSame(['Bojan', 'Cveta'], $users->groupBy('role')->get('editor')->pluck('name')->all());
        $this->assertSame(96, $users->sum('age'));
        $this->assertSame(32, $users->avg('age'));
        $this->assertSame(31, $users->median('age'));
        $this->assertSame(40, $users->max('age'));
        $this->assertSame('Ana, Bojan and Cveta', $users->pluck('name')->join(', ', ' and '));
        $this->assertSame([2 => 'Bojan'], $users->keyBy('id')->map(fn($u) => $u['name'])->only(2)->all());
        $this->assertSame([[1, 2], [3]], collect([1, 2, 3])->chunk(2)->map(fn($c) => $c->values()->all())->all());
        $this->assertSame([1, 2], collect([1, 2, 2, 1])->unique()->values()->all());
        $this->assertSame(['Ana', 'Bojan'], $users->unique('role')->pluck('name')->values()->all());
        $this->assertSame([[1, 'a'], [2, 'b']], collect([1, 2])->zip(['a', 'b'])->toArray());
        $this->assertSame([2, 4, 6], Collection::times(3, fn($i) => $i * 2)->all());
        $this->assertSame([1, 2], collect([1, 2, 3, 1])->takeWhile(fn($v) => $v < 3)->all());
        $this->assertTrue($users->every(fn($u) => $u['age'] > 18));
        $this->assertSame('Ana', $users->sole(fn($u) => $u['role'] === 'admin')['name']);
        $this->assertThrows(\LogicException::class, fn() => $users->sole(fn($u) => $u['role'] === 'editor'));
        $this->assertSame('[1,2]', collect([1, 2])->toJson());
        $this->assertSame([2, 3], collect([1, 2])->when(true, fn($c) => $c->map(fn($v) => $v + 1))->all());
    }

    public function testLazyCollectionStreams(): void
    {
        $produced = 0;
        $source = LazyCollection::make(function () use (&$produced) {
            for ($i = 1; $i <= 1_000_000; $i++) {
                $produced++;
                yield $i;
            }
        });

        $this->assertSame([2, 4, 6], $source->filter(fn($v) => $v % 2 === 0)->take(3)->values()->all());
        $this->assertSame(6, $produced, 'only what was needed was produced');

        $this->assertSame(55, LazyCollection::range(1, 10)->sum());
        $this->assertSame([[1, 2], [3, 4], [5]], LazyCollection::range(1, 5)->chunk(2)->map(fn($c) => $c->values()->all())->values()->all());
        $this->assertSame(3, LazyCollection::make([1, 1, 2, 3, 3])->unique()->count());
        $this->assertSame([3, 4], LazyCollection::range(1, 4)->skipWhile(fn($v) => $v < 3)->values()->all());
        $this->assertSame([1, 2], collect([1, 2])->lazy()->collect()->all());
    }

    public function testHelperFunctions(): void
    {
        $attempts = 0;
        $result = retry(3, function (int $attempt) use (&$attempts) {
            $attempts = $attempt;
            if ($attempt < 3) {
                throw new \RuntimeException('flaky');
            }
            return 'ok';
        });
        $this->assertSame(['ok', 3], [$result, $attempts]);
        $this->assertThrows(\RuntimeException::class, fn() => retry(2, fn() => throw new \RuntimeException('down')));
        $this->assertThrows(\LogicException::class, fn() => retry(5, fn() => throw new \LogicException('fatal'), when: fn($e) => $e instanceof \RuntimeException));

        $this->assertSame('fallback', rescue(fn() => throw new \Exception('x'), 'fallback'));
        $this->assertTrue(blank('  ') && blank([]) && blank(null) && !blank(0) && !blank('0'));
    }
}
