<?php

declare(strict_types=1);

namespace Kestello\Cms\Extension;

use Kestello\Framework\Database\QueryBuilder;

/**
 * Package state in the `packages` table.
 */
final class DatabaseStateStore implements StateStore
{
    /** @var array<string, PackageState>|null */
    private ?array $cache = null;

    public function all(PackageType $type): array
    {
        return array_values(array_filter($this->load(), static fn(PackageState $s): bool => $s->type === $type));
    }

    public function get(PackageType $type, string $slug): ?PackageState
    {
        return $this->load()[$type->value . ':' . $slug] ?? null;
    }

    public function save(PackageState $state): void
    {
        $row = [
            'status' => $state->active ? 'active' : 'inactive',
            'version' => $state->version,
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        $exists = QueryBuilder::table('packages')
            ->where('type', $state->type->value)
            ->where('slug', $state->slug)
            ->first();

        if ($exists) {
            QueryBuilder::table('packages')->where('type', $state->type->value)->where('slug', $state->slug)->update($row);
        } else {
            QueryBuilder::table('packages')->insert($row + ['type' => $state->type->value, 'slug' => $state->slug]);
        }

        $this->cache = null;
    }

    public function delete(PackageType $type, string $slug): void
    {
        QueryBuilder::table('packages')->where('type', $type->value)->where('slug', $slug)->delete();
        $this->cache = null;
    }

    /**
     * @return array<string, PackageState>
     */
    private function load(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        $this->cache = [];

        foreach (QueryBuilder::table('packages')->get() as $row) {
            $type = PackageType::tryFrom((string) $row['type']);
            if ($type === null) {
                continue;
            }

            $this->cache[$type->value . ':' . $row['slug']] = new PackageState(
                $type,
                (string) $row['slug'],
                $row['status'] === 'active',
                (string) $row['version'],
            );
        }

        return $this->cache;
    }
}
