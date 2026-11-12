<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Users;

use Kestello\Cms\Api\ApiProblem;
use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Auth\Roles;

/**
 * Shared rules for editing roles (no privilege escalation):
 *
 * - The administrator role ("*") cannot be changed or deleted.
 * - You can only grant capabilities you hold yourself.
 * - You cannot change the role you hold (unless you hold "*"): a manager
 *   can't widen or lock their own access.
 * - You can only change or reset a role whose capabilities, now and by
 *   default, you all hold: otherwise narrowing a stronger role, giving
 *   someone it, then resetting it would hand out what you don't have.
 * - Only declared capabilities can be granted.
 *
 * @internal
 */
trait RoleAdmin
{
    use UserAdmin;

    private static function roles(): Roles
    {
        return self::cms()->auth->roles;
    }

    private static function editableRole(ApiRequest $request, string $name): void
    {
        $roles = self::roles();
        if (!$roles->exists($name)) {
            throw ApiProblem::notFound('No such role.');
        }
        if (in_array('*', $roles->capabilitiesFor($name), true) || $name === 'admin') {
            throw ApiProblem::forbidden('The administrator role always has every capability.');
        }
        if (self::actorIsSuper($request)) {
            return;
        }
        if ($name === self::actorRole($request)) {
            throw ApiProblem::forbidden("You can't change your own role. Ask an administrator.");
        }
        $missing = array_values(array_filter(
            array_unique([...$roles->capabilitiesFor($name), ...($roles->defaultsFor($name) ?? [])]),
            static fn(string $cap): bool => !$request->principal->can($cap),
        ));
        if ($missing !== []) {
            throw ApiProblem::forbidden('This role can do what you cannot (' . implode(', ', $missing) . '). Ask an administrator to change it.');
        }
    }

    /**
     * @param list<string> $capabilities
     * @return list<string> the clean list
     */
    private static function checkCapabilities(ApiRequest $request, array $capabilities, string $current = ''): array
    {
        $roles = self::roles();
        $declared = $roles->capabilities();
        $had = $current === '' ? [] : $roles->capabilitiesFor($current);
        $errors = [];
        $clean = [];
        foreach (array_unique($capabilities) as $cap) {
            if (!isset($declared[$cap])) {
                $errors[] = "Unknown capability [{$cap}].";
            } elseif (!self::actorIsSuper($request) && !in_array($cap, $had, true) && !$request->principal->can($cap)) {
                $errors[] = "You can't grant [{$cap}]: you don't have it yourself.";
            } else {
                $clean[] = $cap;
            }
        }
        if ($errors !== []) {
            throw ApiProblem::validation(['capabilities' => $errors]);
        }
        sort($clean);

        return $clean;
    }

    private static function actorRole(ApiRequest $request): ?string
    {
        $id = $request->userId();

        return $id === null ? null : (string) \Kestello\Framework\Database\QueryBuilder::table('users')->where('id', $id)->value('role');
    }

    /** @return array<string, mixed> */
    private static function describeRole(string $name, ApiRequest $request): array
    {
        $roles = self::roles();
        $caps = $roles->capabilitiesFor($name);
        $all = in_array('*', $caps, true);
        $defaults = $roles->defaultsFor($name) ?? [];
        $labels = $roles->capabilities();

        return [
            'name' => $name,
            'label' => $roles->roles()[$name]['label'] ?? $name,
            'description' => $roles->description($name),
            'administrator' => $all,
            'assignable' => self::actorIsSuper($request) || (!$all && array_filter($caps, static fn(string $c): bool => !$request->principal->can($c)) === []),
            'custom' => $roles->isCustom($name),
            'customized' => $roles->isCustomized($name),
            'editable' => !$all && (self::actorIsSuper($request) || ($name !== self::actorRole($request)
                && array_filter([...$caps, ...$defaults], static fn(string $c): bool => !$request->principal->can($c)) === [])),
            'capabilities' => $all ? ['*'] : array_values($caps),
            'defaults' => $all ? ['*'] : array_values($defaults),
            'summary' => $all ? ['Everything'] : array_values(array_map(static fn(string $c) => $labels[$c]['label'] ?? $c, $caps)),
            'users' => \Kestello\Framework\Database\QueryBuilder::table('users')->where('role', $name)->count(),
        ];
    }
}
