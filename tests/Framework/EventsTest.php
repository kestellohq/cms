<?php

declare(strict_types=1);

namespace Kestello\Tests\Framework;

use Kestello\Framework\Core\ExecutionContext;
use Kestello\Framework\Events\EventDispatcher;
use Kestello\Framework\Events\Filters;
use Kestello\Framework\Testing\TestCase;
use RuntimeException;

interface Greeting {}

final class Greeted implements Greeting
{
    /** @var list<string> */
    public array $log = [];
}

final class EventsTest extends TestCase
{
    protected function tearDown(): void
    {
        ExecutionContext::reset();
    }

    public function testListenersRunByPriorityThenRegistrationOrder(): void
    {
        $events = new EventDispatcher();
        $events->on(Greeted::class, fn(Greeted $e) => $e->log[] = 'a');
        $events->on(Greeted::class, fn(Greeted $e) => $e->log[] = 'high', priority: 10);
        $events->on(Greeted::class, fn(Greeted $e) => $e->log[] = 'b');
        $events->on(Greeting::class, fn(Greeted $e) => $e->log[] = 'interface');

        $this->assertSame(['high', 'a', 'b', 'interface'], $events->dispatch(new Greeted())->log);
    }

    public function testListenerFailureIsBlamedOnRegisteringExtension(): void
    {
        $events = new EventDispatcher();
        ExecutionContext::run('plugin:broken', fn() => $events->on(Greeted::class, function (): void {
            throw new RuntimeException('boom');
        }));

        $e = $this->assertThrows(RuntimeException::class, fn() => $events->dispatch(new Greeted()));
        $this->assertSame('plugin:broken', ExecutionContext::blamed($e));
        $this->assertSame('core', ExecutionContext::current(), 'stack must unwind after an exception');
    }

    public function testFiltersChainValuesWithArguments(): void
    {
        $filters = new Filters();
        $filters->add('title', fn(string $v, string $suffix) => $v . $suffix);
        $filters->add('title', fn(string $v) => strtoupper($v), priority: 5);

        $this->assertSame('HELLO!', $filters->apply('title', 'hello', '!'));
        $this->assertSame('x', $filters->apply('unknown', 'x'));
    }

    public function testStrictFiltersAllowNullableValues(): void
    {
        $filters = new Filters(strict: true);
        ExecutionContext::run('plugin:scanner', fn() => $filters->add('media.scan', fn(?string $verdict) => 'Infected.'));
        $this->assertSame('Infected.', $filters->apply('media.scan', null), 'a nullable value may become a string');
        ExecutionContext::run('plugin:other', fn() => $filters->add('media.scan', fn(?string $verdict) => null));
        $this->assertNull($filters->apply('media.scan', null));
    }

    public function testRenamedFiltersAndEventsKeepTheirOldListeners(): void
    {
        $filters = new Filters();
        $filters->alias('old.name', 'new.name');
        ExecutionContext::run('plugin:acme', fn() => $filters->add('old.name', fn(string $v) => $v . '!'));
        $this->assertSame('hi!', $filters->apply('new.name', 'hi'));
        $this->assertSame(['old.name' => ['plugin:acme']], $filters->uses());

        $events = new EventDispatcher();
        $old = 'Kestello\\Tests\\Framework\\OldEventName' . bin2hex(random_bytes(3));
        class_alias(RenamedEvent::class, $old);
        $seen = [];
        $events->on($old, function (RenamedEvent $e) use (&$seen) {
            $seen[] = $e;
        });
        $events->dispatch(new RenamedEvent());
        $this->assertCount(1, $seen, 'a listener on the old class name hears the renamed event');
        $this->assertSame([$old => ['core']], $events->uses());
    }

    public function testStrictFiltersRejectTypeChangesAndNameTheOwner(): void
    {
        $filters = new Filters(strict: true);
        ExecutionContext::run('plugin:sloppy', fn() => $filters->add('count', fn(int $v) => (string) $v));

        $e = $this->assertThrows(\UnexpectedValueException::class, fn() => $filters->apply('count', 1));
        $this->assertContains('plugin:sloppy', $e->getMessage());
    }
}

final class RenamedEvent {}
