<?php

declare(strict_types=1);

namespace Kestello\Cms\Demo;

use Kestello\Cms\Cms;
use Kestello\Cms\Content\ContentType;
use Kestello\Cms\Content\Entries;
use Kestello\Cms\Content\Models\ContentModels;
use Kestello\Cms\Content\Terms;
use Kestello\Cms\Forms\Forms;
use Kestello\Cms\Media\MediaLibrary;
use Kestello\Cms\Menus\Menus;
use Kestello\Contracts\Auth\Principal;
use Kestello\Framework\Database\QueryBuilder;
use Kestello\Framework\Settings\Settings;
use Kestello\Support\Clock;
use RuntimeException;

/**
 * The public demo's sample site: an architecture studio in English and
 * Spanish (content/studio.php), hung in the Exhibit theme. It goes into a
 * freshly installed, empty site in two steps, because content types built
 * in the admin are registered when the site boots:
 *
 *   DemoContent::prepare($cms, $author);   // the types and the languages
 *   // ... the next boot registers the types ...
 *   $summary = DemoContent::seed($cms, $author);
 *
 * DemoSite::ensure() does both, one request apart. Everything is written
 * through the same services an editor's save uses, so it is validated and
 * cleaned like any other content.
 */
final class DemoContent
{
    private const string DATA = __DIR__ . '/content/studio.php';
    private const string PHOTOS = __DIR__ . '/content/photos';
    private const string TIMEZONE = 'Europe/Madrid';    // the studio's: talks start at its local time

    /** @return array<string, mixed> the site, as content/studio.php describes it */
    public static function data(): array
    {
        return require self::DATA;
    }

    /** Whether the content types the demo fills are registered (from the boot after prepare()). */
    public static function ready(Cms $cms): bool
    {
        foreach (array_keys(self::data()['types']) as $name) {
            if ($cms->content->get($name) === null) {
                return false;
            }
        }

        return true;
    }

    /**
     * Step one: build the content types (as an administrator would in the
     * admin), and set the languages, the site's name in each and the words
     * in its addresses. Running it again builds only what is missing. The
     * theme it is made for is data()['theme']; DemoSite switches to it.
     *
     * @return list<string> the content types built now
     */
    public static function prepare(Cms $cms, Principal $author): array
    {
        $site = self::data();
        $models = $cms->container->make(ContentModels::class);
        $built = [];
        foreach ($site['types'] as $name => $definition) {
            if ($cms->content->get($name) === null && !QueryBuilder::table('content_models')->where('kind', 'type')->where('name', $name)->exists()) {
                $models->create('type', $name, $definition, $author->userId);
                $built[] = $name;
            }
        }
        foreach ($site['settings'] as $group => $values) {
            Settings::getInstance()->setMany($values, $group);
        }

        return $built;
    }

    /**
     * Step two: the photos, categories, forms, pages, entries and menus, each
     * in English with its Spanish translation.
     *
     * @param bool $images add the photos to the media library (tests leave them out to run faster)
     * @return array{pages: int, entries: array<string, int>, terms: int, forms: int, menus: int, images: int}
     * @throws RuntimeException when the site already has content, or the types are not registered yet
     */
    public static function seed(Cms $cms, Principal $author, bool $images = true): array
    {
        if (QueryBuilder::table('entries')->exists()) {
            throw new RuntimeException('The demo content only goes into an empty site, and this one already has pages or posts.');
        }
        $site = self::data();
        $entries = $cms->container->make(Entries::class);
        $types = [];
        foreach (array_merge(['page'], array_keys($site['entries'])) as $name) {
            $types[$name] = $cms->content->get($name) ?? throw new RuntimeException(
                "The demo content needs the \"{$name}\" content type, and this site has none yet. DemoContent::prepare() builds it; it is registered on the next boot.",
            );
        }

        $photos = $images ? self::photos($cms, $author, $site['photos']) : [];
        $terms = self::terms($cms, $author, $site['terms']);
        $forms = [];
        foreach ($site['forms'] as $locale => $form) {
            $forms[$locale] = (int) $cms->container->make(Forms::class)->save($form, null, $author->userId)['id'];
        }
        $fill = static fn(array $data, string $locale): array => self::fill($data, $photos, $forms[$locale] ?? $forms['']);

        $pages = [];
        foreach ($site['pages'] as $key => $page) {
            $spec = [
                'title' => $page['title'], 'slug' => $page['slug'], 'days_ago' => 600,
                'data' => ['body' => $page['blocks'], 'seo' => ['description' => $page['description']]],
                'es' => ['title' => $page['es']['title'], 'slug' => $page['es']['slug'], 'data' => ['body' => $page['es']['blocks'], 'seo' => ['description' => $page['es']['description']]]],
            ];
            $pages[$key] = self::entry($entries, $types['page'], $spec, $fill, [], $author);
        }

        $counts = [];
        foreach ($site['entries'] as $type => $list) {
            foreach ($list as $spec) {
                if (isset($spec['starts_in_days'])) {
                    $spec['data']['starts'] = self::startsAt((int) $spec['starts_in_days'], (string) $spec['starts_at']);
                }
                $categories = isset($spec['category']) ? ['category' => [$terms['category'][$spec['category']]]] : [];
                self::entry($entries, $types[$type], $spec, $fill, $categories, $author);
            }
            $counts[$type] = count($list);
        }

        return [
            'pages' => count($pages),
            'entries' => $counts,
            'terms' => 2 * array_sum(array_map('count', $terms)),
            'forms' => count($forms),
            'menus' => self::menus($cms, $site['menus'], $pages),
            'images' => count($photos),
        ];
    }

    /**
     * $data with "@photo:<key>" swapped for that photo's id and "@form" for
     * $formId. A missing photo is left out: from a list, as a field's value,
     * and as a block's prop (an image block without its image goes).
     *
     * @param array<array-key, mixed> $data
     * @param array<string, int> $photos key => media id
     * @return array<array-key, mixed>
     */
    public static function fill(array $data, array $photos, int $formId): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            if (is_array($value) && isset($value['type'], $value['props']) && is_array($value['props'])) {
                $props = self::fill($value['props'], $photos, $formId);
                if ($value['type'] === 'core/image' && !isset($props['image'])) {
                    continue;
                }
                $out[$key] = array_replace($value, ['props' => $props, 'children' => self::fill((array) ($value['children'] ?? []), $photos, $formId)]);
            } elseif (is_array($value)) {
                $out[$key] = self::fill($value, $photos, $formId);
            } elseif ($value === '@form') {
                $out[$key] = (string) $formId;
            } elseif (is_string($value) && str_starts_with($value, '@photo:')) {
                if (isset($photos[substr($value, 7)])) {
                    $out[$key] = $photos[substr($value, 7)];
                }
            } else {
                $out[$key] = $value;
            }
        }

        return array_is_list($data) ? array_values($out) : $out;
    }

    /** UTC "Y-m-d H:i:s", $days before now at 8:30 in the morning. */
    public static function daysAgo(int $days): string
    {
        return Clock::now(new \DateTimeZone('UTC'))->modify("-{$days} days")->setTime(8, 30)->format('Y-m-d H:i:s');
    }

    /** A talk's start: $days from now (negative: past) at $time ("19:00") in the studio's timezone, as UTC ISO 8601. */
    public static function startsAt(int $days, string $time): string
    {
        [$hour, $minute] = array_map('intval', explode(':', $time . ':0'));

        return Clock::now(new \DateTimeZone(self::TIMEZONE))->modify(sprintf('%+d days', $days))->setTime($hour, $minute)
            ->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }

    /**
     * An entry in the main language, published, and its Spanish translation:
     * Entries::translate() copies the shared fields and the categories'
     * translations, and the translation's own words replace the rest.
     *
     * @param array<string, mixed> $spec title, slug, days_ago, data, excerpt, and "es" with the translation's
     * @param callable(array<array-key, mixed>, string): array<array-key, mixed> $fill
     * @param array<string, list<int>> $terms taxonomy => term ids
     * @return int the main language's entry id
     */
    private static function entry(Entries $entries, ContentType $type, array $spec, callable $fill, array $terms, Principal $author): int
    {
        $published = self::daysAgo((int) $spec['days_ago']);
        $input = ['title' => $spec['title'], 'slug' => $spec['slug'], 'status' => 'published', 'published_at' => $published, 'data' => $fill($spec['data'], '')];
        if (isset($spec['excerpt'])) {
            $input['excerpt'] = $spec['excerpt'];
        }
        if ($terms !== []) {
            $input['terms'] = $terms;
        }
        $main = $entries->create($type, $input, $author);

        $es = $spec['es'];
        $draft = $entries->translate($main, 'es', $author);
        $translation = [
            'title' => $es['title'], 'slug' => $es['slug'], 'status' => 'published', 'published_at' => $published,
            'data' => array_replace((array) $draft->data, $fill($es['data'] ?? [], 'es')),
        ];
        if (isset($es['excerpt'])) {
            $translation['excerpt'] = $es['excerpt'];
        }
        $entries->update($draft, $translation, $author);

        return (int) $main->getKey();
    }

    /**
     * @param array<string, array{file: string, alt: string, caption: string}> $specs
     * @return array<string, int> key => media id
     */
    private static function photos(Cms $cms, Principal $author, array $specs): array
    {
        $library = $cms->container->make(MediaLibrary::class);
        $ids = [];
        foreach ($specs as $key => $spec) {
            // the library takes the file over: give it a copy, so the one shipped with core stays
            $copy = tempnam(sys_get_temp_dir(), 'kestello-demo-');
            if ($copy === false || !copy(self::PHOTOS . '/' . $spec['file'], $copy)) {
                throw new RuntimeException('Could not copy the demo photo ' . $spec['file'] . ' to the temporary folder ' . sys_get_temp_dir() . '.');
            }
            $ids[$key] = (int) $library->addFile($author, $copy, $spec['file'], ['alt' => $spec['alt'], 'caption' => $spec['caption']])->getKey();
        }

        return $ids;
    }

    /**
     * Each term in the main language, and its Spanish translation.
     *
     * @param array<string, array<string, string>> $names taxonomy => English name => Spanish name
     * @return array<string, array<string, int>> taxonomy => English name => term id
     */
    private static function terms(Cms $cms, Principal $author, array $names): array
    {
        $terms = new Terms();
        $ids = [];
        foreach ($names as $taxonomy => $list) {
            $tax = $cms->content->getTaxonomy($taxonomy) ?? throw new RuntimeException("The demo content needs the \"{$taxonomy}\" taxonomy, and this site has none.");
            foreach ($list as $name => $spanish) {
                $term = $terms->create($tax, ['name' => $name], $author);
                $translation = $terms->translate($tax, $term, 'es', $author);
                $terms->update($tax, $translation, ['name' => $spanish, 'slug' => self::slug($spanish)], $author);
                $ids[$taxonomy][$name] = (int) $term->getKey();
            }
        }

        return $ids;
    }

    /**
     * Create the menus and put each in its location and language, when the
     * theme has the location. Pages link to their translation in the
     * visitor's language by themselves.
     *
     * @param list<array{name: string, location: string, locale: string, items: list<list<string>>}> $specs
     * @param array<string, int> $pages page key => id
     * @return int menus created
     */
    private static function menus(Cms $cms, array $specs, array $pages): int
    {
        $menus = $cms->container->make(Menus::class);
        foreach ($specs as $spec) {
            $items = array_map(static fn(array $i): array => match ($i[0]) {
                'page' => ['kind' => 'entry', 'ref' => $pages[$i[1]]],
                'archive' => ['kind' => 'archive', 'ref' => $i[1], 'label' => $i[2]],
                default => throw new RuntimeException("Unknown menu item \"{$i[0]}\" in the demo content: use page or archive."),
            }, $spec['items']);
            $id = $menus->save(null, $spec['name'], $items);
            if ($cms->menus->has($spec['location'])) {
                $menus->assign($spec['location'], $id, $spec['locale']);
            }
        }

        return count($specs);
    }

    /** "Visitas" → "visitas"; accents dropped ("Diseño" → "diseno"). */
    private static function slug(string $name): string
    {
        $ascii = (string) iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name);

        return trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($ascii)), '-');
    }
}
