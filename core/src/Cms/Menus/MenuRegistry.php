<?php

declare(strict_types=1);

namespace Kestello\Cms\Menus;

use InvalidArgumentException;
use Kestello\Framework\Core\ExecutionContext;

/**
 * Menu locations ($cms->menus): places in a theme where a menu goes.
 *
 *   $cms->menus->location('primary', 'Main navigation', 'Across the top of every page.');
 *
 * Pages get the menu assigned to each location as usePage().props.menus.primary:
 * a tree of { label, url, target, children }. Themes name their
 * locations freely; plugin locations are prefixed with the plugin slug
 * ("acme-shop.footer").
 *
 * @api
 */
final class MenuRegistry
{
    /** @var array<string, array{label: string, description: string, owner: string}> */
    private array $locations = [];

    public function location(string $name, string $label, string $description = ''): void
    {
        if (preg_match('/^[a-z0-9][a-z0-9_.-]{0,63}$/', $name) !== 1) {
            throw new InvalidArgumentException("Invalid menu location [{$name}].");
        }
        $owner = ExecutionContext::current();
        if (str_starts_with($owner, 'plugin:')) {
            $slug = substr($owner, 7);
            if (!str_starts_with($name, $slug . '.')) {
                throw new InvalidArgumentException("Menu locations of {$owner} must start with \"{$slug}.\".");
            }
        }
        $this->locations[$name] = ['label' => $label, 'description' => $description, 'owner' => $owner];
    }

    /** @return array<string, array{label: string, description: string, owner: string}> */
    public function locations(): array
    {
        return $this->locations;
    }

    public function has(string $name): bool
    {
        return isset($this->locations[$name]);
    }
}
