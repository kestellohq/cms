<?php

declare(strict_types=1);

namespace Kestello\Cms\Auth;

use Kestello\Framework\Database\QueryBuilder;

/**
 * Roles edited in the admin (the `roles` table): per role, a label, an
 * optional description and the capabilities granted and revoked on top
 * of what code declares. Roles::applyStored() overlays them.
 *
 * @internal
 */
final class RoleStore
{
    /** @return array<string, array{label: string, description: ?string, custom: bool, granted: list<string>, revoked: list<string>}> */
    public static function load(): array
    {
        $out = [];
        foreach (QueryBuilder::table('roles')->get() as $row) {
            $out[(string) $row['name']] = [
                'label' => (string) $row['label'],
                'description' => $row['description'] === null ? null : (string) $row['description'],
                'custom' => (bool) $row['custom'],
                'granted' => self::list($row['granted']),
                'revoked' => self::list($row['revoked']),
            ];
        }

        return $out;
    }

    /**
     * Store a role so that its effective capabilities become $capabilities.
     *
     * @param list<string> $capabilities
     */
    public static function save(Roles $roles, string $name, string $label, ?string $description, array $capabilities, bool $custom): void
    {
        $defaults = $custom ? [] : ($roles->defaultsFor($name) ?? []);
        $capabilities = array_values(array_unique(array_filter($capabilities, static fn(string $c) => $c !== '*')));
        $now = gmdate('Y-m-d H:i:s');
        $row = [
            'label' => $label,
            'description' => $description,
            'custom' => $custom ? 1 : 0,
            'granted' => json_encode(array_values(array_diff($capabilities, $defaults))),
            'revoked' => json_encode(array_values(array_diff($defaults, $capabilities))),
            'updated_at' => $now,
        ];
        if (QueryBuilder::table('roles')->where('name', $name)->exists()) {
            QueryBuilder::table('roles')->where('name', $name)->update($row);
        } else {
            QueryBuilder::table('roles')->insert(['name' => $name, 'created_at' => $now] + $row);
        }
        $roles->applyStored(self::load());
    }

    /** Forget a role's changes (code roles go back to their defaults; custom roles disappear). */
    public static function forget(Roles $roles, string $name): void
    {
        QueryBuilder::table('roles')->where('name', $name)->delete();
        $roles->applyStored(self::load());
    }

    /** @return list<string> */
    private static function list(mixed $json): array
    {
        $list = is_string($json) ? json_decode($json, true) : null;

        return is_array($list) ? array_values(array_filter($list, 'is_string')) : [];
    }
}
