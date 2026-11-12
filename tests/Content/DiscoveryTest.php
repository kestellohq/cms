<?php

declare(strict_types=1);

namespace Kestello\Tests\Content;

use Kestello\Cms\Content\ContentModule;
use Kestello\Cms\Site\Seo;
use Kestello\Cms\Site\SiteModule;
use Kestello\Framework\Core\Request;
use Kestello\Framework\Core\Response;
use Kestello\Framework\Database\Model;
use Kestello\Tests\Auth\AuthTestCase;

/** Head tags, feeds, sitemap.xml and robots.txt. */
final class DiscoveryTest extends AuthTestCase
{
    protected function setUp(): void
    {
        Model::resetRegistry();
        parent::setUp();
        foreach ([new ContentModule(), new SiteModule(), new \Kestello\Cms\Blocks\BlocksModule(), new \Kestello\Cms\Search\SearchModule(), new \Kestello\Cms\Media\MediaModule()] as $module) {
            $module->register($this->cms->container);
            $module->boot($this->cms);
        }
        ContentModule::finalize($this->cms);
        $this->createUser();
        $this->assertSame(200, $this->api('POST', '/auth/session', ['login' => 'ana', 'password' => self::PASSWORD])->statusCode());
        $this->settings(['general' => ['site_name' => 'Bakery', 'site_url' => 'https://bakery.test/', 'site_description' => 'Bread, daily.']]);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        Model::resetRegistry();
    }

    private function settings(array $groups): void
    {
        $r = $this->api('PATCH', '/settings', $groups);
        $this->assertSame(200, $r->statusCode(), $r->content());
    }

    private function visit(string $uri): Response
    {
        $this->nextRequest();
        parse_str((string) parse_url($uri, PHP_URL_QUERY), $query);

        return $this->cms->routes->dispatch(new Request('GET', $uri, (string) parse_url($uri, PHP_URL_PATH), [], $query, [], [], [], ['REMOTE_ADDR' => '203.0.113.7'], ''));
    }

    /** The <head> tags the page at $uri gets. */
    private function head(string $uri): string
    {
        $page = $this->visit($uri)->payload();
        $this->assertNotNull($page, "{$uri} renders a page");

        return Seo::head($this->cms, ['component' => $page->component, 'props' => $page->props + ['site' => ['name' => 'Bakery']], 'url' => $uri]);
    }

    private function post(array $body): array
    {
        $r = $this->api('POST', '/content/post/entries', $body + ['status' => 'published']);
        $this->assertSame(200, $r->statusCode(), $r->content());

        return $this->json($r)['data'];
    }

    private function xml(string $uri): \SimpleXMLElement
    {
        $r = $this->visit($uri);
        $this->assertSame(200, $r->statusCode(), $uri);
        $xml = simplexml_load_string($r->content());
        $this->assertInstanceOf(\SimpleXMLElement::class, $xml, "{$uri} is well-formed XML");

        return $xml;
    }

    public function testPagesDescribeThemselves(): void
    {
        $this->post(['title' => 'Rye "sour" <dough>', 'slug' => 'rye', 'excerpt' => 'Three days, one loaf.']);
        $head = $this->head('/blog/rye');
        foreach ([
            '<link rel="canonical" href="https://bakery.test/blog/rye">',
            '<meta name="description" content="Three days, one loaf.">',
            '<meta property="og:type" content="article">',
            '<meta property="og:title" content="Rye &quot;sour&quot; &lt;dough&gt;">',
            '<link rel="alternate" type="application/rss+xml" title="Posts - Bakery" href="/blog/feed">',
            '<meta name="twitter:card" content="summary">',
        ] as $tag) {
            $this->assertStringContainsString($tag, $head);
        }
        $this->assertFalse(str_contains($head, 'noindex'));

        $this->post(['title' => 'Staff notes', 'slug' => 'staff', 'data' => ['body' => '<p>Ovens on at <strong>four</strong>.</p><p>Flour in the back.</p>', 'seo' => ['noindex' => true]]]);
        $head = $this->head('/blog/staff');
        $this->assertStringContainsString('<meta name="robots" content="noindex">', $head, 'an entry can ask to stay out of search');
        $this->assertStringContainsString('content="Ovens on at four. Flour in the back."', $head, 'no excerpt: the first lines');

        $this->assertStringContainsString('<link rel="canonical" href="https://bakery.test/blog">', $this->head('/blog'));
        $this->assertStringContainsString('<meta name="description" content="Dated articles listed on the blog.">', $this->head('/blog'), 'an archive: the type\'s description');
        $this->api('POST', '/content/page/entries', ['title' => 'Opening hours', 'status' => 'published', 'data' => ['body' => [
            ['type' => 'core/heading', 'props' => ['text' => 'Open every day']],
            ['type' => 'core/text', 'props' => ['text' => '<p>From <b>seven</b> until noon.</p>']],
            ['type' => 'core/text', 'props' => ['text' => '<p>Staff code 4471</p>'], 'visibility' => ['auth' => 'user']],
        ]]]);
        $this->assertStringContainsString('<meta name="description" content="Open every day. From seven until noon.">', $this->head('/opening-hours'), 'a page built from blocks: the text every visitor sees');
        $this->api('POST', '/content/page/entries', ['title' => 'Contact', 'status' => 'published']);
        $this->assertStringContainsString('<meta name="description" content="Bread, daily.">', $this->head('/contact'), 'nothing to say: the site description');
        $error = Seo::head($this->cms, ['component' => '@core/errors/Error', 'props' => ['status' => 404], 'url' => '/nope']);
        $this->assertStringContainsString('noindex', $error);
        $this->assertFalse(str_contains($error, 'canonical'));
        $this->assertSame('', Seo::head($this->cms, ['component' => '@core/admin/Dashboard', 'props' => ['admin' => []], 'url' => '/admin']));

        $this->settings(['reading' => ['discourage_search' => true]]);
        $this->assertStringContainsString('<meta name="robots" content="noindex">', $this->head('/blog/rye'), 'a hidden site asks on every page');
    }

    public function testAnEntryChoosesItsSearchTitleAndSharingImage(): void
    {
        $png = (function (): string {
            ob_start();
            imagepng(imagecreatetruecolor(12, 8));
            return (string) ob_get_clean();
        })();
        $up = $this->json($this->api('POST', '/media/uploads', ['filename' => 'loaf.png', 'size' => strlen($png)]))['data'];
        $this->api('PUT', "/media/uploads/{$up['id']}/chunks/0", raw: $png);
        $media = $this->json($this->api('POST', "/media/uploads/{$up['id']}/complete"))['data'];
        $this->api('PATCH', "/media/{$media['id']}", ['alt' => 'A rye loaf']);

        $this->post(['title' => 'Rye', 'slug' => 'rye', 'data' => ['seo' => ['title' => 'Rye bread in three days', 'image' => $media['id']]]]);
        $page = $this->visit('/blog/rye')->payload();
        $this->assertSame('Rye bread in three days', Seo::title($page->props), 'the search title, not the heading');
        $this->assertSame('Rye', $page->props['title'], 'the page still shows its own title');
        $head = $this->head('/blog/rye');
        foreach ([
            '<meta property="og:title" content="Rye bread in three days">',
            '<meta property="og:image:width" content="12">',
            '<meta property="og:image:alt" content="A rye loaf">',
            '<meta name="twitter:card" content="summary_large_image">',
        ] as $tag) {
            $this->assertStringContainsString($tag, $head);
        }
        $this->assertMatchesRegularExpression('#<meta property="og:image" content="https?://[^"]+">#', $head);

        $this->post(['title' => 'Spelt', 'slug' => 'spelt', 'data' => ['seo' => ['title' => '  ']]]);
        $this->assertStringContainsString('<meta property="og:title" content="Spelt">', $this->head('/blog/spelt'), 'an empty search title: the title');

        $this->db->table('media')->where('id', $media['id'])->delete();
        $this->assertFalse(str_contains($this->head('/blog/rye'), 'og:image'), 'a file that is gone is not shared');
    }

    public function testTheSiteChoosesItsTitlePatternAndDefaultSharingImage(): void
    {
        $png = (function (): string {
            ob_start();
            imagepng(imagecreatetruecolor(12, 8));
            return (string) ob_get_clean();
        })();
        $up = $this->json($this->api('POST', '/media/uploads', ['filename' => 'logo.png', 'size' => strlen($png)]))['data'];
        $this->api('PUT', "/media/uploads/{$up['id']}/chunks/0", raw: $png);
        $media = $this->json($this->api('POST', "/media/uploads/{$up['id']}/complete"))['data'];
        $this->post(['title' => 'Rye', 'slug' => 'rye']);
        $title = fn(string $uri) => Seo::documentTitle($this->visit($uri)->payload()->props + ['site' => ['name' => 'Bakery']], $uri);

        $this->assertSame('Rye - Bakery', $title('/blog/rye'), 'out of the box: as before');
        $this->assertFalse(str_contains($this->head('/blog/rye'), 'og:image'));

        $this->settings(['seo' => ['seo_title_pattern' => '{title} {sep} {site}', 'seo_home_pattern' => '{site} {sep} {tagline}', 'seo_separator' => '|', 'seo_image' => $media['id']]]);
        $this->assertSame(['page' => '{title} {sep} {site}', 'home' => '{site} {sep} {tagline}', 'sep' => '|', 'tagline' => 'Bread, daily.'], Seo::titles(), 'what the browser gets as site.titles');
        $this->assertSame('Rye | Bakery', $title('/blog/rye'));
        $this->assertSame('Bakery | Bread, daily.', Seo::documentTitle(['title' => 'About', 'site' => ['name' => 'Bakery']], '/'), 'the home page');
        $this->assertStringContainsString('<meta property="og:image:width" content="12">', $this->head('/blog/rye'), 'no image of its own: the default');

        $seo = array_values(array_filter(\Kestello\Cms\Api\Endpoints\Settings\SiteSettings::describe(), static fn(array $g) => $g['id'] === 'seo'))[0];
        $this->assertSame(['integer', 'media'], [$seo['schema']['properties']['seo_image']['type'], $seo['schema']['properties']['seo_image']['format'] ?? null], 'the settings screen shows a media picker');
        $this->assertSame(422, $this->api('PATCH', '/settings', ['seo' => ['seo_separator' => '<b>']])->statusCode(), 'only the listed separators');
        $this->assertSame(422, $this->api('PATCH', '/settings', ['seo' => ['seo_title_pattern' => '']])->statusCode(), 'a pattern cannot be empty');
    }

    /** The JSON-LD @graph on the page at $uri, by @type ([] when there is none). */
    private function graph(string $uri): array
    {
        $head = $this->head($uri);
        if (preg_match('#<script type="application/ld\+json">(.*?)</script>#s', $head, $m) !== 1) {
            return [];
        }
        $json = json_decode($m[1], true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('https://schema.org', $json['@context']);
        $out = [];
        foreach ($json['@graph'] as $node) {
            $out[$node['@type']] = $node;
        }

        return $out;
    }

    public function testPagesDescribeThemselvesToSearchEngines(): void
    {
        $this->post(['title' => 'Rye', 'slug' => 'rye', 'excerpt' => 'Three days, one loaf.', 'published_at' => '2026-03-02T09:00:00Z']);
        $g = $this->graph('/blog/rye');
        $this->assertSame(['WebSite', 'Organization', 'WebPage', 'Article', 'BreadcrumbList'], array_keys($g));
        $this->assertSame(['https://bakery.test/#website', 'https://bakery.test/', 'Bakery', 'Bread, daily.', 'en'], [$g['WebSite']['@id'], $g['WebSite']['url'], $g['WebSite']['name'], $g['WebSite']['description'], $g['WebSite']['inLanguage']]);
        $this->assertSame(['@id' => 'https://bakery.test/#publisher'], $g['WebSite']['publisher']);
        $this->assertSame(['https://bakery.test/blog/rye#webpage', 'Rye', 'Three days, one loaf.'], [$g['WebPage']['@id'], $g['WebPage']['name'], $g['WebPage']['description']]);
        $this->assertSame(['Rye', ['@type' => 'Person', 'name' => 'ana'], ['@id' => 'https://bakery.test/blog/rye#webpage']], [$g['Article']['headline'], $g['Article']['author'], $g['Article']['mainEntityOfPage']]);
        $this->assertStringStartsWith('2026-03-02T09:00:00', $g['Article']['datePublished']);
        $this->assertTrue(($g['Article']['dateModified'] ?? '') !== '', 'when it last changed');
        $this->assertSame([
            ['@type' => 'ListItem', 'position' => 1, 'name' => 'Bakery', 'item' => 'https://bakery.test/'],
            ['@type' => 'ListItem', 'position' => 2, 'name' => 'Posts', 'item' => 'https://bakery.test/blog'],
            ['@type' => 'ListItem', 'position' => 3, 'name' => 'Rye', 'item' => 'https://bakery.test/blog/rye'],
        ], $g['BreadcrumbList']['itemListElement']);

        $about = $this->json($this->api('POST', '/content/page/entries', ['title' => 'About', 'slug' => 'about', 'status' => 'published']))['data'];
        $this->json($this->api('POST', '/content/page/entries', ['title' => 'Team', 'slug' => 'team', 'status' => 'published', 'parent_id' => $about['id']]));
        $g = $this->graph('/about/team');
        $this->assertArrayNotHasKey('Article', $g, 'a page is not an article');
        $this->assertSame(['Bakery', 'About', 'Team'], array_column($g['BreadcrumbList']['itemListElement'], 'name'), 'its parent pages lead to it');

        $this->assertSame('CollectionPage', array_keys($this->graph('/blog'))[2], 'a list of entries');

        $this->post(['title' => 'Staff notes', 'slug' => 'staff', 'data' => ['seo' => ['noindex' => true]]]);
        $this->assertSame([], $this->graph('/blog/staff'), 'a page that is not listed is not described either');
        $this->assertStringNotContainsString('ld+json', Seo::head($this->cms, ['component' => '@core/errors/Error', 'props' => ['status' => 404], 'url' => '/nope']));
        $this->settings(['reading' => ['discourage_search' => true]]);
        $this->assertSame([], $this->graph('/blog/rye'), 'nor is anything on a hidden site');
    }

    public function testTheHomePageIsSharedAsTheSite(): void
    {
        $home = $this->json($this->api('POST', '/content/page/entries', ['title' => 'Home', 'slug' => 'home', 'status' => 'published']))['data'];
        $this->settings(['reading' => ['front_page' => (string) $home['id']]]);
        $this->assertStringContainsString('<meta property="og:title" content="Bakery">', $this->head('/'), 'not "Home"');

        $this->api('PATCH', "/content/page/entries/{$home['id']}", ['data' => ['seo' => ['title' => 'Bakery on the quay']]]);
        $this->assertStringContainsString('<meta property="og:title" content="Bakery on the quay">', $this->head('/'), 'the editor\'s own sharing title wins');
    }

    public function testAPageOfBlocksIsDescribedInSentences(): void
    {
        $node = static fn(string $type, array $props = [], array $children = []): array => ['type' => $type, 'props' => $props, 'children' => $children];
        $r = $this->api('POST', '/content/page/entries', ['title' => 'Bread', 'slug' => 'bread', 'status' => 'published', 'data' => ['body' => [
            $node('core/heading', ['text' => 'Bread from the oven', 'level' => '1']),
            $node('core/text', ['text' => '<p>Sourdough, rye and sweet buns.</p>']),
            $node('core/buttons', [], [$node('core/button', ['text' => 'See what we bake', 'link' => '/menu'])]),
        ]]]);
        $this->assertSame(200, $r->statusCode(), $r->content());

        $this->assertStringContainsString('<meta name="description" content="Bread from the oven. Sourdough, rye and sweet buns. See what we bake.">', $this->head('/bread'));
    }

    public function testPiecesOfTextReadAsSentences(): void
    {
        $this->assertSame('Fresh bread. Baked daily. Open: Mon to Sat. Why rye? "Slow."', Seo::sentences(['Fresh bread', ' Baked   daily ', '', 'Open:', 'Mon to Sat', 'Why rye?', '"Slow."']));
        $this->assertSame('', Seo::sentences([]));
    }

    public function testStructuredDataCannotBreakOutOfItsScript(): void
    {
        $this->post(['title' => '</script><script>alert(1)</script> & <!-- "x"', 'slug' => 'evil', 'excerpt' => "Ends with </SCRIPT >\u{2028}"]);
        $this->cms->filters->add('seo.schema', static fn(array $nodes) => [
            ...$nodes,
            ['@type' => 'Thing', 'name' => '</script><img src=x onerror=alert(1)>'],
            'not a node', ['name' => 'no type'], ['@type' => ''],
        ]);
        $head = $this->head('/blog/evil');
        $this->assertSame(1, substr_count(strtolower($head), '</script'), 'only the closing tag of the JSON-LD itself');
        $this->assertStringNotContainsString('<!--', $head);
        $g = $this->graph('/blog/evil');
        $this->assertSame('</script><script>alert(1)</script> & <!-- "x"', $g['WebPage']['name'], 'the text survives as text');
        $this->assertSame('</script><img src=x onerror=alert(1)>', $g['Thing']['name']);
        $this->assertSame(['WebSite', 'Organization', 'WebPage', 'Article', 'BreadcrumbList', 'Thing'], array_keys($g), 'nodes without an @type are left out');

        $this->cms->filters->add('seo.schema', static fn() => []);
        $this->assertStringNotContainsString('ld+json', $this->head('/blog/evil'), 'a plugin can remove it all');
    }

    public function testWhoIsBehindTheSite(): void
    {
        $png = (function (): string {
            ob_start();
            imagepng(imagecreatetruecolor(120, 120));
            return (string) ob_get_clean();
        })();
        $up = $this->json($this->api('POST', '/media/uploads', ['filename' => 'ana.png', 'size' => strlen($png)]))['data'];
        $this->api('PUT', "/media/uploads/{$up['id']}/chunks/0", raw: $png);
        $media = $this->json($this->api('POST', "/media/uploads/{$up['id']}/complete"))['data'];
        $this->settings(['seo' => [
            'seo_publisher_type' => 'person', 'seo_publisher_name' => 'Ana Baker', 'seo_publisher_logo' => $media['id'],
            'seo_profiles' => "https://github.com/ana\njavascript:alert(1)\n  https://mastodon.social/@ana  \nnot a url\nhttps://github.com/ana\nhttps://user:pw@evil.test/",
        ]]);
        $this->post(['title' => 'Rye', 'slug' => 'rye']);
        $person = $this->graph('/blog/rye')['Person'];
        $this->assertSame(['Ana Baker', ['https://github.com/ana', 'https://mastodon.social/@ana']], [$person['name'], $person['sameAs']], 'only web addresses, once each');
        $this->assertSame([120, 120], [$person['image']['width'], $person['image']['height']]);
        $this->assertArrayNotHasKey('logo', $person, 'a person has a picture, not a logo');
        $this->assertSame(422, $this->api('PATCH', '/settings', ['seo' => ['seo_publisher_type' => 'robot']])->statusCode());
    }

    public function testEmailsShowTheSitesLogo(): void
    {
        $upload = function (string $name, int $w, int $h): array {
            ob_start();
            imagepng(imagecreatetruecolor($w, $h));
            $png = (string) ob_get_clean();
            $up = $this->json($this->api('POST', '/media/uploads', ['filename' => $name, 'size' => strlen($png)]))['data'];
            $this->api('PUT', "/media/uploads/{$up['id']}/chunks/0", raw: $png);

            return $this->json($this->api('POST', "/media/uploads/{$up['id']}/complete"))['data'];
        };
        $this->assertNull(\Kestello\Cms\Mail\MailBrand::site()->logo, 'no logo anywhere: the name alone');

        $publisher = $upload('publisher.png', 300, 100);
        $this->settings(['seo' => ['seo_publisher_logo' => $publisher['id']]]);
        $logo = \Kestello\Cms\Mail\MailBrand::site()->logo;
        $this->assertSame([300, 100], [$logo['width'] ?? null, $logo['height'] ?? null], 'the publisher logo by default');
        $this->assertStringStartsWith('https://bakery.test/', $logo['url'], 'as a whole address a mail app can fetch');

        $own = $upload('email.png', 64, 64);
        $this->settings(['emails' => ['email_logo' => $own['id']]]);
        $this->assertSame(64, \Kestello\Cms\Mail\MailBrand::site()->logo['width'] ?? null, 'an email logo chosen in Settings → Emails wins');
        $this->assertNull(\Kestello\Cms\Mail\MailBrand::site()->darkLogo, 'no logo for dark mode: the logo stays');

        $light = $upload('email-light.png', 128, 64);
        $this->settings(['emails' => ['email_logo_dark' => $light['id']]]);
        $brand = \Kestello\Cms\Mail\MailBrand::site();
        $this->assertSame([64, 128], [$brand->logo['width'] ?? null, $brand->darkLogo['width'] ?? null], 'a logo for dark mode beside it');
        $html = \Kestello\Cms\Notifications\MailMessage::make('Hi')->heading('Hi')->render($brand);
        $this->assertStringContainsString('<img class="k-logo-dark" src="' . $brand->darkLogo['url'] . '" alt="Bakery" width="80" height="40" style="display:none;', $html);
        $this->assertStringContainsString('.k-logo{display:none!important}.k-logo-dark{display:block!important}}', $html, 'swapped in the dark-mode CSS');

        $this->settings(['emails' => ['email_show_logo' => false]]);
        $this->assertNull(\Kestello\Cms\Mail\MailBrand::site()->logo, 'or none at all');
    }

    public function testAnEntryFirstPublishedElsewhere(): void
    {
        $this->assertSame(422, $this->api('POST', '/content/post/entries', ['title' => 'X', 'status' => 'published', 'data' => ['seo' => ['canonical' => 'javascript:alert(1)']]])->statusCode(), 'only web addresses are saved');
        $this->post(['title' => 'Rye', 'slug' => 'rye', 'data' => ['seo' => ['canonical' => 'https://medium.com/@ana/rye']]]);
        $this->post(['title' => 'Spelt', 'slug' => 'spelt', 'data' => ['seo' => ['canonical' => 'https://bakery.test/blog/spelt/']]]);
        $head = $this->head('/blog/rye');
        $this->assertStringContainsString('<link rel="canonical" href="https://medium.com/@ana/rye">', $head);
        $this->assertStringContainsString('<meta property="og:url" content="https://medium.com/@ana/rye">', $head);
        $this->assertSame('https://bakery.test/blog/rye#webpage', $this->graph('/blog/rye')['WebPage']['@id'], 'the graph still describes this page');

        $sitemap = $this->visit('/sitemaps/post-1.xml')->content();
        $this->assertStringNotContainsString('/blog/rye<', $sitemap, 'a sitemap lists only addresses that are their own canonical');
        $this->assertStringContainsString('https://bakery.test/blog/spelt</loc>', $sitemap, 'naming its own address keeps it');

        // stored some other way (an older import, a database edit): never written out
        $id = $this->db->table('entries')->where('slug', 'rye')->value('id');
        $this->db->table('entries')->where('id', $id)->update(['data' => json_encode(['seo' => ['canonical' => 'javascript:alert(1)']])]);
        $this->assertStringContainsString('<link rel="canonical" href="https://bakery.test/blog/rye">', $this->head('/blog/rye'));
    }

    public function testOtherLanguagesAndTheSiteLanguage(): void
    {
        $this->post(['title' => 'Rye', 'slug' => 'rye']);
        $this->assertStringNotContainsString('hreflang', $this->head('/blog/rye'), 'one language: no alternates');
        $this->cms->filters->add('seo.alternates', static fn(array $list, array $data) => $data['url'] === '/blog/rye' ? [
            ['hreflang' => 'de', 'href' => '/de/blog/roggen'],
            ['hreflang' => 'sr-Latn', 'href' => 'https://bakery.test/sr/blog/raz'],
            ['hreflang' => 'x-default', 'href' => '/blog/rye'],
            ['hreflang' => 'de" onload="alert(1)', 'href' => '/x'],
            ['hreflang' => 'fr', 'href' => 'javascript:alert(1)'],
            ['hreflang' => 'it', 'href' => '//evil.test/x'],
            ['hreflang' => 'es'],
            'nonsense',
        ] : $list);
        $head = $this->head('/blog/rye');
        $this->assertStringContainsString('<link rel="alternate" hreflang="de" href="https://bakery.test/de/blog/roggen">', $head);
        $this->assertStringContainsString('<link rel="alternate" hreflang="sr-Latn" href="https://bakery.test/sr/blog/raz">', $head);
        $this->assertStringContainsString('<link rel="alternate" hreflang="x-default" href="https://bakery.test/blog/rye">', $head);
        $this->assertSame(3, substr_count($head, 'hreflang='), 'invalid tags and addresses are left out');

        $this->assertNull(Seo::ogLocale('en'));
        $this->assertSame(['pt_BR', 'sr_RS', 'de_AT'], [Seo::ogLocale('pt_BR'), Seo::ogLocale('sr_Latn_RS'), Seo::ogLocale('de-at')]);
        $this->assertNull(Seo::ogLocale('"><script>'));
        $this->assertStringContainsString('<meta name="robots" content="max-image-preview:large">', $head, 'listed pages may show large pictures');
    }

    public function testPluginsAddToTheSitemapButOnlyThisSite(): void
    {
        $this->cms->filters->add('sitemap.files', static fn(array $files) => $files + ['shop-1' => new \DateTimeImmutable('2026-01-02T03:04:05Z'), '../etc' => null, 'Shop-1' => null]);
        $this->cms->filters->add('sitemap.urls', static fn(array $urls, string $file) => $file !== 'shop-1' ? $urls : [
            ['loc' => '/shop/bread', 'lastmod' => new \DateTimeImmutable('2026-01-02'), 'images' => ['/media/bread.jpg', 'https://cdn.test/b.jpg', 'javascript:x']],
            ['loc' => 'https://bakery.test/shop/rolls'],
            ['loc' => 'https://evil.test/shop'],
            ['loc' => 'https://bakery.test.evil.test/x'],
            ['loc' => '//evil.test/x'],
            ['loc' => 'javascript:alert(1)'],
            ['nothing' => 1],
        ]);
        $index = str_replace(["\n", ' '], '', $this->visit('/sitemap.xml')->content());
        $this->assertStringContainsString('<loc>https://bakery.test/sitemaps/shop-1.xml</loc><lastmod>2026-01-02T03:04:05+00:00</lastmod>', $index, 'a plugin\'s file is listed');
        $this->assertStringNotContainsString('etc', $index);
        $this->assertStringNotContainsString('Shop-1', $index);

        $xml = $this->visit('/sitemaps/shop-1.xml')->content();
        $this->assertSame(['https://bakery.test/shop/bread', 'https://bakery.test/shop/rolls'], array_map('strval', iterator_to_array((function () use ($xml) {
            foreach (simplexml_load_string($xml)->url as $u) {
                yield (string) $u->loc;
            }
        })(), false)));
        $this->assertStringContainsString('<image:loc>https://bakery.test/media/bread.jpg</image:loc></image:image><image:image><image:loc>https://cdn.test/b.jpg</image:loc>', str_replace(["\n", ' '], '', $xml));
        $this->assertStringNotContainsString('javascript', $xml);
        $this->assertSame(404, $this->visit('/sitemaps/cakes-1.xml')->statusCode(), 'a file nobody fills');
    }

    public function testFiltersCannotSlipInScriptAddresses(): void
    {
        $this->post(['title' => 'Rye', 'slug' => 'rye']);
        $this->cms->filters->add('seo.head', static fn(array $tags) => [...$tags, ['link', ['rel' => 'me', 'href' => 'javascript:alert(1)']], ['link', ['rel' => 'me', 'href' => 'https://mastodon.social/@ana']], ['meta', ['name' => ['x']]], ['link', ['rel' => 'icon', 'href' => '/i.png', 'onload' => 'alert(1)']]]);
        $head = $this->head('/blog/rye');
        $this->assertStringNotContainsString('javascript', $head);
        $this->assertStringNotContainsString('onload', $head, 'no event handler, even from a filter');
        $this->assertStringContainsString('<link rel="me" href="https://mastodon.social/@ana">', $head);
    }

    public function testFeeds(): void
    {
        $news = $this->json($this->api('POST', '/taxonomies/category/terms', ['name' => 'News']))['data'];
        $this->post(['title' => 'Old loaf', 'published_at' => '2026-01-01T09:00:00Z']);
        $this->post(['title' => 'New oven', 'published_at' => '2026-03-01T09:00:00Z', 'data' => ['body' => '<p>It is <em>hot</em>.</p>'], 'terms' => ['category' => [$news['id']]]]);
        $this->post(['title' => 'Secret', 'data' => ['seo' => ['noindex' => true]]]);
        $this->api('POST', '/content/post/entries', ['title' => 'Draft']);

        $rss = $this->xml('/feed');
        $this->assertSame('application/rss+xml; charset=UTF-8', $this->visit('/feed')->headers()['Content-Type']);
        $this->assertSame(['New oven', 'Old loaf'], array_map('strval', $rss->xpath('//item/title')), 'newest first; no drafts, no hidden entries');
        $this->assertSame('https://bakery.test/blog/new-oven', (string) $rss->channel->item[0]->link);
        $this->assertStringContainsString('<em>hot</em>', (string) $rss->channel->item[0]->children('content', true)->encoded);
        $this->assertSame('It is hot.', (string) $rss->channel->item[0]->description);


        $atom = $this->xml('/blog/feed/atom');
        $atom->registerXPathNamespace('a', 'http://www.w3.org/2005/Atom');
        $this->assertSame('Posts - Bakery', (string) $atom->title);
        $this->assertCount(2, $atom->xpath('//a:entry'));
        $this->assertMatchesRegularExpression('/^tag:bakery\.test,2026-03-01:entry-\d+$/', (string) $atom->entry[0]->id);

        $this->assertSame(['New oven'], array_map('strval', $this->xml('/category/news/feed')->xpath('//item/title')), 'a category\'s own feed');
        $this->assertSame(404, $this->visit('/category/nothing/feed')->statusCode());

        $this->settings(['reading' => ['feed_items' => 1]]);
        $this->assertCount(1, $this->xml('/feed')->xpath('//item'));

        $taken = $this->post(['title' => 'Feed']);
        $this->assertFalse('feed' === $taken['slug'], 'a post can\'t take its type\'s feed address');
        // a post built from blocks: readers get its text, never what only members see
        $this->post(['title' => 'Built', 'data' => ['body' => [
            ['type' => 'core/heading', 'props' => ['text' => 'Sourdough & rye', 'level' => '2'], 'children' => []],
            ['type' => 'core/text', 'props' => ['text' => '<p>Baked <strong>daily</strong>.</p>'], 'children' => []],
            ['type' => 'core/text', 'props' => ['text' => '<p>Members price list</p>'], 'children' => [], 'visibility' => ['auth' => 'user']],
        ]]]);
        $item = $this->xml('/feed')->channel->item[0];
        $this->assertSame('Built', (string) $item->title);
        $built = (string) $item->children('content', true)->encoded;
        $this->assertStringContainsString('<h2>Sourdough &amp; rye</h2><p>Baked <strong>daily</strong>.</p>', $built);
        $this->assertStringNotContainsString('Members price list', $built);
    }

    public function testSitemapAndRobots(): void
    {
        $about = $this->json($this->api('POST', '/content/page/entries', ['title' => 'About', 'status' => 'published']))['data'];
        $home = $this->json($this->api('POST', '/content/page/entries', ['title' => 'Welcome', 'status' => 'published']))['data'];
        $this->json($this->api('POST', '/content/page/entries', ['title' => 'Thanks', 'status' => 'published', 'data' => ['seo' => ['noindex' => true]]]));
        $news = $this->json($this->api('POST', '/taxonomies/category/terms', ['name' => 'News']))['data'];
        $this->post(['title' => 'Hello', 'terms' => ['category' => [$news['id']]]]);
        $draft = $this->json($this->api('POST', '/taxonomies/category/terms', ['name' => 'Secret plans']))['data'];
        $this->api('POST', '/content/post/entries', ['title' => 'Unannounced', 'status' => 'draft', 'terms' => ['category' => [$draft['id']]]]);
        $this->settings(['reading' => ['front_page' => (string) $home['id']]]);

        $index = $this->xml('/sitemap.xml');
        $files = array_map('strval', $index->xpath('//*[local-name()="loc"]'));
        $this->assertSame(['https://bakery.test/sitemaps/page-1.xml', 'https://bakery.test/sitemaps/post-1.xml', 'https://bakery.test/sitemaps/category-1.xml'], $files);

        $pages = array_map('strval', $this->xml('/sitemaps/page-1.xml')->xpath('//*[local-name()="loc"]'));
        $this->assertSame(['https://bakery.test/about', 'https://bakery.test/'], $pages, 'the home page at /, hidden pages left out');
        $this->assertSame(['https://bakery.test/blog', 'https://bakery.test/blog/hello'], array_map('strval', $this->xml('/sitemaps/post-1.xml')->xpath('//*[local-name()="loc"]')));
        $this->assertSame(['https://bakery.test/category/news'], array_map('strval', $this->xml('/sitemaps/category-1.xml')->xpath('//*[local-name()="loc"]')));
        $this->assertSame(404, $this->visit('/category/secret-plans')->statusCode(), 'a term only a draft uses has no public page');
        $this->assertSame(404, $this->visit('/sitemaps/page-2.xml')->statusCode());
        $this->assertSame(404, $this->visit('/sitemaps/users-1.xml')->statusCode());
        unset($about);

        $robots = $this->visit('/robots.txt');
        $this->assertSame("User-agent: *\nDisallow: /admin/\nDisallow: /api/\nDisallow: /search\n\nSitemap: https://bakery.test/sitemap.xml\n", $robots->content());
        $this->settings(['reading' => ['discourage_search' => true, 'robots_txt' => "User-agent: GPTBot\r\nDisallow: /"]]);
        $this->assertSame("User-agent: *\nDisallow: /\n\nUser-agent: GPTBot\nDisallow: /\n\nSitemap: https://bakery.test/sitemap.xml\n", $this->visit('/robots.txt')->content());
    }

    public function testCodeSnippets(): void
    {
        $this->settings(['snippets' => ['snippet_head' => '<script async src="https://stats.example/a.js"></script><script nonce="old">go()</script>', 'snippet_footer' => '<p>bye</p>']]);
        $page = ['component' => '@core/content/Entry', 'props' => []];
        $this->assertSame(
            '<script nonce="n0nce" async src="https://stats.example/a.js"></script><script nonce="n0nce">go()</script>' . "\n",
            \Kestello\Cms\Site\Snippets::render('head', $page, 'n0nce'),
            'every script runs under the page\'s nonce',
        );
        $this->assertSame("<p>bye</p>\n", \Kestello\Cms\Site\Snippets::render('footer', $page, 'n'));
        $this->assertSame('', \Kestello\Cms\Site\Snippets::render('body', $page, 'n'), 'nothing set, nothing added');
        foreach (['@core/auth/Login', '@core/installer/Index', '@core/admin/Dashboard'] as $private) {
            $this->assertSame('', \Kestello\Cms\Site\Snippets::render('head', ['component' => $private, 'props' => []], 'n'), "never on {$private}");
        }
        $this->assertSame('', \Kestello\Cms\Site\Snippets::render('head', ['component' => '@theme/Home', 'props' => ['admin' => []]], 'n'));

        // Changing site settings isn't enough: code for every page is for administrators.
        $this->cms->auth->roles->grant('editor', 'settings.manage');
        $this->createUser(['username' => 'eda', 'email' => 'eda@example.test', 'role' => 'editor']);
        $this->api('DELETE', '/auth/session');
        $this->api('POST', '/auth/session', ['login' => 'eda', 'password' => self::PASSWORD]);
        $this->assertSame(403, $this->api('PATCH', '/settings', ['snippets' => ['snippet_head' => '<script>steal()</script>']])->statusCode());
        $groups = array_column($this->json($this->api('GET', '/settings'))['meta']['groups'], 'id');
        $this->assertFalse(in_array('snippets', $groups, true), 'and they don\'t see it');
        $this->assertContains('reading', $groups);
    }
}
