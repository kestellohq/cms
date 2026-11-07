<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Settings;

use Kestello\Cms\Api\ApiProblem;
use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Api\Endpoint;
use Kestello\Cms\Api\Schema;
use Kestello\Cms\Cms;
use Kestello\Framework\Settings\Settings;

/**
 * Change site settings: { "<group>": { "<key>": value } }. Send only what
 * changes; a blank secret keeps the stored value, null clears it. Plugin
 * groups ("<slug>.<tab>") that need a capability the caller lacks are refused.
 */
final class UpdateSettings extends Endpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::patch('/settings');
    }
    public function capability(): ?string
    {
        return 'settings.manage';
    }
    public function scopes(): array
    {
        return ['settings:write'];
    }
    public function requiresRecentAuth(): bool
    {
        return true;
    }
    public function summary(): string
    {
        return 'Update site settings';
    }
    public function tags(): array
    {
        return ['settings'];
    }

    public function body(): ?Schema
    {
        return Schema::object(array_map(static fn(array $g): Schema => $g['schema']->partial($g['secrets']), SiteSettings::groups()));
    }

    public function handle(ApiRequest $request): mixed
    {
        $allowed = SiteSettings::groupsFor($request->principal);
        foreach (array_keys($request->body) as $id) {
            if (!isset($allowed[$id])) {
                throw ApiProblem::forbidden("You cannot change the {$id} settings.");
            }
        }

        $changed = [];
        foreach ($allowed as $id => $group) {
            $values = (array) $request->input($id, []);
            foreach ($group['secrets'] as $secret) {
                if (array_key_exists($secret, $values)) {
                    if ($values[$secret] === '') {
                        unset($values[$secret]);
                    } elseif ($values[$secret] === null) {
                        $values[$secret] = '';
                    }
                }
            }
            if ($id === 'general' && isset($values['site_url'])) {
                $values['site_url'] = rtrim((string) $values['site_url'], '/');
                // Reset links are built on the site address: pointing it elsewhere would collect them.
                if ($values['site_url'] !== rtrim((string) (SiteSettings::read()['general']['site_url'] ?? ''), '/') && !$request->principal->can('system.manage')) {
                    throw ApiProblem::forbidden('Changing the site address needs the system.manage permission.');
                }
            }
            if ($values === []) {
                continue;
            }
            $changed = [...$changed, ...array_map(static fn($k) => "{$id}.{$k}", SiteSettings::write($id, $group, $values))];
        }
        Settings::getInstance()->reload();

        if ($changed !== []) {
            if (array_filter($changed, static fn($k) => str_starts_with($k, 'general.')) !== []) {
                SiteSettings::syncAppConfig(SiteSettings::read()['general']);
            }
            Cms::instance()?->audit->record('settings.updated', ['settings', 'site'], ['keys' => $changed], $request->userId());
        }

        return SiteSettings::read($request->principal);
    }
}
