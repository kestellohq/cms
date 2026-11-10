<?php

declare(strict_types=1);

namespace Kestello\Cms\Seed;

use Kestello\Cms\Api\Endpoints\Settings\SiteSettings;
use Kestello\Cms\Cms;
use Kestello\Cms\Content\ContentException;
use Kestello\Cms\Content\Entries;
use Kestello\Cms\Content\Entry;
use Kestello\Cms\Media\Media;
use Kestello\Cms\Media\MediaLibrary;
use Kestello\Cms\Menus\Menus;
use Kestello\Contracts\Auth\Principal;
use Kestello\Framework\Database\DatabaseManager;
use Kestello\Framework\Database\QueryBuilder;
use Kestello\Framework\Settings\Settings;

/**
 * Puts a seed folder (SeedFolder) into a site: media, pages, menus,
 * settings and the front page, written through the same services the admin
 * uses (settings through the same schema as Settings), so everything is
 * validated and cleaned the same way. A seed never holds secrets, such as a
 * mail password: those are refused.
 *
 *   $seeder = new SiteSeeder($cms);
 *   $plan = SeedFolder::read($folder);
 *   $problems = $seeder->check($plan, $principal);   // [] when it would go in
 *   $summary = $seeder->seed($plan, $principal);
 *   $summary = $seeder->seed($plan, $principal, update: true);   // a site that has it already
 *
 * A seed goes into an empty site. With update, it brings a site in line with
 * the seed instead, keeping everything else: a page with the seed page's
 * address is updated in place (a new revision, the same id and links), a
 * menu with the seed menu's name gets the seed's items, settings are
 * written, and a file the seed added before is reused rather than added
 * again. Pages, menus and files the seed doesn't name are left alone.
 *
 * Everything is written in one transaction. When anything fails, it is
 * rolled back and the media files it added are deleted, so the site is left
 * as it was. check() is a seed that always rolls back: it finds exactly what
 * a real seed would.
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
    public function check(array $plan, Principal $author, bool $update = false): array
    {
        try {
            $this->run($plan, $author, keep: false, update: $update);
        } catch (SeedException $e) {
            return explode("\n", $e->getMessage());
        }

        return [];
    }

    /**
     * @param array<string, mixed> $plan SeedFolder::read()
     * @return array{media: int, pages: int, menus: int, settings: int, front_page: ?int, unassigned: list<string>, updated: int, reused: int}
     * @throws SeedException naming each problem, one per line; nothing is written then
     */
    public function seed(array $plan, Principal $author, bool $update = false): array
    {
        return $this->run($plan, $author, keep: true, update: $update);
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
     * @return array{media: int, pages: int, menus: int, settings: int, front_page: ?int, unassigned: list<string>, updated: int, reused: int}
     */
    private function run(array $plan, Principal $author, bool $keep, bool $update): array
    {
        if (!$update && QueryBuilder::table('entries')->exists()) {
            throw new SeedException('A seed only goes into an empty site, and this one already has pages or posts. To bring it in line with the seed, use --update.');
        }
        if ($this->cms->content->get('page') === null) {
            throw new SeedException('This site has no "page" content type.');
        }

        $library = $this->cms->container->make(MediaLibrary::class);
        try {
            $summary = DatabaseManager::getConnection()->transaction(function () use ($plan, $author, $keep, $update, $library): array {
                /** @var array<string, Media> $added */
                $added = [];
                /** @var array<string, int> $ids */
                $ids = [];
                try {
                    foreach ($plan['media'] as $name => $file) {
                        $checksum = (string) hash_file('sha256', $file['path']);
                        $kept = $update ? self::seeded($name, $checksum) : null;
                        if ($kept !== null) {
                            if ((string) $kept->alt !== $file['alt']) {
                                $kept->alt = $file['alt'];
                                $kept->save();
                            }
                            $ids[$name] = (int) $kept->getKey();
                            continue;
                        }
                        $added[$name] = $this->addMedia($library, $name, $file, $author, $checksum);
                        $ids[$name] = (int) $added[$name]->getKey();
                    }
                    $summary = $this->write($plan, $ids, $author, $update);
                    $summary['reused'] = count($ids) - count($added);
                    $summary['media'] = count($added);
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
     * @return array{media: int, pages: int, menus: int, settings: int, front_page: ?int, unassigned: list<string>, updated: int, reused: int}
     */
    private function write(array $plan, array $ids, Principal $author, bool $update): array
    {
        $this->checkBlocks($plan['pages'], $ids);
        [$pages, $updatedPages] = $this->pages($plan['pages'], $ids, $author, $update);
        [$unassigned, $updatedMenus] = $this->menus($plan['menus'], $pages, $update);
        $front = $plan['front_page'] === null ? null : $pages[$plan['front_page']];

        return [
            'media' => count($ids),
            'pages' => count($pages) - $updatedPages,
            'menus' => count($plan['menus']) - $updatedMenus,
            'settings' => $this->settings($plan['settings'], $front, $author),
            'front_page' => $front,
            'unassigned' => $unassigned,
            'updated' => $updatedPages + $updatedMenus,
            'reused' => 0,
        ];
    }

    /**
     * The file a seed added before from this seed file: the same name, and the
     * checksum the seeder recorded (or the library's own, for files seeded
     * before it recorded one, when the library kept the file as it was).
     */
    private static function seeded(string $name, string $checksum): ?Media
    {
        foreach (Media::query()->where('filename', MediaLibrary::cleanName($name))->orderBy('id')->get() as $media) {
            if (!$media instanceof Media) {
                continue;
            }
            $meta = is_array($media->meta) ? $media->meta : [];
            if (($meta['seed']['checksum'] ?? null) === $checksum || (string) $media->checksum === $checksum) {
                return $media;
            }
        }

        return null;
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
    private function addMedia(MediaLibrary $library, string $name, array $file, Principal $author, string $checksum): Media
    {
        // The library moves the file it is given; the seed folder stays as it is.
        $copy = tempnam(sys_get_temp_dir(), 'kestello-seed-');
        if ($copy === false || !copy($file['path'], $copy)) {
            throw new SeedException("Could not copy media/{$name} to the temporary folder " . sys_get_temp_dir() . '.');
        }
        try {
            $media = $library->addFile($author, $copy, $name, ['alt' => $file['alt']]);
        } catch (\Throwable $e) {
            @unlink($copy);
            throw new SeedException("media/{$name}: {$e->getMessage()}", previous: $e);
        }
        // The library may rewrite an image (rotation, metadata), so its checksum is of
        // the stored file. The seed file's own is what a later --update compares.
        $media->meta = ['seed' => ['checksum' => $checksum]] + (is_array($media->meta) ? $media->meta : []);
        $media->save();

        return $media;
    }

    /**
     * @param array<string, array<string, mixed>> $pages
     * @param array<string, int> $media
     * @return array{0: array<string, int>, 1: int} page key => entry id, and how many were updated
     */
    private function pages(array $pages, array $media, Principal $author, bool $update): array
    {
        $type = $this->cms->content->get('page') ?? throw new SeedException('This site has no "page" content type.');
        $entries = $this->cms->container->make(Entries::class);
        $ids = [];
        $updated = 0;
        foreach ($pages as $key => $page) {
            $data = ['body' => self::resolve($page['blocks'], $media)];
            if ($page['seo'] !== []) {
                $data['seo'] = self::resolve($page['seo'], $media);
            }
            try {
                $input = ['title' => $page['title'], 'slug' => $page['slug'], 'status' => $page['status'], 'data' => $data]
                    + ($page['template'] !== null ? ['template' => $page['template']] : []);
                // a seed page is a top-level page, so its slug is its address
                $existing = $update ? $this->topLevelPage($page['slug']) : null;
                if ($existing !== null) {
                    $ids[$key] = (int) $entries->update($existing, $input, $author)->getKey();
                    $updated++;
                } else {
                    $ids[$key] = (int) $entries->create($type, $input, $author)->getKey();
                }
            } catch (ContentException $e) {
                throw new SeedException("pages/{$key}.json: {$e->getMessage()}" . self::details($e), previous: $e);
            }
        }

        return [$ids, $updated];
    }

    /** The page at /<slug>, not counting the trash. */
    private function topLevelPage(string $slug): ?Entry
    {
        foreach (Entry::query()->where('type', 'page')->whereNull('parent_id')->where('slug', $slug)->limit(1)->get() as $entry) {
            if ($entry instanceof Entry) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * Make each menu and put it in its location when the theme has one.
     *
     * @param list<array{name: string, location: string, items: list<array<string, mixed>>}> $menus
     * @param array<string, int> $pages
     * @return array{0: list<string>, 1: int} names of menus whose location the theme lacks, and how many were updated
     */
    private function menus(array $menus, array $pages, bool $update): array
    {
        $store = $this->cms->container->make(Menus::class);
        $existing = $update ? array_column($store->all(), 'id', 'name') : [];
        $unassigned = [];
        $updated = 0;
        foreach ($menus as $menu) {
            $current = isset($existing[$menu['name']]) ? (int) $existing[$menu['name']] : null;
            $updated += $current === null ? 0 : 1;
            try {
                $id = $store->save($current, $menu['name'], self::menuItems($menu['items'], $pages));
            } catch (ContentException $e) {
                throw new SeedException("site.json: menu \"{$menu['name']}\": {$e->getMessage()}" . self::details($e), previous: $e);
            }
            if ($menu['location'] !== '' && $this->cms->menus->has($menu['location'])) {
                $store->assign($menu['location'], $id);
            } elseif ($menu['location'] !== '') {
                $unassigned[] = $menu['name'];
            }
        }

        return [$unassigned, $updated];
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

    /**
     * Seed menu items as Menus stores them, children included.
     *
     * @param list<array<string, mixed>> $items SeedFolder's
     * @param array<string, int> $pages page key => entry id
     * @return list<array<string, mixed>>
     */
    public static function menuItems(array $items, array $pages): array
    {
        return array_map(static function (array $item) use ($pages): array {
            $label = (string) $item['label'];
            $out = match (true) {
                isset($item['page']) => ['kind' => 'entry', 'ref' => $pages[$item['page']]] + ($label !== '' ? ['label' => $label] : []),
                isset($item['url']) => ['kind' => 'url', 'url' => $item['url'], 'label' => $label],
                default => ['kind' => 'archive', 'ref' => $item['archive']] + ($label !== '' ? ['label' => $label] : []),
            };

            return $out + (isset($item['children']) ? ['children' => self::menuItems($item['children'], $pages)] : []);
        }, $items);
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
