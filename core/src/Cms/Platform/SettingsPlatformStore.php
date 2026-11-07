<?php

declare(strict_types=1);

namespace Kestello\Cms\Platform;

use Kestello\Framework\Database\Connection;

/** Layer 3 in the settings table: key "platform.<subsystem>", JSON value. */
final class SettingsPlatformStore implements PlatformStore
{
    private const string PREFIX = 'platform.';

    public function __construct(private readonly Connection $connection) {}

    public function load(): array
    {
        $rows = $this->connection->table('settings')->where('group', 'platform')->get();
        $out = [];

        foreach ($rows as $row) {
            $key = (string) $row['key'];
            $value = json_decode((string) $row['value'], true);
            if (str_starts_with($key, self::PREFIX) && is_array($value) && is_string($value['driver'] ?? null)) {
                $out[substr($key, strlen(self::PREFIX))] = ['driver' => $value['driver'], 'options' => (array) ($value['options'] ?? [])];
            }
        }

        return $out;
    }

    public function save(string $subsystem, ?array $value): void
    {
        $key = self::PREFIX . $subsystem;

        if ($value === null) {
            $this->connection->table('settings')->where('key', $key)->delete();
            return;
        }

        $now = date('Y-m-d H:i:s');
        $this->connection->table('settings')->upsert(
            ['key' => $key, 'value' => json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), 'group' => 'platform', 'created_at' => $now, 'updated_at' => $now],
            ['key'],
            ['value', 'group', 'updated_at'],
        );
    }
}
