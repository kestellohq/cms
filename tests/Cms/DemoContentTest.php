<?php

declare(strict_types=1);

namespace Kestello\Tests\Cms;

use Kestello\Cms\Blocks\BlocksModule;
use Kestello\Cms\Content\ContentModule;
use Kestello\Cms\Demo\DemoContent;
use Kestello\Cms\Forms\FormsModule;
use Kestello\Cms\I18n\LanguagesModule;
use Kestello\Cms\Media\MediaModule;
use Kestello\Cms\Menus\Menus;
use Kestello\Cms\Redirects\RedirectsModule;
use Kestello\Contracts\Auth\Principal;
use Kestello\Framework\Core\Request;
use Kestello\Framework\Core\Response;
use Kestello\Framework\Database\Model;
use Kestello\Framework\Settings\Settings;
use Kestello\Support\Clock;
use Kestello\Support\Json;
use Kestello\Tests\Auth\AuthTestCase;

/** The public demo's sample studio: what preparing and seeding an empty site create. */
final class DemoContentTest extends AuthTestCase
{
    protected function setUp(): void
    {
        Model::resetRegistry();
        parent::setUp();
        $this->cms->storage->configure($this->tempDir(), []);
        $this->bootModules();
        $this->createUser();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        Model::resetRegistry();
        Clock::freeze(null);
    }

    private function bootModules(): void
    {
        foreach ([new LanguagesModule(), new ContentModule(), new MediaModule(), new BlocksModule(), new FormsModule(), new RedirectsModule()] as $module) {
            $module->register($this->cms->container);
            $module->boot($this->cms);
        }
        $this->cms->menus->location('primary', 'Rooms');     // as the Exhibit theme declares them
        $this->cms->menus->location('footer', 'Footer links');
        ContentModule::finalize($this->cms);
    }

    /** The next request: the content types prepare() built are registered at boot. */
    private function reboot(): void
    {
        Model::resetRegistry();
        $this->bootCms();
        $this->cms->storage->configure($this->tempDir(), []);
        $this->bootModules();
    }

    private function author(): Principal
    {
        return $this->cms->auth->principalFor((array) $this->db->table('users')->where('username', 'ana')->first(), 'cli');
    }

    /** prepare(), then the boot that registers its types. */
    private function prepared(): void
    {
        DemoContent::prepare($this->cms, $this->author());
        $this->reboot();
    }

    private function visit(string $uri): Response
    {
        $this->nextRequest();

        return $this->cms->routes->dispatch(new Request('GET', $uri, $uri, [], [], [], [], [], ['REMOTE_ADDR' => '203.0.113.7'], ''));
    }

    /** @return list<string> titles of published entries of a type in a language, oldest first */
    private function titles(string $type, string $locale = ''): array
    {
        return array_map('strval', $this->db->table('entries')->where('type', $type)->where('locale', $locale)->where('status', 'published')->orderBy('id')->pluck('title'));
    }

    /** @return array<string, mixed> */
    private function data(string $slug, string $locale): array
    {
        return Json::decode((string) $this->db->table('entries')->where('slug', $slug)->where('locale', $locale)->value('data'));
    }

    public function testPreparingBuildsTheTypesTheLanguagesAndTheNamesInEach(): void
    {
        $this->assertSame(['projects', 'talks', 'team'], DemoContent::prepare($this->cms, $this->author()));
        $this->assertFalse(DemoContent::ready($this->cms), 'built types are registered on the next boot');
        $this->assertSame([], DemoContent::prepare($this->cms, $this->author()), 'nothing is built twice');
        $this->assertSame(3, $this->db->table('content_models')->count());

        $settings = Settings::getInstance();
        $this->assertSame(['Taller Arroyo & Lind', 'es', '1'], [$settings->get('site_name'), $settings->get('languages'), $settings->get('discourage_search')]);
        $this->assertSame('/proyectos', Json::decode((string) $settings->get('path_translations'))['es']['/projects']);
        $this->assertStringContainsString('Valencia', (string) Json::decode((string) $settings->get('site_translations'))['es']['site_description']);

        $this->reboot();
        $this->assertTrue(DemoContent::ready($this->cms));
        $type = $this->cms->content->get('projects');
        $this->assertSame(['Projects', '/projects', true, true], [$type?->plural(), $type?->getPath(), $type?->hasArchive(), $type?->getField('year')?->isIndexed()]);
        $this->assertFalse($type->getField('place')?->isTranslatable(), 'a project\'s place is the same in every language');
    }

    public function testSeedingBeforeTheTypesAreRegisteredSaysWhatToDo(): void
    {
        $error = $this->assertThrows(\RuntimeException::class, fn() => DemoContent::seed($this->cms, $this->author(), images: false));
        $this->assertStringContainsString('DemoContent::prepare()', $error->getMessage());
        $this->assertSame(0, $this->db->table('entries')->count(), 'nothing half made');
    }

    public function testSeedingFillsTheStudioInEnglishAndSpanish(): void
    {
        $this->prepared();
        $summary = DemoContent::seed($this->cms, $this->author(), images: false);

        $this->assertSame(['pages' => 2, 'entries' => ['projects' => 5, 'talks' => 3, 'team' => 4, 'post' => 3], 'terms' => 4, 'forms' => 2, 'menus' => 4, 'images' => 0], $summary);
        $this->assertSame(['Studio', 'Start a project'], $this->titles('page'));
        $this->assertSame(['Estudio', 'Empezar un proyecto'], $this->titles('page', 'es'));
        $this->assertSame(5, count($this->titles('projects', 'es')), 'every project is translated');
        $this->assertSame(0, $this->db->table('entries')->whereNull('translation_group')->count(), 'and linked to its translation');

        $en = $this->data('house-on-the-terraces', '');
        $es = $this->data('casa-en-las-terrazas', 'es');
        $this->assertSame([2024, 'housing', 'Altea'], [$es['year'], $es['use'], $es['place']], 'shared fields come from the original');
        $this->assertStringContainsString('bancales', $es['body'], 'the words are Spanish');
        $this->assertNotSame($en['body'], $es['body']);
        $this->assertSame('Un estudio pequeño en Valencia. Dibujamos a mano primero, hacemos maquetas de todo y seguimos en la obra hasta que se cuelga la última puerta.', $this->data('estudio', 'es')['seo']['description']);

        $forms = $this->db->table('forms')->orderBy('id')->get();
        $this->assertSame(['Start a project', 'Empezar un proyecto'], array_column($forms, 'title'));
        $this->assertSame((string) $forms[1]['id'], ((array) $this->data('contacto', 'es')['body'][1]['props'])['form'], 'the Spanish page shows the Spanish form');

        $this->assertSame(['Visits', 'Drawing'], array_map('strval', $this->db->table('terms')->where('locale', '')->orderBy('id')->pluck('name')));
        $this->assertSame(['visitas', 'dibujo'], array_map('strval', $this->db->table('terms')->where('locale', 'es')->orderBy('id')->pluck('slug')));

        $starts = array_map(static fn(array $r) => Json::decode((string) $r['data'])['starts'], $this->db->table('entries')->where('type', 'talks')->where('locale', '')->orderBy('id')->get());
        $this->assertTrue($starts[0] < gmdate('Y-m-d\TH:i:s\Z') && $starts[1] > gmdate('Y-m-d\TH:i:s\Z'), 'one talk has taken place, the others are coming');

        $menus = $this->cms->container->make(Menus::class);
        [$main, $spanish] = [$menus->assignments(), $menus->assignments('es')];
        $this->assertTrue($main['primary'] !== null && $spanish['primary'] !== null && $main['primary'] !== $spanish['primary'], 'each language has its own rooms');

        $this->reboot();     // the archives' routes are made at boot
        foreach (['/projects', '/es/proyectos', '/es/proyectos/casa-en-las-terrazas', '/talks', '/es/charlas', '/es/estudio', '/es/contacto', '/es/diario/patios-en-urbino', '/es/equipo'] as $path) {
            $this->assertSame(200, $this->visit($path)->statusCode(), $path);
        }
        $this->assertSame(301, $this->visit('/es/projects')->statusCode(), 'the English word under /es goes to the Spanish one');
    }

    public function testThePhotosGoIntoTheLibraryWithTheirCredits(): void
    {
        $this->prepared();
        $summary = DemoContent::seed($this->cms, $this->author());

        $this->assertSame(8, $summary['images']);
        foreach ($this->db->table('media')->get() as $file) {
            $this->assertMatchesRegularExpression('/\((CC0|public domain)\)$/', (string) $file['caption'], "{$file['filename']} is credited");
            $this->assertTrue((string) $file['alt'] !== '' && (int) $file['width'] <= 1600, $file['filename']);
        }
        $media = (int) $this->db->table('media')->where('filename', 'concrete-volumes.webp')->value('id');
        $this->assertSame([$media], $this->data('casa-en-las-terrazas', 'es')['photos'], 'a translation shows the same photos');
        $this->assertSame((int) $this->db->table('media')->where('filename', 'urbino-courtyard.webp')->value('id'), $this->data('courtyards-in-urbino', '')['image']);
        $this->assertTrue(is_file(dirname(__DIR__, 2) . '/core/src/Cms/Demo/content/photos/concrete-volumes.webp'), 'the shipped photo stays where it is');
    }

    public function testASiteWithContentIsLeftAlone(): void
    {
        $this->prepared();
        DemoContent::seed($this->cms, $this->author(), images: false);

        $this->assertThrows(\RuntimeException::class, fn() => DemoContent::seed($this->cms, $this->author(), images: false));
        $this->assertSame(4, $this->db->table('entries')->where('type', 'page')->count());
    }

    public function testFillSwapsPhotosAndTheFormAndLeavesOutWhatIsMissing(): void
    {
        $blocks = [
            ['type' => 'core/text', 'props' => ['text' => 'Hi'], 'children' => []],
            ['type' => 'core/image', 'props' => ['image' => '@photo:gone'], 'children' => []],
            ['type' => 'core/form', 'props' => ['form' => '@form'], 'children' => []],
        ];
        $this->assertSame(
            ['photos' => [7], 'image' => 7, 'body' => [$blocks[0], ['type' => 'core/form', 'props' => ['form' => '12'], 'children' => []]]],
            DemoContent::fill(['photos' => ['@photo:here', '@photo:gone'], 'image' => '@photo:here', 'cover' => '@photo:gone', 'body' => $blocks], ['here' => 7], 12),
        );
    }

    public function testTalksStartAtTheStudiosLocalTime(): void
    {
        Clock::freeze('2026-01-10 12:00:00 UTC');
        $this->assertSame('2026-01-12T18:00:00Z', DemoContent::startsAt(2, '19:00'), 'Valencia is UTC+1 in winter');
        $this->assertSame('2026-01-08T10:30:00Z', DemoContent::startsAt(-2, '11:30'));
        Clock::freeze('2026-07-10 12:00:00 UTC');
        $this->assertSame('2026-07-12T17:00:00Z', DemoContent::startsAt(2, '19:00'), 'and UTC+2 in summer');
    }
}
