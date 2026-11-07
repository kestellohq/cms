<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Design;

use Kestello\Cms\Api\ApiProblem;
use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Content\ContentException;
use Kestello\Cms\Design\ThemeTemplates;

/** Base for /design/templates: templates, parts and patterns. @internal */
abstract class TemplateEndpoint extends DesignEndpoint
{
    protected const string KIND = '(?:template|part|pattern)';
    protected const string KEY = '[a-z0-9][a-z0-9-]{0,60}';

    protected function templates(): ThemeTemplates
    {
        return $this->cms()->container->make(ThemeTemplates::class);
    }

    /** Run a write, turning refusals into API problems. */
    protected function attempt(\Closure $write): mixed
    {
        try {
            return $write();
        } catch (ContentException $e) {
            throw match ($e->kind) {
                'invalid' => ApiProblem::validation($e->errors),
                'forbidden' => ApiProblem::forbidden($e->getMessage()),
                default => ApiProblem::conflict($e->getMessage()),
            };
        } catch (\OutOfBoundsException $e) {
            throw ApiProblem::notFound($e->getMessage());
        }
    }

    /** One document with its draft, the theme's file and its history. @return array<string, mixed> */
    protected function document(ApiRequest $request, string $kind, string $key): array
    {
        $t = $this->templates();
        $slug = $t->themeSlug() ?? throw ApiProblem::conflict('No theme is running.');
        $published = $this->documents()->get($slug, $kind, $key);
        $file = $t->file($kind, $key);
        $draft = $this->documents()->get($slug, $kind, $key, 'draft');
        if ($published === null && $file === null && $draft === null) {
            throw ApiProblem::notFound('There is no such ' . $kind . '.');
        }
        $revisions = $this->documents()->revisions($slug, $kind, $key);

        return [
            'kind' => $kind,
            'key' => $key,
            'theme' => $slug,
            'source' => $file !== null ? ($published !== null ? 'customized' : 'theme') : 'site',
            'published' => $published ?? $file,
            'draft' => $draft,
            'file' => $file !== null,
            'info' => $this->documents()->info($slug, $kind, $key),
            'draft_info' => $this->documents()->info($slug, $kind, $key, 'draft'),
            'revisions' => $this->withAuthors($revisions),
            'used_by' => $this->usedBy($kind, $key),
            // the theme changed its version since the site's was made
            'theme_update' => $published !== null && $t->hasThemeUpdate($kind, $key),
            'theme_version' => $published !== null && $file !== null && $t->hasThemeUpdate($kind, $key) ? $file : null,
        ];
    }

    /** Templates that place this part or synced pattern. @return list<array{key: string, title: string}> */
    protected function usedBy(string $kind, string $key): array
    {
        if ($kind === 'template') {
            return [];
        }
        [$type, $prop] = $kind === 'part' ? ['core/template-part', 'part'] : ['core/pattern', 'pattern'];
        $out = [];
        foreach (['template', 'part'] as $k) {
            foreach ($this->templates()->all($k) as $doc) {
                if (self::places($doc['data']['blocks'] ?? [], $type, $prop, $key)) {
                    $out[] = ['kind' => $k, 'key' => $doc['key'], 'title' => $doc['title']];
                }
            }
        }

        return $out;
    }

    /** @param list<array<string, mixed>> $nodes */
    private static function places(array $nodes, string $type, string $prop, string $key): bool
    {
        foreach ($nodes as $n) {
            if ((($n['type'] ?? null) === $type && (string) ((array) ($n['props'] ?? []))[$prop] === $key) || self::places($n['children'] ?? [], $type, $prop, $key)) {
                return true;
            }
        }

        return false;
    }

    /** @param list<array<string, mixed>> $revisions @return list<array<string, mixed>> */
    protected function withAuthors(array $revisions): array
    {
        $names = [];
        $ids = array_values(array_unique(array_filter(array_column($revisions, 'author'))));
        if ($ids !== []) {
            foreach (\Kestello\Framework\Database\QueryBuilder::table('users')->whereIn('id', $ids)->get() as $u) {
                $u = (array) $u;
                $names[(int) $u['id']] = (string) ($u['display_name'] ?? '') !== '' ? (string) $u['display_name'] : (string) $u['username'];
            }
        }

        return array_map(static fn(array $r) => $r + ['author_name' => $r['author'] !== null ? ($names[$r['author']] ?? null) : null], $revisions);
    }

    protected function changed(string $event, string $kind, string $key, ?int $userId, array $extra = []): void
    {
        $this->purgePages();
        $this->cms()->audit->record('theme.' . $kind . '.' . $event, ['theme_' . $kind, $key], $extra, $userId);
    }
}
