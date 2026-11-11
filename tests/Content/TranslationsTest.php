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

    protected function setUp(): void
    {
        Model::resetRegistry();
        parent::setUp();
        foreach ([new LanguagesModule(), new ContentModule(), new RedirectsModule(), new SiteModule()] as $module) {
            $module->register($this->cms->container);
            $module->boot($this->cms);
        }
        ContentModule::finalize($this->cms);
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
}
