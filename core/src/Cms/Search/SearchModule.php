<?php

declare(strict_types=1);

namespace Kestello\Cms\Search;

use Kestello\Cms\Cms;
use Kestello\Cms\Content\Entry;
use Kestello\Cms\Extension\Provider;
use Kestello\Cms\Health\HealthResult;
use Kestello\Cms\Platform\PlatformChanged;
use Kestello\Framework\Core\Request;
use Kestello\Framework\Database\Orm\Events\ModelEvent;

/** Core module: site search. */
final class SearchModule extends Provider
{
    /** The languages the search index was last built for: a change rebuilds it. */
    private const string INDEXED_LANGUAGES = 'search_languages';

    public function boot(Cms $cms): void
    {
        $cms->queue->job(IndexEntries::class);
        $cms->queue->job(ReindexSearch::class);

        // Every write path ends in a model event: editor, API, scheduled publishing, trash, restore, delete.
        $sync = static function (ModelEvent $e) use ($cms): void {
            $cms->search->sync([(int) $e->model->getKey()]);
            $type = $e->model instanceof Entry ? $e->model->contentType() : null;
            if ($type?->isHierarchical() && $e->model->wasChanged('slug', 'parent_id', 'status')) {
                $cms->search->sync(self::descendants((int) $e->model->getKey()));   // their addresses changed too
            }
        };
        foreach (['saved', 'deleted', 'restored'] as $event) {
            $cms->models->on(Entry::class, $event, $sync);
        }

        // search filters by language on a site in several: when the languages change, every document is indexed again with its own
        $cms->audit->listen(static function (string $event) use ($cms): void {
            if ($event !== 'settings.updated') {
                return;
            }
            $languages = implode(',', \Kestello\Cms\I18n\ContentLanguages::others());
            $settings = \Kestello\Framework\Settings\Settings::getInstance();
            if ((string) $settings->get(self::INDEXED_LANGUAGES, '') !== $languages) {
                $settings->set(self::INDEXED_LANGUAGES, $languages);
                $cms->queue->push(new ReindexSearch());
            }
        });

        $cms->events->on(PlatformChanged::class, static function (PlatformChanged $e) use ($cms): void {
            if ($e->subsystem === 'search') {
                $cms->queue->push(new ReindexSearch());
            }
        });

        $cms->health->check('search', 'Site search', static function () use ($cms): HealthResult {
            $degraded = $cms->drivers->degraded()['search'] ?? null;
            if ($degraded !== null) {
                return HealthResult::critical(
                    "The search engine can't be reached, so search answers from the database: {$degraded['error']}",
                    [],
                    ['Check search settings', '/admin/system/platform'],
                );
            }
            try {
                $engine = $cms->search->engine();
                $engine->ping();
            } catch (\Throwable $e) {
                return HealthResult::critical('Search is not working: ' . $e->getMessage());
            }
            $details = ['Engine' => $engine->name()];
            if ($engine instanceof DatabaseEngine) {
                $details['Matching'] = ['mysql' => 'MySQL full-text', 'pgsql' => 'Postgres full-text', 'sqlite' => 'SQLite FTS5', 'like' => 'simple text matching'][$engine->mode()];
                if ($engine->mode() === 'like') {
                    return HealthResult::recommended('Search uses simple text matching because the database has no full-text index. Results are less relevant and slower on large sites.', $details);
                }
            }

            return HealthResult::good('Search is working.', $details);
        });

        $cms->routes->get('/search', static fn(Request $r) => SearchController::page($cms, $r))->name('search');
    }

    /** @return list<int> */
    private static function descendants(int $id): array
    {
        $out = [];
        $level = [$id];
        for ($depth = 0; $depth < 10 && $level !== [] && count($out) < 2000; $depth++) {
            $level = array_map('intval', Entry::query()->withTrashed()->whereIn('parent_id', $level)->pluck('id'));
            $out = [...$out, ...$level];
        }

        return $out;
    }
}
