<?php

declare(strict_types=1);

namespace Kestello\Cms\Platform;

use Closure;
use Kestello\Cms\Api\Schema;

/**
 * One driver for a platform subsystem.
 *
 *   new DriverDefinition(
 *       name: 'redis',
 *       label: 'Redis',
 *       schema: Schema::object(['host' => Schema::string()->default('127.0.0.1'), 'password' => Schema::string()->optional()]),
 *       factory: fn(array $options, Container $c) => new RedisStore(...),
 *       secrets: ['password'],
 *       requires: ['ext' => ['sockets']],
 *       health: fn(RedisStore $store) => $store->ping(),   // throw on failure
 *   );
 *
 * @api
 */
final readonly class DriverDefinition
{
    /**
     * @param Closure(array<string, mixed>, \Kestello\Framework\Core\Container): object $factory
     * @param list<string> $secrets option names stored encrypted and never returned
     * @param array{ext?: list<string>, functions?: list<string>, php?: string} $requires
     * @param (Closure(object): mixed)|null $health throw (or return false) when unhealthy
     * @param list<string> $endpoints options holding the address of a service the admin runs (e.g. "url"):
     *                                the factory gets $options['clients'][$name], an HTTP client locked
     *                                to that address that may reach private networks (ServiceClient)
     */
    public function __construct(
        public string $name,
        public string $label,
        public Schema $schema,
        public Closure $factory,
        public array $secrets = [],
        public array $requires = [],
        public ?Closure $health = null,
        public string $description = '',
        public array $endpoints = [],
    ) {
        if ($schema->type() !== 'object') {
            throw new \InvalidArgumentException("Driver [{$name}] schema must be Schema::object().");
        }
        foreach ($endpoints as $endpoint) {
            if (!array_key_exists($endpoint, $schema->properties())) {
                throw new \InvalidArgumentException("Driver [{$name}] endpoint [{$endpoint}] is not in its schema.");
            }
        }
        foreach ($secrets as $secret) {
            if (!array_key_exists($secret, $schema->properties())) {
                throw new \InvalidArgumentException("Driver [{$name}] secret [{$secret}] is not in its schema.");
            }
        }
    }

    /** Why this driver can't run on this server, or null when it can. */
    public function unavailableReason(): ?string
    {
        foreach ($this->requires['ext'] ?? [] as $ext) {
            if (!extension_loaded($ext)) {
                return "Needs the PHP extension \"{$ext}\".";
            }
        }
        foreach ($this->requires['functions'] ?? [] as $function) {
            if (!function_exists($function)) {
                return "Needs the PHP function {$function}() (disabled on this server).";
            }
        }
        if (isset($this->requires['php']) && version_compare(PHP_VERSION, $this->requires['php'], '<')) {
            return "Needs PHP {$this->requires['php']} or newer.";
        }

        return null;
    }

    public function isSecret(string $option): bool
    {
        return in_array($option, $this->secrets, true);
    }
}
