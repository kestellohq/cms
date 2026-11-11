<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Settings;

use Kestello\Cms\Api\ApiProblem;
use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Api\Endpoint;
use Kestello\Cms\Api\Schema;
use Kestello\Cms\Cms;
use Kestello\Cms\Content\Entries;
use Kestello\Cms\I18n\ContentLanguages;
use Kestello\Cms\I18n\SiteLanguage;
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
            if ($id === 'general' && array_key_exists(ContentLanguages::SETTING, $values)) {
                $entries = Cms::instance()?->container->make(Entries::class);
                [$codes, $errors] = ContentLanguages::check(
                    (string) $values[ContentLanguages::SETTING],
                    (string) ($values[SiteLanguage::SETTING] ?? SiteLanguage::site()),
                    static fn(string $code): ?string => $entries?->languageOwner($code),
                    ContentLanguages::others(),
                );
                if ($errors !== []) {
                    throw ApiProblem::validation(['general.' . ContentLanguages::SETTING => $errors]);
                }
                $values[ContentLanguages::SETTING] = implode(',', $codes);
            }
            if ($id === 'general' && array_key_exists(\Kestello\Cms\I18n\TranslatedPaths::SETTING, $values)) {
                $entries = Cms::instance()?->container->make(Entries::class);
                $content = Cms::instance()?->content;
                $declared = [];
                foreach ([...($content?->types() ?? []), ...($content?->taxonomies() ?? [])] as $owner) {
                    if (($owner->getPath() ?? '') !== '') {
                        $declared[] = (string) $owner->getPath();
                    }
                }
                [$paths, $errors] = \Kestello\Cms\I18n\TranslatedPaths::clean(
                    json_decode((string) $values[\Kestello\Cms\I18n\TranslatedPaths::SETTING], true),
                    array_key_exists(ContentLanguages::SETTING, $values) ? array_values(array_filter(explode(',', (string) $values[ContentLanguages::SETTING]))) : ContentLanguages::others(),
                    $declared,
                    static fn(string $segment, string $code): ?string => $entries?->translatedPathOwner($segment, $code),
                );
                if ($errors !== []) {
                    throw ApiProblem::validation(['general.' . \Kestello\Cms\I18n\TranslatedPaths::SETTING => $errors]);
                }
                $values[\Kestello\Cms\I18n\TranslatedPaths::SETTING] = $paths === [] ? '' : json_encode($paths, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            if ($id === 'general' && array_key_exists(\Kestello\Cms\I18n\SiteText::SETTING, $values)) {
                // texts for the languages the site has after this save, kept to the known keys
                $languages = array_key_exists(ContentLanguages::SETTING, $values)
                    ? array_values(array_filter(explode(',', (string) $values[ContentLanguages::SETTING]))) : ContentLanguages::others();
                [$texts, $errors] = \Kestello\Cms\I18n\SiteText::clean(json_decode((string) $values[\Kestello\Cms\I18n\SiteText::SETTING], true), $languages);
                if ($errors !== []) {
                    throw ApiProblem::validation(['general.' . \Kestello\Cms\I18n\SiteText::SETTING => $errors]);
                }
                $values[\Kestello\Cms\I18n\SiteText::SETTING] = $texts === [] ? '' : json_encode($texts, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
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
