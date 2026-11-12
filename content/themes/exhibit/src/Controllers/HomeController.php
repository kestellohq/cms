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
 * The entrance: the newest projects as prints, the coming talks, the
 * people, the latest journal entries, and the studio's introduction and contact page
 * when the site has pages at /studio and /contact. Everything is read in
 * the page's language.
 */
final class HomeController
{
    /** The rooms, in order: the content type each one shows, and how many of its entries the home page hangs. */
    public const array ROOMS = ['projects' => 7, 'talks' => 3, 'team' => 8, 'post' => 3];

    /** How many talks are read to find the coming ones: talks are published before they happen. */
    private const int TALKS_READ = 40;

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
                ->orderBy('entries.published_at', $name === 'team' ? 'asc' : 'desc')->limit($name === 'talks' ? self::TALKS_READ : $count);
            $entries = [];
            foreach ($q->get() as $entry) {
                $entries[] = $entry;
            }
            if ($name === 'talks') {
                $entries = array_slice(self::programme($entries), 0, $count);
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
     * Talks as a programme reads: the coming ones first, the soonest at the
     * top, then the ones held, the latest first. A talk without a start
     * counts from when it was published.
     *
     * @param list<Entry> $talks
     * @return list<Entry>
     */
    public static function programme(array $talks): array
    {
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $start = static function (Entry $e): string {
            $starts = $e->field('starts');
            $at = is_string($starts) && $starts !== '' ? strtotime($starts) : strtotime((string) $e->getAttribute('published_at'));

            return gmdate('Y-m-d\TH:i:s\Z', $at === false ? 0 : $at);
        };
        $coming = array_values(array_filter($talks, static fn(Entry $e): bool => $start($e) > $now));
        $held = array_values(array_filter($talks, static fn(Entry $e): bool => $start($e) <= $now));
        usort($coming, static fn(Entry $a, Entry $b): int => $start($a) <=> $start($b));
        usort($held, static fn(Entry $a, Entry $b): int => $start($b) <=> $start($a));

        return [...$coming, ...$held];
    }

    /**
     * A top-level page by its address, in the page's language when it is
     * translated: its title, its search description (Search and sharing)
     * and its address. Null when the site has no such page.
     *
     * @return array{title: string, description: string, path: string}|null
     */
    public static function page(string $slug): ?array
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
