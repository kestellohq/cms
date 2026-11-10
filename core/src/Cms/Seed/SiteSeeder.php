<?php

declare(strict_types=1);

namespace Kestello\Cms\Seed;

use Kestello\Cms\Api\Endpoints\Settings\SiteSettings;
use Kestello\Cms\Cms;
use Kestello\Cms\Content\ContentException;
use Kestello\Cms\Content\Entries;
use Kestello\Cms\Media\Media;
use Kestello\Cms\Media\MediaLibrary;
use Kestello\Cms\Menus\Menus;
use Kestello\Contracts\Auth\Principal;
use Kestello\Framework\Database\DatabaseManager;
use Kestello\Framework\Database\QueryBuilder;
use Kestello\Framework\Settings\Settings;

/**
 * Puts a seed folder (SeedFolder) into an empty site: media, pages, menus,
 * settings and the front page, written through the same services the admin
 * uses (settings through the same schema as Settings), so everything is
 * validated and cleaned the same way. A seed never holds secrets, such as a
 * mail password: those are refused.
 *
 *   $seeder = new SiteSeeder($cms);
 *   $plan = SeedFolder::read($folder);
 *   $problems = $seeder->check($plan, $principal);   // [] when it would go in
 *   $summary = $seeder->seed($plan, $principal);
 *
 * Everything is written in one transaction. When anything fails, it is
 * rolled back and the media files it added are deleted, so the site is left
 * empty. check() is a seed that always rolls back: it finds exactly what a
 * real seed would.
 */
final class SiteSeeder
{
    public function __construct(private readonly Cms $cms) {}

    /**
     * What stops this plan going into this site, as messages naming the file.
     * Writes nothing.
     *
     * @param array<string, mixed> $plan SeedFolder::read()
     * @return list<string>
     */
    public function check(array $plan, Principal $author): array
    {
        try {
            $this->run($plan, $author, keep: false);
        } catch (SeedException $e) {
            return explode("\n", $e->getMessage());
        }

        return [];
    }

    /**
     * @param array<string, mixed> $plan SeedFolder::read()
     * @return array{media: int, pages: int, menus: int, settings: int, front_page: ?int, unassigned: list<string>}
     * @throws SeedException naming each problem, one per line; nothing is written then
     */
    public function seed(array $plan, Principal $author): array
    {
        return $this->run($plan, $author, keep: true);
    }

    /**
     * Swap every "@media:<file>" for that file's media id.
     *
     * @param array<string, int> $ids file name => media id
     */
    public static function resolve(mixed $value, array $ids): mixed
    {
        if (is_string($value) && str_starts_with($value, '@media:')) {
            return $ids[substr($value, 7)] ?? $value;
        }
        if (is_array($value)) {
            return array_map(static fn(mixed $v): mixed => self::resolve($v, $ids), $value);
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $plan
     * @return array{media: int, pages: int, menus: int, settings: int, front_page: ?int, unassigned: list<string>}
     */
    private function run(array $plan, Principal $author, bool $keep): array
    {
        if (QueryBuilder::table('entries')->exists()) {
            throw new SeedException('A seed only goes into an empty site, and this one already has pages or posts.');
        }
        if ($this->cms->content->get('page') === null) {
            throw new SeedException('This site has no "page" content type.');
        }

        $library = $this->cms->container->make(MediaLibrary::class);
        try {
            $summary = DatabaseManager::getConnection()->transaction(function () use ($plan, $author, $keep, $library): array {
                /** @var array<string, Media> $added */
                $added = [];
                try {
                    foreach ($plan['media'] as $name => $file) {
                        $added[$name] = $this->addMedia($library, $name, $file, $author);
                    }
                    $summary = $this->write($plan, array_map(static fn(Media $m): int => (int) $m->getKey(), $added), $author);
                    if (!$keep) {
                        throw new DryRun($summary);
                    }

                    return $summary;
                } catch (\Throwable $e) {
                    // The rollback takes the rows; the files, thumbnails included, go
                    // now, while their rows still say where they are.
                    foreach ($added as $media) {
                        $library->delete($media, $author, force: true);
                    }
                    throw $e;
                }
            });
        } catch (DryRun $dry) {
            return $dry->summary;
        } finally {
            Settings::getInstance()->reload();
        }
        // Only once committed: it writes config/app.php, which no rollback undoes.
        if (isset($plan['settings']['general'])) {
            SiteSettings::syncAppConfig(SiteSettings::read()['general']);
        }

        return $summary;
    }

    /**
     * Pages, menus, settings and the front page, once the media is in.
     *
     * @param array<string, mixed> $plan
     * @param array<string, int> $ids media file name => id
     * @return array{media: int, pages: int, menus: int, settings: int, front_page: ?int, unassigned: list<string>}
     */
    private function write(array $plan, array $ids, Principal $author): array
    {
        $this->checkBlocks($plan['pages'], $ids);
        $pages = $this->pages($plan['pages'], $ids, $author);
        $unassigned = $this->menus($plan['menus'], $pages);
        $front = $plan['front_page'] === null ? null : $pages[$plan['front_page']];

        return [
            'media' => count($ids),
            'pages' => count($pages),
            'menus' => count($plan['menus']),
            'settings' => $this->settings($plan['settings'], $front, $author),
            'front_page' => $front,
            'unassigned' => $unassigned,
        ];
    }

    /**
     * Every page's blocks, checked before any is written, so one run names
     * every block problem in the seed.
     *
     * @param array<string, array<string, mixed>> $pages
     * @param array<string, int> $media
     */
    private function checkBlocks(array $pages, array $media): void
    {
        $problems = [];
        foreach ($pages as $key => $page) {
            [, $errors] = $this->cms->blocks->validate(self::resolve($page['blocks'], $media));
            foreach ($errors as $error) {
                $problems[] = "pages/{$key}.json: {$error}";
            }
        }
        if ($problems !== []) {
            throw new SeedException(implode("\n", $problems));
        }
    }

    /**
     * @param array{path: string, alt: string} $file
     */
    private function addMedia(MediaLibrary $library, string $name, array $file, Principal $author): Media
    {
        // The library moves the file it is given; the seed folder stays as it is.
        $copy = tempnam(sys_get_temp_dir(), 'kestello-seed-');
        if ($copy === false || !copy($file['path'], $copy)) {
            throw new SeedException("Could not copy media/{$name} to the temporary folder " . sys_get_temp_dir() . '.');
        }
        try {
            return $library->addFile($author, $copy, $name, ['alt' => $file['alt']]);
        } catch (\Throwable $e) {
            @unlink($copy);
            throw new SeedException("media/{$name}: {$e->getMessage()}", previous: $e);
        }
    }

    /**
     * @param array<string, array<string, mixed>> $pages
     * @param array<string, int> $media
     * @return array<string, int> page key => entry id
     */
    private function pages(array $pages, array $media, Principal $author): array
    {
        $type = $this->cms->content->get('page') ?? throw new SeedException('This site has no "page" content type.');
        $entries = $this->cms->container->make(Entries::class);
        $ids = [];
        foreach ($pages as $key => $page) {
            $data = ['body' => self::resolve($page['blocks'], $media)];
            if ($page['seo'] !== []) {
                $data['seo'] = self::resolve($page['seo'], $media);
            }
            try {
                $input = ['title' => $page['title'], 'slug' => $page['slug'], 'status' => $page['status'], 'data' => $data]
                    + ($page['template'] !== null ? ['template' => $page['template']] : []);
                $ids[$key] = (int) $entries->create($type, $input, $author)->getKey();
            } catch (ContentException $e) {
                throw new SeedException("pages/{$key}.json: {$e->getMessage()}" . self::details($e), previous: $e);
            }
        }

        return $ids;
    }

    /**
     * Make each menu and put it in its location when the theme has one.
     *
     * @param list<array{name: string, location: string, items: list<array<string, string>>}> $menus
     * @param array<string, int> $pages
     * @return list<string> names of menus whose location the theme lacks
     */
    private function menus(array $menus, array $pages): array
    {
        $store = $this->cms->container->make(Menus::class);
        $unassigned = [];
        foreach ($menus as $menu) {
            $items = array_map(static fn(array $item): array => match (true) {
                isset($item['page']) => ['kind' => 'entry', 'ref' => $pages[$item['page']]] + ($item['label'] !== '' ? ['label' => $item['label']] : []),
                isset($item['url']) => ['kind' => 'url', 'url' => $item['url'], 'label' => $item['label']],
                default => ['kind' => 'archive', 'ref' => $item['archive']] + ($item['label'] !== '' ? ['label' => $item['label']] : []),
            }, $menu['items']);
            try {
                $id = $store->save(null, $menu['name'], $items);
            } catch (ContentException $e) {
                throw new SeedException("site.json: menu \"{$menu['name']}\": {$e->getMessage()}" . self::details($e), previous: $e);
            }
            if ($menu['location'] !== '' && $this->cms->menus->has($menu['location'])) {
                $store->assign($menu['location'], $id);
            } elseif ($menu['location'] !== '') {
                $unassigned[] = $menu['name'];
            }
        }

        return $unassigned;
    }

    /**
     * The settings, and the front page, checked and stored like a save on the
     * Settings screens: the group's schema, then SiteSettings::write().
     *
     * @param array<string, array<string, scalar>> $groups
     * @return int settings written, not counting the front page
     */
    private function settings(array $groups, ?int $front, Principal $author): int
    {
        $count = array_sum(array_map('count', $groups));
        if ($front !== null) {
            $groups['reading']['front_page'] = (string) $front;
        }
        $allowed = SiteSettings::groupsFor($author);
        $problems = [];
        $clean = [];
        foreach ($groups as $id => $values) {
            $group = $allowed[$id] ?? null;
            if ($group === null) {
                $problems[] = "site.json: there are no \"{$id}\" settings; use " . implode(', ', array_keys($allowed)) . '.';
                continue;
            }
            foreach (array_intersect(array_keys($values), $group['secrets']) as $secret) {
                $problems[] = "site.json: settings.{$id}.{$secret} is a secret and does not belong in a seed; set it on the site.";
            }
            [$checked, $errors] = $group['schema']->partial()->validate($values);
            foreach ($errors as $path => $messages) {
                $problems[] = "site.json: settings.{$id}" . ($path === '_' ? '' : ".{$path}") . ': ' . implode(' ', $messages);
            }
            $clean[$id] = [$group, is_array($checked) ? $checked : []];
        }
        if ($problems !== []) {
            throw new SeedException(implode("\n", $problems));
        }
        foreach ($clean as $id => [$group, $values]) {
            SiteSettings::write($id, $group, $values);
        }
        Settings::getInstance()->reload();

        return $count;
    }

    private static function details(ContentException $e): string
    {
        if ($e->errors === []) {
            return '';
        }
        $lines = [];
        foreach ($e->errors as $field => $messages) {
            $lines[] = $field . ': ' . implode(' ', array_map('strval', (array) $messages));
        }

        return ' (' . implode('; ', $lines) . ')';
    }
}
