<?php

declare(strict_types=1);

namespace Kestello\Framework\Events;

use Closure;
use Kestello\Framework\Core\Container;
use Kestello\Framework\Core\ExecutionContext;
use RuntimeException;

/**
 * Typed events (actions).
 *
 *   $events->on(UserRegistered::class, SendWelcomeMail::class);        // class with handle() or __invoke()
 *   $events->on(UserRegistered::class, fn(UserRegistered $e) => ...);  // any callable
 *   $events->dispatch(new UserRegistered($user));
 *
 * Listeners run in priority order (higher first; equal priority keeps
 * registration order). Each listener runs as the extension that
 * registered it, for fault attribution. Listeners also match parent
 * classes and interfaces of the dispatched event.
 *
 * Listener classes marked #[Queued] are handed to the queuer (the job
 * queue) instead of running during dispatch.
 *
 * @api
 */
final class EventDispatcher
{
    /** @var array<string, list<array{listener: callable|string, priority: int, owner: string, seq: int}>> */
    private array $listeners = [];

    private int $seq = 0;

    /** @var array<string, array<string, true>> */
    private array $uses = [];

    /** @var (Closure(string, object, Queued): bool)|null */
    private ?Closure $queuer = null;

    /** @var array<class-string, Queued|false> */
    private array $queuedCache = [];

    public function __construct(
        private readonly ?Container $container = null,
    ) {}

    /**
     * @param class-string           $event
     * @param callable|class-string  $listener
     */
    public function on(string $event, callable|string $listener, int $priority = 0): void
    {
        $owner = ExecutionContext::current();
        $this->uses[$event][$owner] = true;
        // A renamed event class keeps its old name as a class_alias(); listen under the real name.
        if (class_exists($event) || interface_exists($event)) {
            $event = (new \ReflectionClass($event))->getName();
        }
        $this->listeners[$event][] = [
            'listener' => $listener,
            'priority' => $priority,
            'owner' => $owner,
            'seq' => $this->seq++,
        ];
    }

    /**
     * Who listens, by the class name they used.
     *
     * @return array<string, list<string>>
     */
    public function uses(): array
    {
        return array_map(static fn(array $owners) => array_keys($owners), $this->uses);
    }

    /**
     * @template T of object
     * @param T $event
     * @return T
     */
    public function dispatch(object $event): object
    {
        foreach ($this->listenersFor($event) as $entry) {
            if (is_string($entry['listener']) && $this->queuer !== null && ($queued = $this->queuedAttribute($entry['listener'])) !== null) {
                $queuer = $this->queuer;
                $listenerClass = $entry['listener'];
                if (ExecutionContext::run($entry['owner'], static fn() => $queuer($listenerClass, $event, $queued))) {
                    continue;
                }
            }

            $listener = $this->resolve($entry['listener']);

            ExecutionContext::run($entry['owner'], static fn() => $listener($event));

            if ($event instanceof StoppableEvent && $event->isPropagationStopped()) {
                break;
            }
        }

        return $event;
    }

    /**
     * @internal Kernel: who queues #[Queued] listeners. It returns false
     * when the event can't be queued; the listener then runs inline.
     *
     * @param Closure(string $listener, object $event, Queued $options): bool $queuer
     */
    public function setQueuer(?Closure $queuer): void
    {
        $this->queuer = $queuer;
    }

    /**
     * The owner of $listener when it is a queued listener of this event
     * (CallQueuedListener runs only listeners registered here).
     */
    public function queuedListenerOwner(object $event, string $listener): ?string
    {
        if ($this->queuedAttribute($listener) === null) {
            return null;
        }
        foreach ($this->listenersFor($event) as $entry) {
            if ($entry['listener'] === $listener) {
                return $entry['owner'];
            }
        }

        return null;
    }

    /** Run one listener for an event as its owner (used by queued listeners). */
    public function runListener(string $listener, object $event, string $owner): void
    {
        $callable = $this->resolve($listener);
        ExecutionContext::run($owner, static fn() => $callable($event));
    }

    private function queuedAttribute(string $listener): ?Queued
    {
        if (!array_key_exists($listener, $this->queuedCache)) {
            $attr = class_exists($listener) ? ((new \ReflectionClass($listener))->getAttributes(Queued::class)[0] ?? null) : null;
            $this->queuedCache[$listener] = $attr?->newInstance() ?? false;
        }

        return $this->queuedCache[$listener] ?: null;
    }

    public function hasListeners(string $event): bool
    {
        return !empty($this->listeners[$event]);
    }

    /**
     * @return list<array{listener: callable|string, priority: int, owner: string, seq: int}>
     */
    private function listenersFor(object $event): array
    {
        $types = [$event::class, ...array_values(class_parents($event) ?: []), ...array_values(class_implements($event) ?: [])];
        $matched = [];

        foreach ($types as $type) {
            foreach ($this->listeners[$type] ?? [] as $entry) {
                $matched[] = $entry;
            }
        }

        usort($matched, static fn(array $a, array $b): int => [$b['priority'], $a['seq']] <=> [$a['priority'], $b['seq']]);

        return $matched;
    }

    private function resolve(callable|string $listener): callable
    {
        if (is_callable($listener)) {
            return $listener;
        }

        if (!class_exists($listener)) {
            throw new RuntimeException("Event listener class [{$listener}] does not exist.");
        }

        $instance = $this->container?->make($listener) ?? new $listener();

        return match (true) {
            method_exists($instance, 'handle') => Closure::fromCallable([$instance, 'handle']),
            is_callable($instance) => $instance,
            default => throw new RuntimeException("Listener [{$listener}] needs a handle() or __invoke() method."),
        };
    }
}
