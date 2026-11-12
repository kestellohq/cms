<?php

declare(strict_types=1);

namespace Kestello\Cms\Health;

use Kestello\Cms\Cms;
use Kestello\Framework\Database\DatabaseManager;

/** Active users whose role grants system.manage (who hears about infrastructure problems), or another capability. @internal */
final class Administrators
{
    /** @return list<int> at most 50 */
    public static function ids(Cms $cms, string $capability = 'system.manage'): array
    {
        $roles = $cms->auth->roles->rolesWith($capability);
        if ($roles === []) {
            return [];
        }

        return array_map('intval', DatabaseManager::getConnection()->table('users')->whereIn('role', $roles)->where('is_active', true)->limit(50)->pluck('id'));
    }
}
