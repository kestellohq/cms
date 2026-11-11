<?php

declare(strict_types=1);

namespace Themes\Exhibit\Controllers;

use Kestello\Cms\Cms;
use Kestello\Cms\Content\ContentController;
use Kestello\Cms\Content\Entry;
use Kestello\Cms\I18n\ContentLanguages;
use Kestello\Cms\View\Page;
use Kestello\Framework\Core\Response;
use Kestello\Framework\Database\Model;

/**
 * The entrance: the newest projects as prints, the newest talks, the
 * latest journal entries, and the studio's introduction and contact page
 * when the site has pages at /studio and /contact. Everything is read in
 * the page's language.
 */
final class HomeController
{
    /** The rooms, in order: the content type each one shows, and how many of its entries the home page hangs. */
    public const array ROOMS = ['projects' => 7, 'talks' => 3, 'post' => 3];

    public static function home(Cms $cms): Response
    {
        $rooms = [];
        foreach (self::ROOMS as $name => $count) {
            $type = $cms->content->get($name);
            if ($type === null || $type->getPath() === null) {
                continue;
            }
            $q = Entry::query();
            $q->where('entries.type', $name)->where('entries.locale', ContentLanguages::current())
                ->where('entries.status', 'published')->where('entries.published_at', '<=', Model::freshTimestamp())
                ->orderBy('entries.published_at', 'desc')->limit($count);
            $entries = [];
            foreach ($q->get() as $entry) {
                $entries[] = $entry;
            }
            $rooms[$name] = [
                'name' => $name,
                'plural' => $type->plural(),
                'path' => ContentLanguages::localize((string) $type->getPath(), ContentLanguages::current()),
                'entries' => ContentController::presentList($entries, [$name => $type]),
            ];
        }

        return Page::render('Home', [
            'rooms' => (object) $rooms,
            'intro' => self::page('studio'),
            'contact' => self::page('contact'),
        ]);
    }

    /**
     * A top-level page by its address, in the page's language when it is
     * translated: its title, its search description (Search and sharing)
     * and its address. Null when the site has no such page.
     *
     * @return array{title: string, description: string, path: string}|null
     */
    private static function page(string $slug): ?array
    {
        $q = Entry::query();
        $q->where('entries.type', 'page')->where('entries.slug', $slug)->where('entries.locale', '')->whereNull('entries.parent_id')
            ->where('entries.status', 'published')->where('entries.published_at', '<=', Model::freshTimestamp());
        $entry = $q->first();
        if (!$entry instanceof Entry) {
            return null;
        }
        $shown = ContentLanguages::current() === '' ? $entry : ($entry->translation(ContentLanguages::current()) ?? $entry);

        $seo = $shown->field('seo');

        return ['title' => (string) $shown->title, 'description' => is_array($seo) ? trim((string) ($seo['description'] ?? '')) : '', 'path' => (string) $shown->url()];
    }
}
