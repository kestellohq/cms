<?php

declare(strict_types=1);

namespace Kestello\Cms\Hooks;

/**
 * One declared event or filter, as the hook reference shows it.
 *
 * @api
 */
final readonly class Hook
{
    public const string EVENT = 'event';
    public const string FILTER = 'filter';

    /**
     * @param 'event'|'filter' $kind
     * @param string $name the event class, or the filter name ("{resource}" marks a part that varies)
     * @param string $owner "core", "plugin:<slug>" or "theme:<slug>"
     * @param string $value filters: the type of the value being filtered
     * @param array<string, string> $arguments filters: "type $name" => what it is
     * @param array<string, array{type: string, description: string}> $properties events: property name => type and description
     * @param array{since: string, use: ?string, removal: ?string}|null $deprecated
     */
    public function __construct(
        public string $kind,
        public string $name,
        public string $owner,
        public string $since,
        public string $group,
        public string $description,
        public string $value = '',
        public array $arguments = [],
        public array $properties = [],
        public bool $stoppable = false,
        public ?array $deprecated = null,
    ) {}

    /** "EntryPublished" for an event, the name itself for a filter. */
    public function shortName(): string
    {
        return $this->kind === self::EVENT ? substr(strrchr('\\' . $this->name, '\\'), 1) : $this->name;
    }

    /** Whether a filter name as used in code is this hook ("api.response.users" is "api.response.{resource}"). */
    public function matches(string $name): bool
    {
        if ($this->kind === self::EVENT) {
            return strcasecmp(ltrim($name, '\\'), $this->name) === 0;
        }
        if (!str_contains($this->name, '{')) {
            return $name === $this->name;
        }
        $pattern = preg_replace('/\\\\\{[a-z_]+\\\\\}/', '[a-z0-9_.-]+', preg_quote($this->name, '/'));

        return preg_match('/^' . $pattern . '$/', $name) === 1;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind, 'name' => $this->name, 'owner' => $this->owner, 'since' => $this->since,
            'group' => $this->group, 'description' => $this->description,
            ...($this->kind === self::FILTER ? ['value' => $this->value, 'arguments' => (object) $this->arguments] : ['properties' => (object) $this->properties, 'stoppable' => $this->stoppable]),
            'deprecated' => $this->deprecated,
        ];
    }
}
