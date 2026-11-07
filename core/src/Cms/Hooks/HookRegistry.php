<?php

declare(strict_types=1);

namespace Kestello\Cms\Hooks;

use InvalidArgumentException;
use Kestello\Framework\Core\ExecutionContext;
use Kestello\Framework\Events\EventDispatcher;
use Kestello\Framework\Events\Filters;
use Kestello\Framework\Events\StoppableEvent;
use ReflectionClass;
use ReflectionNamedType;

/**
 * Every event and filter other code may rely on, with the version it
 * appeared in. `bin/cms hooks:list` and the hook reference in the docs are
 * built from it, and a core test fails when core dispatches an event or
 * applies a filter that is not declared here, so the reference cannot go
 * stale.
 *
 * Plugins declare the hooks they offer to other plugins:
 *
 *   $cms->hooks->event(StockChanged::class, since: '1.0.0', group: 'Stock');
 *   $cms->hooks->filter('catalog.price_label', since: '1.1.0', value: 'string',
 *       arguments: ['float $price' => 'The price', 'string $currency' => 'ISO code'],
 *       description: 'The price as shown on product cards.');
 *
 * An event's description and properties come from its class: the first
 * paragraph of the class docblock, and the constructor's promoted
 * properties with their @param descriptions.
 *
 * @api
 */
final class HookRegistry
{
    private const string VERSION = '/^\d+\.\d+\.\d+$/';

    /** @var array<string, Hook> kind:name => hook */
    private array $hooks = [];

    private ?Filters $filters = null;

    /** @internal Cms: lets a deprecated filter with a replacement forward to it. */
    public function connect(Filters $filters): void
    {
        $this->filters = $filters;
    }

    /** Declare an event class. */
    public function event(string $class, string $since, string $group = 'General', ?array $deprecated = null): void
    {
        if (!class_exists($class)) {
            throw new InvalidArgumentException("Event class {$class} does not exist.");
        }
        $owner = ExecutionContext::current();
        $this->assertOwner($owner, $class, Hook::EVENT);
        $ref = new ReflectionClass($class);
        [$summary, $params] = self::docblock((string) $ref->getDocComment(), (string) ($ref->getConstructor()?->getDocComment() ?: ''));
        $properties = [];
        foreach ($ref->getConstructor()?->getParameters() ?? [] as $p) {
            if ($p->isPromoted()) {
                $type = self::typeName($p->getType());
                $doc = $params[$p->getName()] ?? ['type' => '', 'description' => ''];
                if (in_array(ltrim($type, '?'), ['array', 'mixed'], true) && $doc['type'] !== '') {
                    $type = $doc['type'];         // list<string> says more than array
                }
                $properties[$p->getName()] = ['type' => $type, 'description' => $doc['description']];
            }
        }
        $this->add(new Hook(
            Hook::EVENT,
            ltrim($class, '\\'),
            $owner,
            $since,
            $group,
            $summary,
            properties: $properties,
            stoppable: $ref->implementsInterface(StoppableEvent::class) || $ref->hasMethod('cancel'),
            deprecated: self::deprecation($deprecated),
        ));
    }

    /**
     * Declare a filter that this code applies.
     *
     * @param array<string, string> $arguments "type $name" => description, in the order the callback receives them after the value
     * @param array{since: string, use?: ?string, removal?: ?string}|null $deprecated
     */
    public function filter(string $name, string $since, string $value, string $description, array $arguments = [], string $group = 'General', ?array $deprecated = null): void
    {
        if (preg_match('/^[a-z][a-z0-9_]*(\.([a-z0-9_]+|\{[a-z_]+\}))+$|^robots\.txt$/', $name) !== 1) {
            throw new InvalidArgumentException("Filter name \"{$name}\" must be dotted lowercase words, such as \"acme.share_text\".");
        }
        $owner = ExecutionContext::current();
        $this->assertOwner($owner, $name, Hook::FILTER);
        $hook = new Hook(Hook::FILTER, $name, $owner, $since, $group, $description, $value, $arguments, deprecated: self::deprecation($deprecated));
        $this->add($hook);
        if ($hook->deprecated['use'] ?? null) {
            $this->filters?->alias($name, $hook->deprecated['use']);   // callbacks on the old name keep running
        }
    }

    /** @return list<Hook> events first, each kind by owner (core first), group and name */
    public function all(?string $kind = null): array
    {
        $list = array_values(array_filter($this->hooks, static fn(Hook $h) => $kind === null || $h->kind === $kind));
        usort($list, static fn(Hook $a, Hook $b) => [$a->kind, $a->owner !== 'core', $a->owner, $a->group, $a->shortName()] <=> [$b->kind, $b->owner !== 'core', $b->owner, $b->group, $b->shortName()]);

        return $list;
    }

    public function get(string $kind, string $name): ?Hook
    {
        if (isset($this->hooks[$kind . ':' . strtolower($name)])) {
            return $this->hooks[$kind . ':' . strtolower($name)];
        }
        foreach ($this->hooks as $hook) {
            if ($hook->kind === $kind && $hook->matches($name)) {
                return $hook;
            }
        }

        return null;
    }

    /**
     * Which packages listen to a deprecated hook, for Site Health and
     * `hooks:list --deprecated`.
     *
     * @return list<array{owner: string, hook: Hook, as: string}>
     */
    public function deprecatedUses(Filters $filters, EventDispatcher $events): array
    {
        $found = [];
        foreach ([Hook::FILTER => $filters->uses(), Hook::EVENT => $events->uses()] as $kind => $uses) {
            foreach ($uses as $name => $owners) {
                $hook = $this->get($kind, $name);
                if ($hook?->deprecated === null) {
                    continue;
                }
                foreach ($owners as $owner) {
                    if ($owner !== 'core' && $owner !== $hook->owner) {
                        $found[] = ['owner' => $owner, 'hook' => $hook, 'as' => $name];
                    }
                }
            }
        }

        return $found;
    }

    /**
     * Filters that something listens to but no package declares: usually a
     * typo, or a plugin that is not active.
     *
     * @return array<string, list<string>> name => owners
     */
    public function undeclaredUses(Filters $filters): array
    {
        $found = [];
        foreach ($filters->uses() as $name => $owners) {
            if ($this->get(Hook::FILTER, $name) === null) {
                $found[$name] = $owners;
            }
        }
        ksort($found);

        return $found;
    }

    private function add(Hook $hook): void
    {
        if (preg_match(self::VERSION, $hook->since) !== 1) {
            throw new InvalidArgumentException("Hook {$hook->name}: since must be a version such as 1.2.0.");
        }
        $key = $hook->kind . ':' . strtolower($hook->name);
        if (isset($this->hooks[$key])) {
            throw new InvalidArgumentException("The {$hook->kind} {$hook->name} is already declared by {$this->hooks[$key]->owner}.");
        }
        $this->hooks[$key] = $hook;
    }

    /** A plugin or theme declares hooks under its own name: filters "<slug>.", events in its own classes. */
    private function assertOwner(string $owner, string $name, string $kind): void
    {
        if ($owner === 'core') {
            return;
        }
        $slug = substr($owner, (int) strpos($owner, ':') + 1);
        if ($kind === Hook::FILTER && !str_starts_with($name, $slug . '.')) {
            throw new InvalidArgumentException("Filters declared by {$owner} start with \"{$slug}.\", not \"{$name}\".");
        }
        if ($kind === Hook::EVENT && preg_match('/^\\\\?Kestello\\\\(Cms|Framework|Contracts)\\\\/', $name) === 1) {
            throw new InvalidArgumentException("{$owner} cannot declare core's event {$name}.");
        }
    }

    /** @return array{since: string, use: ?string, removal: ?string}|null */
    private static function deprecation(?array $d): ?array
    {
        if ($d === null) {
            return null;
        }
        $since = (string) ($d['since'] ?? '');
        $removal = isset($d['removal']) ? (string) $d['removal'] : null;
        if (preg_match(self::VERSION, $since) !== 1 || ($removal !== null && preg_match(self::VERSION, $removal) !== 1)) {
            throw new InvalidArgumentException('A deprecation needs since (and optionally removal) as versions such as 1.2.0.');
        }

        return ['since' => $since, 'use' => isset($d['use']) ? (string) $d['use'] : null, 'removal' => $removal];
    }

    /**
     * The summary (first paragraph, without tags) and @param descriptions.
     *
     * @return array{0: string, 1: array<string, array{type: string, description: string}>}
     */
    private static function docblock(string $class, string $constructor): array
    {
        $lines = static fn(string $doc): array => array_map(static fn(string $l) => trim(preg_replace('/^\s*\/?\*+\/?\s?/', '', $l) ?? ''), explode("\n", trim($doc)));
        $summary = [];
        foreach ($lines($class) as $line) {
            $line = trim(str_replace('*/', '', $line));
            if (str_starts_with($line, '@')) {
                break;
            }
            if ($line === '') {
                if ($summary !== []) {
                    break;
                }
                continue;
            }
            $summary[] = $line;
        }
        $params = [];
        $current = null;
        foreach ($lines($constructor) as $line) {
            $line = trim(str_replace('*/', '', $line));
            if (preg_match('/^@param\s+(.+?)\s+\$(\w+)\s*(.*)$/', $line, $m) === 1) {
                $current = $m[2];
                $params[$current] = ['type' => $m[1], 'description' => $m[3]];
            } elseif ($current !== null && $line !== '' && !str_starts_with($line, '@')) {
                $params[$current]['description'] .= ' ' . $line;
            } else {
                $current = null;
            }
        }

        return [implode(' ', $summary), array_map(static fn(array $p) => ['type' => $p['type'], 'description' => trim($p['description'])], $params)];
    }

    private static function typeName(?\ReflectionType $type): string
    {
        if ($type instanceof ReflectionNamedType) {
            $name = $type->isBuiltin() ? $type->getName() : substr(strrchr('\\' . $type->getName(), '\\'), 1);

            return ($type->allowsNull() && $type->getName() !== 'mixed' && $type->getName() !== 'null' ? '?' : '') . $name;
        }

        return $type === null ? 'mixed' : (string) $type;
    }
}
