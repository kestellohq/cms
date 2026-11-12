<?php

declare(strict_types=1);

namespace Kestello\Cms\Api;

use Kestello\Contracts\Auth\Principal;
use Kestello\Framework\Core\ExecutionContext;
use Kestello\Framework\Log\Log;
use Kestello\Framework\Log\LogLevel;
use Throwable;

/**
 * Additive fields on a core (or another plugin's) resource.
 *
 *   $cms->api->extend('users')->field('acme_badge', fn(array $row, Principal $p) => ..., read: 'users.read');
 *
 * Extension fields never replace existing fields. A field whose resolver
 * throws is left out (and logged) instead of breaking the response.
 *
 * @api
 */
final class ResourceExtension
{
    /** @var array<string, array{resolver: callable, read: ?string, owner: string}> */
    private array $fields = [];

    public function __construct(public readonly string $resource) {}

    /**
     * @param callable(array<string, mixed>, Principal): mixed $resolver
     * @param string|null $read capability needed to see the field
     */
    public function field(string $name, callable $resolver, ?string $read = null): self
    {
        if (!preg_match('/^[a-z][a-z0-9_]*$/', $name)) {
            throw new \InvalidArgumentException("Invalid field name [{$name}].");
        }
        if (isset($this->fields[$name])) {
            throw new \InvalidArgumentException("Field [{$name}] on [{$this->resource}] is already added by {$this->fields[$name]['owner']}.");
        }

        $this->fields[$name] = ['resolver' => $resolver, 'read' => $read, 'owner' => ExecutionContext::current()];

        return $this;
    }

    /** @return array<string, array{resolver: callable, read: ?string, owner: string}> */
    public function fields(): array
    {
        return $this->fields;
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public function apply(array $data, array $row, Principal $principal): array
    {
        foreach ($this->fields as $name => $field) {
            if (array_key_exists($name, $data)) {
                continue; // never shadow a core field
            }
            if ($field['read'] !== null && !$principal->can($field['read'])) {
                continue;
            }

            try {
                $data[$name] = ExecutionContext::run($field['owner'], static fn() => ($field['resolver'])($row, $principal));
            } catch (Throwable $e) {
                Log::write(LogLevel::ERROR, "API field {$this->resource}.{$name} failed: " . $e->getMessage(), ['exception' => $e], (string) $field['owner']);
            }
        }

        return $data;
    }
}
