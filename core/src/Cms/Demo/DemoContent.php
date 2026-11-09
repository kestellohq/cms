<?php

declare(strict_types=1);

namespace Kestello\Cms\Demo;

use Kestello\Cms\Cms;
use Kestello\Cms\Content\ContentType;
use Kestello\Cms\Content\Entries;
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
 * The public demo's sample site: fills a freshly installed, empty site with
 * a small bakery (pages, posts, terms, a contact form, menus and a few
 * generated images). The words and the layout are in content/bakery.php.
 *
 *   $summary = DemoContent::seed($cms, $cms->auth->principalFor($admin, 'cli'));
 *
 * Everything is written through the same services an editor's save uses,
 * so it is validated and cleaned like any other content. Without GD the
 * images are left out and everything else is still created.
 */
final class DemoContent
{
    private const string DATA = __DIR__ . '/content/bakery.php';
    private const int WIDTH = 960;
    private const int HEIGHT = 640;
    private const int SUPERSAMPLE = 2;    // shapes are drawn larger and scaled down, for smooth edges

    /**
     * @param ?bool $images make the images; null: when GD is there
     * @return array{pages: int, posts: int, terms: int, forms: int, menus: int, images: int, front_page: int}
     * @throws RuntimeException when the site already has content
     */
    public static function seed(Cms $cms, Principal $author, ?bool $images = null): array
    {
        if (QueryBuilder::table('entries')->exists()) {
            throw new RuntimeException('The demo content only goes into an empty site, and this one already has pages or posts.');
        }
        /** @var array<string, mixed> $site */
        $site = require self::DATA;
        $entries = $cms->container->make(Entries::class);

        $media = ($images ?? extension_loaded('gd')) ? self::images($cms, $author, $site['images']) : [];
        $terms = self::terms($cms, $author, $site['terms']);
        $formId = (int) $cms->container->make(Forms::class)->save($site['form'], null, $author->userId)['id'];

        $pageType = self::type($cms, 'page');
        $pages = [];
        foreach ($site['pages'] as $key => $page) {
            $blocks = self::resolve($page['blocks'], $media, $formId);
            $pages[$key] = (int) $entries->create($pageType, [
                'title' => $page['title'], 'slug' => $page['slug'], 'status' => 'published', 'data' => ['body' => $blocks],
            ], $author)->getKey();
        }

        $postType = self::type($cms, 'post');
        foreach ($site['posts'] as $post) {
            $data = ['body' => self::resolve($post['blocks'], $media, $formId)];
            if (isset($media[$post['image']])) {
                $data['image'] = $media[$post['image']];
            }
            $entries->create($postType, [
                'title' => $post['title'], 'slug' => $post['slug'], 'status' => 'published', 'excerpt' => $post['excerpt'],
                'published_at' => self::daysAgo((int) $post['days_ago']), 'data' => $data,
                'terms' => [
                    'category' => [$terms['category'][$post['category']]],
                    'tag' => array_map(static fn(string $t): int => $terms['tag'][$t], $post['tags']),
                ],
            ], $author);
        }

        $menus = self::menus($cms, $site['menus'], $pages, $terms);
        $front = $pages[$site['front_page']];
        self::settings($site['settings'] + ['reading' => []], $front);

        return [
            'pages' => count($pages),
            'posts' => count($site['posts']),
            'terms' => array_sum(array_map('count', $terms)),
            'forms' => 1,
            'menus' => $menus,
            'images' => count($media),
            'front_page' => $front,
        ];
    }

    /**
     * Swap "@image:<key>" for the id of that image and "@form" for the
     * form's id. A node whose image is missing (no GD) loses it: an image
     * block goes, media and text keeps only its blocks, a cover its colour.
     *
     * @param list<array<string, mixed>> $nodes
     * @param array<string, int> $media image key => media id
     * @return list<array<string, mixed>>
     */
    public static function resolve(array $nodes, array $media, int $formId): array
    {
        $out = [];
        foreach ($nodes as $node) {
            $missing = false;
            foreach ($node['props'] as $name => $value) {
                if ($value === '@form') {
                    $node['props'][$name] = (string) $formId;
                } elseif (is_string($value) && str_starts_with($value, '@image:')) {
                    $id = $media[substr($value, 7)] ?? null;
                    if ($id === null) {
                        unset($node['props'][$name]);
                        $missing = true;
                    } else {
                        $node['props'][$name] = $id;
                    }
                }
            }
            $node['children'] = self::resolve($node['children'], $media, $formId);
            if ($missing && $node['type'] === 'core/image') {
                continue;
            }
            if ($missing && $node['type'] === 'core/media-text') {
                array_push($out, ...$node['children']);
                continue;
            }
            $out[] = $node;
        }

        return $out;
    }

    /**
     * Paint an image spec (see content/bakery.php) as a JPEG.
     *
     * @param array{background: string, shapes: list<array{0: string, 1: list<int>, 2: string}>} $spec
     */
    public static function paint(array $spec): string
    {
        $s = self::SUPERSAMPLE;
        $big = imagecreatetruecolor(self::WIDTH * $s, self::HEIGHT * $s);
        imagefill($big, 0, 0, self::colour($big, $spec['background']));
        foreach ($spec['shapes'] as [$shape, $at, $hex]) {
            $c = self::colour($big, $hex);
            $p = array_map(static fn(int $v): int => $v * $s, $at);
            match ($shape) {
                'rect' => imagefilledrectangle($big, $p[0], $p[1], $p[2], $p[3], $c),
                'ellipse' => imagefilledellipse($big, $p[0], $p[1], $p[2], $p[3], $c),
                'poly' => imagefilledpolygon($big, $p, $c),
                'line' => imagesetthickness($big, $p[4]) && imageline($big, $p[0], $p[1], $p[2], $p[3], $c) && imagesetthickness($big, 1),
                default => throw new RuntimeException("Unknown shape \"{$shape}\" in the demo images: use rect, ellipse, poly or line."),
            };
        }
        $img = imagecreatetruecolor(self::WIDTH, self::HEIGHT);
        imagecopyresampled($img, $big, 0, 0, 0, 0, self::WIDTH, self::HEIGHT, self::WIDTH * $s, self::HEIGHT * $s);
        ob_start();
        imagejpeg($img, null, 84);

        return (string) ob_get_clean();
    }

    /** UTC "Y-m-d H:i:s", $days before now at 8:30 in the morning. */
    public static function daysAgo(int $days): string
    {
        return Clock::now(new \DateTimeZone('UTC'))->modify("-{$days} days")->setTime(8, 30)->format('Y-m-d H:i:s');
    }

    /**
     * @param array<string, array<string, mixed>> $specs
     * @return array<string, int> key => media id
     */
    private static function images(Cms $cms, Principal $author, array $specs): array
    {
        $library = $cms->container->make(MediaLibrary::class);
        $ids = [];
        foreach ($specs as $key => $spec) {
            $file = tempnam(sys_get_temp_dir(), 'kestello-demo-');
            if ($file === false || file_put_contents($file, self::paint($spec)) === false) {
                throw new RuntimeException('Could not write a demo image to the temporary folder ' . sys_get_temp_dir() . '.');
            }
            $ids[$key] = (int) $library->addFile($author, $file, (string) $spec['filename'], ['alt' => (string) $spec['alt']])->getKey();
        }

        return $ids;
    }

    /**
     * @param array<string, list<string>> $names taxonomy => term names
     * @return array<string, array<string, int>> taxonomy => name => term id
     */
    private static function terms(Cms $cms, Principal $author, array $names): array
    {
        $terms = new Terms();
        $ids = [];
        foreach ($names as $taxonomy => $list) {
            $tax = $cms->content->getTaxonomy($taxonomy);
            if ($tax === null) {
                throw new RuntimeException("The demo content needs the \"{$taxonomy}\" taxonomy, and this site has none.");
            }
            foreach ($list as $name) {
                $ids[$taxonomy][$name] = (int) $terms->create($tax, ['name' => $name], $author)->getKey();
            }
        }

        return $ids;
    }

    /**
     * Create the menus and put each in its location, when the theme has it.
     *
     * @param list<array{name: string, location: string, items: list<list<string>>}> $specs
     * @param array<string, int> $pages
     * @param array<string, array<string, int>> $terms
     * @return int menus created
     */
    private static function menus(Cms $cms, array $specs, array $pages, array $terms): int
    {
        $menus = $cms->container->make(Menus::class);
        foreach ($specs as $spec) {
            $items = array_map(static fn(array $i): array => match ($i[0]) {
                'page' => ['kind' => 'entry', 'ref' => $pages[$i[1]]],
                'term' => ['kind' => 'term', 'ref' => $terms[$i[1]][$i[2]]],
                'archive' => ['kind' => 'archive', 'ref' => $i[1], 'label' => $i[2]],
                default => throw new RuntimeException("Unknown menu item \"{$i[0]}\" in the demo content: use page, term or archive."),
            }, $spec['items']);
            $id = $menus->save(null, $spec['name'], $items);
            if ($cms->menus->has($spec['location'])) {
                $menus->assign($spec['location'], $id);
            }
        }

        return count($specs);
    }

    /** @param array<string, array<string, string>> $groups group => key => value */
    private static function settings(array $groups, int $frontPage): void
    {
        $groups['reading']['front_page'] = (string) $frontPage;
        foreach ($groups as $group => $values) {
            Settings::getInstance()->setMany($values, $group);
        }
    }

    private static function type(Cms $cms, string $name): ContentType
    {
        return $cms->content->get($name) ?? throw new RuntimeException("The demo content needs the \"{$name}\" content type, and this site has none.");
    }

    private static function colour(\GdImage $img, string $hex): int
    {
        [$r, $g, $b] = sscanf($hex, '#%02x%02x%02x') ?? [0, 0, 0];

        return (int) imagecolorallocate($img, (int) $r, (int) $g, (int) $b);
    }
}
