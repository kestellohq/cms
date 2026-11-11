<?php

declare(strict_types=1);

namespace Kestello\Tests\Content;

use Kestello\Cms\Content\ContentModule;
use Kestello\Cms\I18n\ContentLanguages;
use Kestello\Cms\I18n\LanguagesModule;
use Kestello\Cms\Menus\Menus;
use Kestello\Framework\Database\Model;
use Kestello\Framework\Settings\Settings;
use Kestello\Support\Translator;
use Kestello\Tests\Auth\AuthTestCase;

/** Languages and translations through the API the admin uses. */
final class TranslationsApiTest extends AuthTestCase
{
    protected function setUp(): void
    {
        Model::resetRegistry();
        parent::setUp();
        foreach ([new LanguagesModule(), new ContentModule()] as $module) {
            $module->register($this->cms->container);
            $module->boot($this->cms);
        }
        $this->cms->menus->location('primary', 'Main navigation');
        ContentModule::finalize($this->cms);
        $this->createUser();
        $this->assertSame(200, $this->api('POST', '/auth/session', ['login' => 'ana', 'password' => self::PASSWORD])->statusCode());
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        Translator::reset();
        Model::resetRegistry();
    }

    /** @return array<string, mixed> */
    private function ok(string $method, string $path, array $body = []): array
    {
        $r = $this->api($method, $path, $body);
        $this->assertSame(200, $r->statusCode(), $r->content());

        return $this->json($r)['data'];
    }

    /** @return array<string, list<string>> field errors of a refused request */
    private function refused(string $method, string $path, array $body, int $status = 422): array
    {
        $r = $this->api($method, $path, $body);
        $this->assertSame($status, $r->statusCode(), $r->content());

        return $this->json($r)['errors'] ?? [];
    }

    public function testLanguagesAreChosenUnderGeneral(): void
    {
        $this->ok('PATCH', '/settings', ['general' => ['languages' => 'ES, pt_BR, en, es']]);
        $this->assertSame('es,pt-br', Settings::getInstance()->get('languages'), 'cleaned up: written as in addresses, no main language, no repeats');
        $this->assertSame(['es', 'pt-br'], ContentLanguages::others());

        $this->ok('POST', '/content/page/entries', ['title' => 'De', 'status' => 'published']);
        $errors = $this->refused('PATCH', '/settings', ['general' => ['languages' => 'es, de, api, not a code']]);
        $text = implode(' ', $errors['general.languages'] ?? []);
        $this->assertStringContainsString('/de already belongs to the page "De"', $text, 'a page lives there');
        $this->assertStringContainsString('/api already belongs to the site itself', $text);
        $this->assertStringContainsString('"not a code" isn\'t a language code', $text);
        $this->assertSame('es,pt-br', Settings::getInstance()->get('languages'), 'nothing saved');

        $this->ok('PATCH', '/settings', ['general' => ['languages' => '']]);
        $this->assertSame([], ContentLanguages::others(), 'all removed');
    }

    public function testTheLanguagesAreListedWithTheirEntries(): void
    {
        $this->ok('PATCH', '/settings', ['general' => ['languages' => 'es']]);
        $about = $this->ok('POST', '/content/page/entries', ['title' => 'About']);
        $this->ok('POST', "/content/page/entries/{$about['id']}/translations", ['locale' => 'es']);

        $data = $this->ok('GET', '/content/languages');
        $this->assertSame([['', true, '', 1], ['es', false, '/es', 1]], array_map(static fn(array $l) => [$l['code'], $l['main'], $l['prefix'], $l['entries']], $data['languages']));
        $codes = array_column($data['choices'], 'code');
        $this->assertContains('es', $codes);
        $this->assertNotContains('en', $codes, 'not the main language');
    }

    public function testAnEntryIsTranslated(): void
    {
        $this->ok('PATCH', '/settings', ['general' => ['languages' => 'es']]);
        $about = $this->ok('POST', '/content/page/entries', ['title' => 'About', 'status' => 'published']);
        $this->assertSame(['', []], [$about['locale'], $about['translations']]);

        $es = $this->ok('POST', "/content/page/entries/{$about['id']}/translations", ['locale' => 'es']);
        $this->assertSame(['es', 'draft', 'About', '/es/about'], [$es['locale'], $es['status'], $es['title'], $es['path']]);
        $this->assertSame([['locale' => '', 'id' => $about['id'], 'status' => 'published', 'title' => 'About']], $es['translations']);
        $this->assertSame([['locale' => 'es', 'id' => $es['id'], 'status' => 'draft', 'title' => 'About']], $this->ok('GET', "/content/page/entries/{$about['id']}")['translations']);

        $this->refused('POST', "/content/page/entries/{$about['id']}/translations", ['locale' => 'es'], 409);
        $this->assertArrayHasKey('locale', $this->refused('POST', "/content/page/entries/{$about['id']}/translations", ['locale' => 'fr']));

        $direct = $this->ok('POST', '/content/page/entries', ['title' => 'Contacto', 'locale' => 'es']);
        $this->assertSame('es', $direct['locale'], 'written in Spanish from the start');
        $this->assertArrayHasKey('locale', $this->refused('POST', '/content/page/entries', ['title' => 'Kontakt', 'locale' => 'de']));
        $updated = $this->ok('PATCH', "/content/page/entries/{$direct['id']}", ['title' => 'Contacto 2']);
        $this->assertSame('es', $updated['locale'], 'a language stays');
        $spanish = array_column($this->ok('GET', '/content/lookup?types=page&locale=es'), 'title');
        sort($spanish);
        $this->assertSame(['About', 'Contacto 2'], $spanish, 'parents in the same language: the translation of About, and Contacto');
        $this->assertSame(['About'], array_column($this->ok('GET', '/content/lookup?types=page&locale=main&search=About'), 'title'));

        $list = fn(string $query): array => array_column($this->ok('GET', '/content/page/entries?' . $query), 'title');
        $this->assertSame(['About', 'About', 'Contacto 2'], $list('sort=id'), 'every language by default');
        $this->assertSame(['About'], $list('sort=id&locale=main'), 'the main language');
        $this->assertSame(['About'], $list('sort=id&locale='), 'the main language, written empty');
        $this->assertSame(['About', 'Contacto 2'], $list('sort=id&locale=es'));
        $rows = $this->ok('GET', '/content/page/entries?sort=id&locale=main');
        $this->assertSame(['es'], array_column($rows[0]['translations'], 'locale'), 'each row names its translations');
    }

    public function testEachLanguageCanHaveItsOwnMenu(): void
    {
        $this->ok('PATCH', '/settings', ['general' => ['languages' => 'es']]);
        $about = $this->ok('POST', '/content/page/entries', ['title' => 'About', 'status' => 'published']);
        $contact = $this->ok('POST', '/content/page/entries', ['title' => 'Contact', 'status' => 'published']);
        $sobre = $this->ok('POST', "/content/page/entries/{$about['id']}/translations", ['locale' => 'es']);
        $main = $this->ok('POST', '/menus', ['name' => 'Main', 'items' => [
            ['kind' => 'entry', 'ref' => $about['id']], ['kind' => 'entry', 'ref' => $contact['id']], ['kind' => 'archive', 'ref' => 'post'],
        ]]);
        $this->ok('PUT', '/menus/locations', ['locations' => ['primary' => $main['id']]]);
        $public = fn(string $code): array => array_map(
            static fn(array $i) => [$i['label'], $i['url']],
            ContentLanguages::within($code, fn() => $this->cms->container->make(Menus::class)->forLocations())['primary'],
        );

        $this->assertSame([['About', '/about'], ['Contact', '/contact'], ['Posts', '/blog']], $public(''));
        $this->assertSame([['About', '/es/about'], ['Contact', '/es/contact'], ['Posts', '/es/blog']], $public('es'), 'the main menu, under the prefix: a draft translation is not linked yet');

        $this->ok('PATCH', "/content/page/entries/{$sobre['id']}", ['title' => 'Sobre nosotros', 'slug' => 'sobre', 'status' => 'published']);
        $this->assertSame([['Sobre nosotros', '/es/sobre'], ['Contact', '/es/contact'], ['Posts', '/es/blog']], $public('es'), 'a published translation is');

        $spanish = $this->ok('GET', '/menus?locale=es')['locations'][0];
        $this->assertSame([null, $main['id']], [$spanish['menu_id'], $spanish['main_menu_id']], 'Spanish uses the main menu');

        $principal = $this->ok('POST', '/menus', ['name' => 'Principal', 'items' => [['kind' => 'entry', 'ref' => $sobre['id']]]]);
        $this->assertSame(['primary' => $principal['id']], $this->ok('PUT', '/menus/locations', ['locale' => 'es', 'locations' => ['primary' => $principal['id']]]));
        $this->assertSame([['Sobre nosotros', '/es/sobre']], $public('es'), 'its own menu');
        $this->assertSame([['About', '/about'], ['Contact', '/contact'], ['Posts', '/blog']], $public(''), 'the main language keeps its menu');
        $menus = array_column($this->ok('GET', '/menus')['menus'], null, 'name');
        $this->assertSame([['primary'], [['location' => 'primary', 'locale' => 'es']]], [$menus['Main']['locations'], $menus['Principal']['placements']]);

        $this->assertArrayHasKey('locale', $this->refused('PUT', '/menus/locations', ['locale' => 'fr', 'locations' => ['primary' => $principal['id']]]));
        $this->ok('PUT', '/menus/locations', ['locale' => 'es', 'locations' => ['primary' => null]]);
        $this->assertCount(3, $public('es'), 'back to the main menu');
    }

    public function testCategoriesAreTranslated(): void
    {
        $this->ok('PATCH', '/settings', ['general' => ['languages' => 'es']]);
        $news = $this->ok('POST', '/taxonomies/category/terms', ['name' => 'News']);
        $this->assertSame(['', [], '/category/news'], [$news['locale'], $news['translations'], $news['path']]);

        $es = $this->ok('POST', "/taxonomies/category/terms/{$news['id']}/translations", ['locale' => 'es']);
        $this->assertSame(['es', 'News', 'news', '/es/category/news'], [$es['locale'], $es['name'], $es['slug'], $es['path']], 'slugs are unique per language');
        $this->assertSame([['locale' => '', 'id' => $news['id'], 'title' => 'News']], $es['translations']);
        $this->refused('POST', "/taxonomies/category/terms/{$news['id']}/translations", ['locale' => 'es'], 409);
        $this->assertArrayHasKey('locale', $this->refused('POST', "/taxonomies/category/terms/{$news['id']}/translations", ['locale' => 'fr']));

        $viajes = $this->ok('POST', '/taxonomies/category/terms', ['name' => 'Viajes', 'locale' => 'es']);
        $this->assertSame(['es', 'viajes'], [$viajes['locale'], $viajes['slug']]);
        $this->assertArrayHasKey('locale', $this->refused('POST', '/taxonomies/category/terms', ['name' => 'Reisen', 'locale' => 'de']));
        $this->assertArrayHasKey('parent_id', $this->refused('POST', '/taxonomies/category/terms', ['name' => 'Local', 'locale' => 'es', 'parent_id' => $news['id']]), 'a parent in the same language');
        $this->ok('POST', '/taxonomies/category/terms', ['name' => 'Local', 'parent_id' => $news['id']]);
        $local = $this->ok('GET', '/taxonomies/category/terms?locale=main&search=Local')[0];
        $this->assertSame('es', $this->ok('POST', "/taxonomies/category/terms/{$local['id']}/translations", ['locale' => 'es'])['locale']);
        $spanishLocal = $this->ok('GET', '/taxonomies/category/terms?locale=es&search=Local')[0];
        $this->assertSame($es['id'], $spanishLocal['parent_id'], 'under the translation of its parent');

        $names = fn(string $query): array => array_column($this->ok('GET', '/taxonomies/category/terms?' . $query), 'name');
        $this->assertContains('Viajes', $names('locale=es'));
        $this->assertNotContains('Viajes', $names('locale=main'));
        $this->assertContains('Viajes', $names(''), 'every language by default');
        $rows = array_column($this->ok('GET', '/taxonomies/category/terms?locale=main'), null, 'name');
        $this->assertSame(['es'], array_column($rows['News']['translations'], 'locale'));

        $this->ok('POST', '/content/post/entries', ['title' => 'Hola', 'locale' => 'es', 'terms' => ['tag' => ['Viaje']]]);
        $created = $this->ok('GET', '/taxonomies/tag/terms?locale=es');
        $this->assertSame([['Viaje', 'es']], array_map(static fn(array $t) => [$t['name'], $t['locale']], $created), 'a tag typed on a Spanish post is Spanish');
    }

    public function testMenuCategoryLinksFollowTheLanguage(): void
    {
        $this->ok('PATCH', '/settings', ['general' => ['languages' => 'es']]);
        $news = $this->ok('POST', '/taxonomies/category/terms', ['name' => 'News']);
        $this->ok('POST', '/content/post/entries', ['title' => 'Opening', 'status' => 'published', 'terms' => ['category' => [$news['id']]]]);
        $menu = $this->ok('POST', '/menus', ['name' => 'Main', 'items' => [['kind' => 'term', 'ref' => $news['id']]]]);
        $this->ok('PUT', '/menus/locations', ['locations' => ['primary' => $menu['id']]]);
        $public = fn(string $code): array => array_map(
            static fn(array $i) => [$i['label'], $i['url']],
            ContentLanguages::within($code, fn() => $this->cms->container->make(Menus::class)->forLocations())['primary'],
        );

        $this->assertSame([['News', '/es/category/news']], $public('es'));
        $es = $this->ok('POST', "/taxonomies/category/terms/{$news['id']}/translations", ['locale' => 'es']);
        $this->ok('PATCH', "/taxonomies/category/terms/{$es['id']}", ['name' => 'Noticias', 'slug' => 'noticias']);
        $this->assertSame([['Noticias', '/es/category/noticias']], $public('es'), 'the translation names the link');
        $this->assertSame([['News', '/category/news']], $public(''));
    }
}
