<?php

declare(strict_types=1);

namespace Kestello\Tests\Content;

use Kestello\Cms\Content\ContentException;
use Kestello\Cms\Content\ContentModule;
use Kestello\Cms\Content\Entries;
use Kestello\Cms\Content\Entry;
use Kestello\Cms\Frontend\FrontendModule;
use Kestello\Cms\I18n\ContentLanguages;
use Kestello\Cms\I18n\LanguagePrefix;
use Kestello\Cms\I18n\LanguagesModule;
use Kestello\Cms\I18n\PageLanguages;
use Kestello\Cms\Redirects\RedirectsModule;
use Kestello\Cms\Site\SiteModule;
use Kestello\Contracts\Auth\Principal;
use Kestello\Framework\Core\Request;
use Kestello\Framework\Core\Response;
use Kestello\Framework\Database\Model;
use Kestello\Framework\Settings\Settings;
use Kestello\Support\Translator;
use Kestello\Tests\Auth\AuthTestCase;

/** A site in English and Spanish: /es/… addresses, translations, and the page shown when one is missing. */
final class TranslationsTest extends AuthTestCase
{
    private Entries $entries;
    private Principal $me;
    private object $capture;

    protected function setUp(): void
    {
        Model::resetRegistry();
        parent::setUp();
        \Kestello\Cms\Platform\CoreDrivers::registerSearch($this->cms->drivers);     // the database engine
        foreach ([new LanguagesModule(), new ContentModule(), new RedirectsModule(), new SiteModule(), new \Kestello\Cms\Search\SearchModule()] as $module) {
            $module->register($this->cms->container);
            $module->boot($this->cms);
        }
        ContentModule::finalize($this->cms);
        // innermost: sees each page's languages while its request is still being answered
        $this->cms->routes->pushGlobalMiddleware($this->capture = new class implements \Kestello\Framework\Core\Middleware\MiddlewareInterface {
            /** @var array{languages: list<array<string, mixed>>, alternates: list<array<string, string>>}|null */
            public ?array $seen = null;

            public function handle(Request $request, \Closure $next): Response
            {
                $response = $next($request);
                $this->seen = ['languages' => PageLanguages::all(), 'alternates' => PageLanguages::alternates()];

                return $response;
            }
        });
        $id = $this->createUser();
        $this->assertSame(200, $this->api('POST', '/auth/session', ['login' => 'ana', 'password' => self::PASSWORD])->statusCode());
        Settings::getInstance()->set(ContentLanguages::SETTING, 'es');
        $this->entries = $this->cms->container->make(Entries::class);
        $this->me = new Principal((int) ($id['id'] ?? 1), ['id' => (int) ($id['id'] ?? 1)], 'session', ['*']);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        Translator::reset();
        Model::resetRegistry();
    }

    private function visit(string $uri): Response
    {
        $this->nextRequest();
        parse_str((string) parse_url($uri, PHP_URL_QUERY), $query);

        return $this->cms->routes->dispatch(new Request('GET', $uri, (string) parse_url($uri, PHP_URL_PATH), [], $query, [], [], [], ['REMOTE_ADDR' => '203.0.113.7'], ''));
    }

    /** @return array{0: int, 1: ?string} */
    private function goesTo(string $uri): array
    {
        $r = $this->visit($uri);

        return [$r->statusCode(), $r->headers()['Location'] ?? null];
    }

    /** @return array<string, mixed> the page's props */
    private function page(string $uri): array
    {
        $r = $this->visit($uri);
        $this->assertSame(200, $r->statusCode(), $uri);
        $this->assertNotNull($r->payload(), $uri);

        return $r->payload()->props;
    }

    /**
     * Where the page at $uri is in each language: code => [url, missing], and its hreflang alternates.
     *
     * @return array{0: array<string, array{0: string, 1: bool}>, 1: array<string, string>}
     */
    private function languagesOf(string $uri): array
    {
        $this->assertSame(200, $this->visit($uri)->statusCode(), $uri);
        $seen = $this->capture->seen;

        return [
            array_combine(array_column($seen['languages'], 'code'), array_map(static fn(array $l) => [$l['url'], $l['missing']], $seen['languages'])),
            array_combine(array_column($seen['alternates'], 'hreflang'), array_column($seen['alternates'], 'href')),
        ];
    }

    private function publish(string $type, array $input): Entry
    {
        return $this->entries->create($this->cms->content->get($type), $input + ['status' => 'published'], $this->me);
    }

    /** $source in Spanish, published with $input. */
    private function inSpanish(Entry $source, array $input): Entry
    {
        $copy = $this->entries->translate($source, 'es', $this->me);

        return $this->entries->update($copy, $input + ['status' => 'published'], $this->me);
    }

    public function testEachLanguageHasItsOwnAddresses(): void
    {
        $about = $this->publish('page', ['title' => 'About']);
        $sobre = $this->inSpanish($about, ['title' => 'Sobre nosotros', 'slug' => 'sobre-nosotros']);
        $this->assertSame(['', 'es'], [$about->language(), $sobre->language()]);
        $this->assertSame($about->fresh()->translation_group, $sobre->translation_group, 'linked as translations');
        $this->assertSame('/es/sobre-nosotros', $sobre->url());

        $this->assertSame('About', $this->page('/about')['entry']['title']);
        $es = $this->page('/es/sobre-nosotros');
        $this->assertSame('Sobre nosotros', $es['entry']['title']);
        $this->assertArrayNotHasKey('translation_missing', $es);
        $this->assertSame(['', 'en'], [ContentLanguages::current(), Translator::locale()], 'the next request starts in the main language again');

        $this->assertSame([301, '/es/sobre-nosotros'], $this->goesTo('/es/about'), 'the main address in Spanish leads to the translation');
        $this->assertSame([301, '/es/sobre-nosotros?ref=mail'], $this->goesTo('/es/about?ref=mail'));
        $this->assertSame([404, null], $this->goesTo('/sobre-nosotros'), 'a Spanish slug is not an English address');
    }

    public function testAMissingTranslationShowsTheOriginalMarked(): void
    {
        $this->publish('page', ['title' => 'Contact']);
        $page = $this->page('/es/contact');
        $this->assertSame('Contact', $page['entry']['title']);
        $this->assertTrue($page['translation_missing']);
        $this->assertTrue($page['seo']['noindex'] ?? false, 'not for search engines');
        $this->assertSame([404, null], $this->goesTo('/es/nothing'));
    }

    public function testSlugsAreUniquePerLanguage(): void
    {
        $news = $this->publish('post', ['title' => 'News']);
        $noticias = $this->inSpanish($news, []);
        $this->assertSame(['news', 'news'], [(string) $news->slug, (string) $noticias->slug], 'the same slug in two languages');
        $this->assertSame(['/blog/news', '/es/blog/news'], [$news->url(), $noticias->url()]);
        $this->assertSame('news-2', (string) $this->publish('post', ['title' => 'News'])->slug, 'still unique within a language');
    }

    public function testListsShowThePageLanguageOnly(): void
    {
        $hello = $this->publish('post', ['title' => 'Hello', 'published_at' => '2026-01-01T10:00:00Z']);
        $this->inSpanish($hello, ['title' => 'Hola', 'slug' => 'hola', 'published_at' => '2026-01-01T10:00:00Z']);
        $this->publish('post', ['title' => 'Only English', 'published_at' => '2026-01-02T10:00:00Z']);

        $this->assertSame(['Only English', 'Hello'], array_column($this->page('/blog')['entries'], 'title'));
        $es = $this->page('/es/blog');
        $this->assertSame(['Hola'], array_column($es['entries'], 'title'));
        $this->assertSame('/es/blog', $es['type']['path'], 'pagination links stay in Spanish');
        $this->assertSame('/es/blog', $es['seo']['canonical']);
    }

    public function testTheHomePageInEachLanguage(): void
    {
        $home = $this->publish('page', ['title' => 'Home']);
        Settings::getInstance()->set('front_page', (string) $home->getKey(), 'reading');
        $this->assertSame('Home', $this->page('/es')['entry']['title'], 'no translation yet: the original, marked');
        $this->assertTrue($this->page('/es')['translation_missing']);

        $inicio = $this->inSpanish($home, ['title' => 'Inicio', 'slug' => 'inicio']);
        $this->assertSame('/es', $inicio->url());
        $page = $this->page('/es');
        $this->assertSame('Inicio', $page['entry']['title']);
        $this->assertArrayNotHasKey('translation_missing', $page);
        $this->assertSame([301, '/es'], $this->goesTo('/es/inicio'), 'one address per page');
        $this->assertSame([301, '/'], $this->goesTo('/home'));
    }

    public function testTranslationsKeepTheirLanguageApart(): void
    {
        $about = $this->publish('page', ['title' => 'About']);
        $this->inSpanish($about, []);
        $this->assertThrows(ContentException::class, fn() => $this->entries->translate($about, 'es', $this->me), 'one translation per language');
        $this->assertThrows(ContentException::class, fn() => $this->entries->translate($about, 'fr', $this->me), 'only the site\'s languages');
        $this->assertThrows(ContentException::class, fn() => $this->entries->translate($about, '', $this->me), 'already in the main language');

        $team = $this->entries->create($this->cms->content->get('page'), ['title' => 'Equipo', 'locale' => 'es'], $this->me);
        try {
            $this->entries->update($team, ['parent_id' => $about->getKey()], $this->me);
            $this->fail('a Spanish page under an English one');
        } catch (ContentException $e) {
            $this->assertStringContainsString('same language', json_encode($e->errors ?? $e->getMessage()) ?: '');
        }

        $this->assertSame('es-2', (string) $this->publish('page', ['title' => 'ES'])->slug, 'a top-level page cannot take a language code');
    }

    public function testThemesAreToldTheLanguage(): void
    {
        $this->assertSame(['en', ''], [FrontendModule::shared($this->cms)['i18n']['locale'], FrontendModule::shared($this->cms)['i18n']['prefix']]);
        $es = ContentLanguages::within('es', fn() => FrontendModule::shared($this->cms)['i18n']);
        $this->assertSame(['es', '/es'], [$es['locale'], $es['prefix']], 'interface text and links in Spanish');
    }

    public function testTheSiteOwnAddressesHaveNoLanguage(): void
    {
        $seen = [];
        $next = static function (Request $r) use (&$seen): Response {
            $seen[] = [$r->path, ContentLanguages::current()];

            return new Response('');
        };
        $prefix = new LanguagePrefix();
        $prefix->handle(new Request('GET', '/es/admin/content', '/es/admin/content', [], [], [], [], [], []), $next);
        $prefix->handle(new Request('GET', '/es/about', '/es/about', [], [], [], [], [], []), $next);
        $prefix->handle(new Request('GET', '/fr/about', '/fr/about', [], [], [], [], [], []), $next);
        $this->assertSame([['/es/admin/content', ''], ['/about', 'es'], ['/fr/about', '']], $seen);
    }

    public function testCategoriesHaveALanguageToo(): void
    {
        $terms = new \Kestello\Cms\Content\Terms();
        $category = $this->cms->content->getTaxonomy('category');
        $news = $terms->create($category, ['name' => 'News'], $this->me);
        $hello = $this->publish('post', ['title' => 'Hello', 'terms' => ['category' => [(int) $news->getKey()]]]);
        $this->inSpanish($hello, ['title' => 'Hola', 'slug' => 'hola']);

        $this->assertSame(['Hola'], array_column($this->page('/es/category/news')['entries'], 'title'), 'not translated yet: the main category, listing Spanish posts');
        $this->assertSame(['Hello'], array_column($this->page('/category/news')['entries'], 'title'));

        $noticias = $terms->translate($category, $news, 'es', $this->me);
        $terms->update($category, $noticias, ['name' => 'Noticias', 'slug' => 'noticias'], $this->me);
        $this->assertSame([301, '/es/category/noticias'], $this->goesTo('/es/category/news'), 'the translation has its own address');
        $page = $this->page('/es/category/noticias');
        $this->assertSame(['Noticias', ['Hola'], '/es/category/noticias'], [$page['title'], array_column($page['entries'], 'title'), $page['seo']['canonical']], 'the Spanish post still carries the English category, and counts');
        $this->assertSame(404, $this->visit('/category/noticias')->statusCode(), 'a Spanish category is not in English');

        $again = $this->entries->translate($this->publish('post', ['title' => 'Second', 'terms' => ['category' => [(int) $news->getKey()]]]), 'es', $this->me);
        $this->assertSame([(int) $noticias->getKey()], (new \Kestello\Cms\Content\Terms())->idsFor([(int) $again->getKey()])[(int) $again->getKey()]['category'], 'a new translation takes the translated category');
    }

    public function testEachPageKnowsItsOtherLanguages(): void
    {
        $about = $this->publish('page', ['title' => 'About']);
        $this->assertSame([['en' => ['/about', false], 'es' => ['/es/about', true]], []], $this->languagesOf('/about'), 'not translated: the original in Spanish, and no alternates');
        $this->assertSame(['en' => ['/about', false], 'es' => ['/es/about', true]], $this->languagesOf('/es/about')[0], 'the stand-in knows it too');

        $this->inSpanish($about, ['title' => 'Sobre', 'slug' => 'sobre']);
        $both = [['en' => ['/about', false], 'es' => ['/es/sobre', false]], ['en' => '/about', 'es' => '/es/sobre', 'x-default' => '/about']];
        $this->assertSame($both, $this->languagesOf('/about'));
        $this->assertSame($both, $this->languagesOf('/es/sobre'));

        $this->publish('post', ['title' => 'Hello']);
        $this->assertSame(['en' => ['/blog', false], 'es' => ['/es/blog', false]], $this->languagesOf('/es/blog')[0], 'a list is at the same address in each language');

        $spanishOnly = $this->entries->create($this->cms->content->get('page'), ['title' => 'Solo', 'locale' => 'es', 'status' => 'published'], $this->me);
        $this->assertSame(['en' => ['/', true], 'es' => ['/es/solo', false]], $this->languagesOf((string) $spanishOnly->url())[0], 'nothing in English: its home page');
    }

    public function testTheSitemapListsTranslationsAsAlternates(): void
    {
        $about = $this->publish('page', ['title' => 'About']);
        $this->inSpanish($about, ['title' => 'Sobre', 'slug' => 'sobre']);
        $this->publish('page', ['title' => 'Only English']);
        Settings::getInstance()->set('site_url', 'https://studio.test');
        $xml = $this->visit('/sitemaps/page-1.xml')->content();
        $this->assertStringContainsString('xmlns:xhtml="http://www.w3.org/1999/xhtml"', $xml);
        $this->assertSame(2, substr_count($xml, '<xhtml:link rel="alternate" hreflang="es" href="https://studio.test/es/sobre"/>'), 'on both translations');
        $this->assertSame(2, substr_count($xml, 'hreflang="x-default" href="https://studio.test/about"'));
        $this->assertSame(6, substr_count($xml, '<xhtml:link'), 'none for a page in one language');

        $this->publish('post', ['title' => 'Hello']);
        $this->entries->create($this->cms->content->get('post'), ['title' => 'Hola', 'locale' => 'es', 'status' => 'published'], $this->me);
        $posts = $this->visit('/sitemaps/post-1.xml')->content();
        $this->assertStringContainsString('<loc>https://studio.test/es/blog</loc>', $posts, 'the archive in each language with posts');
    }

    public function testAnAddressSaysItsLanguage(): void
    {
        $this->assertSame(
            [['es', '/about'], ['es', '/'], ['', '/es/admin/x'], ['', '/fr/about'], ['es', '/search'], ['es', '/feed'], ['es', '/author/ana']],
            array_map(LanguagePrefix::detect(...), ['/es/about', '/es', '/es/admin/x', '/fr/about', '/es/search', '/es/feed', '/es/author/ana']),
            'the site\'s own addresses have no language; its public search, feeds and author pages do',
        );
        $inSpanish = LanguagePrefix::run('es', '/missing', static fn() => [ContentLanguages::current(), Translator::locale(), PageLanguages::all()[1]['url']]);
        $this->assertSame(['es', 'es', '/es/missing'], $inSpanish, 'an error page renders in the address\'s language');
    }

    public function testSearchAndFeedsAreInThePageLanguage(): void
    {
        $hello = $this->publish('post', ['title' => 'Studio notes']);
        $this->inSpanish($hello, ['title' => 'Notas del studio', 'slug' => 'notas']);
        $this->cms->search->syncNow(array_map('intval', $this->db->table('entries')->pluck('id')));
        $titles = fn(string $code, array $options = []): array => array_column(ContentLanguages::within($code, fn() => $this->cms->search->search('studio', $options))['hits'], 'title');

        $this->assertSame(['Studio notes'], $titles(''));
        $this->assertSame(['Notas del studio'], $titles('es'), '/es/search finds Spanish entries');
        $every = $titles('', ['language' => null]);
        sort($every);
        $this->assertSame(['Notas del studio', 'Studio notes'], $every, 'or every language, when asked');
        $this->assertSame(200, $this->visit('/es/search?q=studio')->statusCode());

        Settings::getInstance()->set('site_url', 'https://studio.test');
        $feed = $this->visit('/es/feed')->content();
        $this->assertStringContainsString('<language>es</language>', $feed);
        $this->assertStringContainsString('<link>https://studio.test/es/blog/notas</link>', $feed, 'Spanish posts at Spanish addresses');
        $this->assertStringNotContainsString('Studio notes', $feed);
        $this->assertStringContainsString('href="https://studio.test/es/feed"', $feed, 'its own address');
        $main = $this->visit('/feed')->content();
        $this->assertStringContainsString('<language>en</language>', $main);
        $this->assertStringNotContainsString('Notas del studio', $main);
    }
}
