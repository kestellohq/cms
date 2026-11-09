<?php

declare(strict_types=1);

namespace Kestello\Tests\Content;

use Kestello\Cms\Content\ContentModule;
use Kestello\Cms\Content\ContentRegistry;
use Kestello\Cms\Content\Models\ContentModels;
use Kestello\Cms\Site\SiteModule;
use Kestello\Framework\Core\Request;
use Kestello\Framework\Core\Response;
use Kestello\Framework\Database\Model;
use Kestello\Framework\Database\QueryBuilder;
use Kestello\Support\Json;
use Kestello\Tests\Auth\AuthTestCase;

/** Content types and taxonomies built in the admin. */
final class ContentModelsTest extends AuthTestCase
{
    protected function setUp(): void
    {
        Model::resetRegistry();
        parent::setUp();
        $this->bootModules();
        $this->createUser();
        $this->createUser(['username' => 'eve', 'email' => 'eve@example.test', 'role' => 'editor']);
        $this->signIn('ana');
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        Model::resetRegistry();
    }

    private function bootModules(): void
    {
        foreach ([new ContentModule(), new \Kestello\Cms\Redirects\RedirectsModule(), new SiteModule()] as $module) {
            $module->register($this->cms->container);
            $module->boot($this->cms);
        }
        ContentModule::finalize($this->cms);
    }

    /** The next request: stored models are registered at boot. */
    private function reboot(): void
    {
        Model::resetRegistry();
        $this->bootCms();
        $this->bootModules();
    }

    private function signIn(string $login): void
    {
        $this->api('DELETE', '/auth/session');
        $this->assertSame(200, $this->api('POST', '/auth/session', ['login' => $login, 'password' => self::PASSWORD])->statusCode());
    }

    private function visit(string $uri): Response
    {
        $this->nextRequest();

        return $this->cms->routes->dispatch(new Request('GET', $uri, $uri, [], [], [], [], [], ['REMOTE_ADDR' => '203.0.113.7'], ''));
    }

    private const array EVENT = [
        'singular' => 'Event', 'plural' => 'Events', 'icon' => 'calendar-dots', 'path' => '/events', 'archive' => true,
        'fields' => [
            ['id' => 'aaaa0001', 'name' => 'subtitle', 'type' => 'text', 'required' => true, 'options' => ['max' => 120]],
            ['id' => 'aaaa0002', 'name' => 'starts', 'type' => 'date', 'indexed' => true, 'width' => 'sidebar'],
            ['id' => 'aaaa0003', 'name' => 'level', 'type' => 'select', 'options' => ['choices' => [['value' => 'easy', 'label' => 'Easy'], ['value' => 'hard', 'label' => 'Hard']]]],
            ['id' => 'aaaa0004', 'name' => 'speakers', 'type' => 'repeater', 'children' => [
                ['id' => 'aaaa0005', 'name' => 'name', 'type' => 'text'],
            ]],
        ],
    ];

    private function createEvent(): array
    {
        $r = $this->api('POST', '/content-models', ['kind' => 'type', 'name' => 'event', 'definition' => self::EVENT]);
        $this->assertSame(200, $r->statusCode(), $r->content());
        $this->reboot();

        return $this->json($r)['data'];
    }

    private function entry(array $data, string $status = 'published'): array
    {
        $r = $this->api('POST', '/content/event/entries', ['title' => 'Bread week', 'status' => $status, 'data' => $data]);
        $this->assertSame(200, $r->statusCode(), $r->content());

        return $this->json($r)['data'];
    }

    private function stored(int $id): array
    {
        return Json::decode((string) QueryBuilder::table('entries')->where('id', $id)->first()['data']);
    }

    public function testBuiltTypesWorkLikeCodeTypes(): void
    {
        $this->assertNull($this->cms->content->get('event'));
        $this->createEvent();
        $type = $this->cms->content->get('event');
        $this->assertSame(['Events', 'site', '/events', 'sidebar'], [$type->plural(), $type->owner, $type->getPath(), $type->getField('starts')->getWidth()]);

        $this->assertSame(422, $this->api('POST', '/content/event/entries', ['title' => 'No subtitle', 'status' => 'published', 'data' => []])->statusCode(), 'required fields are enforced');
        $this->assertSame(422, $this->api('POST', '/content/event/entries', ['title' => 'Bad level', 'status' => 'draft', 'data' => ['level' => 'medium']])->statusCode(), 'and choices');
        $event = $this->entry(['subtitle' => 'Seven loaves', 'starts' => '2026-12-01', 'level' => 'easy', 'speakers' => [['name' => 'Ana']]]);
        $this->assertSame(200, $this->visit('/events/' . $event['slug'])->statusCode(), 'it has public pages');
        $this->assertSame(200, $this->visit('/events')->statusCode(), 'and an archive');
        $this->assertSame('2026-12-01', substr((string) QueryBuilder::table('entry_index')->where('entry_id', $event['id'])->where('field', 'starts')->first()['value_date'], 0, 10), 'indexed for sorting');

        $tax = $this->api('POST', '/content-models', ['kind' => 'taxonomy', 'name' => 'track', 'definition' => ['singular' => 'Track', 'plural' => 'Tracks', 'types' => ['event'], 'path' => '/tracks']]);
        $this->assertSame(200, $tax->statusCode(), $tax->content());
        $this->reboot();
        $this->assertSame(200, $this->api('POST', '/taxonomies/track/terms', ['name' => 'Baking'])->statusCode());

        $this->signIn('eve');
        $this->assertSame(200, $this->api('POST', '/content/event/entries', ['title' => 'By an editor', 'data' => []])->statusCode(), 'editors get the usual access to it');
        $this->assertSame(403, $this->api('GET', '/content-models')->statusCode(), 'but only administrators change the structure');
        $this->signIn('ana');
        $this->api('POST', '/content/page/entries', ['title' => 'Classes', 'status' => 'published']);

        foreach ([
            [['kind' => 'type', 'name' => 'class', 'definition' => ['path' => '/classes'] + self::EVENT], 'path', 'a page already answers there'],
            [['kind' => 'type', 'name' => 'post', 'definition' => self::EVENT], 'name', 'code\'s types are taken'],
            [['kind' => 'type', 'name' => 'event', 'definition' => self::EVENT], 'name', 'so are built ones'],
            [['kind' => 'type', 'name' => 'Bad Name', 'definition' => self::EVENT], 'name', 'names are like slugs'],
            [['kind' => 'type', 'name' => 'class', 'definition' => ['path' => '/blog'] + self::EVENT], 'path', 'addresses are taken too'],
            [['kind' => 'type', 'name' => 'class', 'definition' => ['path' => '/admin'] + self::EVENT], 'path', 'and the site\'s own'],
            [['kind' => 'type', 'name' => 'class', 'definition' => ['path' => null, 'fields' => [['name' => 'seo', 'type' => 'text']]] + self::EVENT], 'fields.0.name', 'the platform\'s fields'],
            [['kind' => 'type', 'name' => 'class', 'definition' => ['path' => null, 'fields' => [['name' => 'level', 'type' => 'select']]] + self::EVENT], 'fields.0', 'a select needs choices'],
            [['kind' => 'type', 'name' => 'class', 'definition' => ['path' => null, 'fields' => [['name' => 'x', 'type' => 'php']]] + self::EVENT], 'fields.0.type', 'only known field types'],
            [['kind' => 'type', 'name' => 'class', 'definition' => ['path' => null, 'fields' => [['name' => 'tags', 'type' => 'multi_select', 'indexed' => true, 'options' => ['choices' => ['a' => 'A']]]]] + self::EVENT], 'fields.0.indexed', 'only single values sort'],
            [['kind' => 'type', 'name' => 'class', 'definition' => ['path' => null, 'fields' => [['name' => 'a', 'type' => 'text'], ['name' => 'a', 'type' => 'text']]] + self::EVENT], 'fields.1.name', 'no duplicate names'],
            [['kind' => 'taxonomy', 'name' => 'mood', 'definition' => ['singular' => 'Mood', 'plural' => 'Moods', 'types' => ['nothing']]], 'types', 'taxonomies are for real types'],
        ] as [$body, $field, $why]) {
            $r = $this->api('POST', '/content-models', $body);
            $this->assertSame(422, $r->statusCode(), $why);
            $this->assertTrue(array_key_exists($field, $this->json($r)['errors'] ?? []), "{$why}: error on {$field}, got " . $r->content());
        }
    }

    public function testLinksFollowARenamedRelationshipField(): void
    {
        $def = self::EVENT;
        $def['fields'][] = ['id' => 'aaaa0006', 'name' => 'host', 'type' => 'entry', 'options' => ['types' => ['post']]];
        $r = $this->api('POST', '/content-models', ['kind' => 'type', 'name' => 'event', 'definition' => $def]);
        $this->assertSame(200, $r->statusCode(), $r->content());
        $model = $this->json($r)['data'];
        $this->reboot();
        $post = $this->json($this->api('POST', '/content/post/entries', ['title' => 'Bakery']))['data'];
        $event = $this->entry(['subtitle' => 'Seven loaves', 'host' => $post['id']], 'draft');
        $links = fn() => array_column(QueryBuilder::table('entry_relations')->where('source_id', $event['id'])->get(), 'field');
        $this->assertSame(['host'], $links());

        $def = $model['definition'];
        $def['fields'][4]['name'] = 'organiser';
        $this->assertSame(200, $this->api('PATCH', "/content-models/{$model['id']}", ['definition' => $def])->statusCode());
        $this->assertSame(['organiser'], $links(), 'the link is kept under the new name');
    }

    public function testTextTurnedIntoRichTextIsSanitized(): void
    {
        $model = $this->createEvent();
        $unsafe = '<p>Hi</p><img src="x" onerror="alert(1)"><iframe src="https://evil.test"></iframe>';
        $event = $this->entry(['subtitle' => $unsafe, 'speakers' => [['name' => $unsafe]]]);
        $this->assertSame($unsafe, $this->stored($event['id'])['subtitle'], 'plain text is stored as typed');

        $def = $model['definition'];
        $def['fields'][0] = ['id' => 'aaaa0001', 'name' => 'subtitle', 'type' => 'rich_text'];
        $def['fields'][3]['children'][0] = ['id' => 'aaaa0005', 'name' => 'name', 'type' => 'rich_text'];
        $this->assertSame(200, $this->api('PATCH', "/content-models/{$model['id']}", ['definition' => $def, 'confirm' => true])->statusCode());

        $stored = $this->stored($event['id']);
        foreach ([$stored['subtitle'], $stored['speakers'][0]['name']] as $html) {
            $this->assertStringContainsString('<p>Hi</p>', $html);
            $this->assertStringNotContainsString('onerror', $html);
            $this->assertStringNotContainsString('<iframe', $html);
        }
    }

    public function testRichTextIsSanitizedWhenShownEvenIfStoredUnsafe(): void
    {
        $model = $this->createEvent();
        $def = $model['definition'];
        $def['fields'][0] = ['id' => 'aaaa0001', 'name' => 'subtitle', 'type' => 'rich_text'];
        $this->assertSame(200, $this->api('PATCH', "/content-models/{$model['id']}", ['definition' => $def, 'confirm' => true])->statusCode());
        $this->reboot();
        $event = $this->entry(['subtitle' => '<p>Safe</p>']);
        // written around the field (a restored revision, a plugin, an import): the page still never trusts it
        QueryBuilder::table('entries')->where('id', $event['id'])->update(['data' => Json::encode(['subtitle' => '<p>Hi</p><img src="x" onerror="alert(1)">'])]);

        // the page's props, before Vue renders them (rich text goes into v-html)
        $payload = $this->visit('/events/' . $event['slug'])->payload();
        $this->assertInstanceOf(\Kestello\Cms\View\PageResponse::class, $payload);
        $page = Json::encode($payload->props);
        $this->assertStringContainsString('Hi', $page);
        $this->assertStringNotContainsString('onerror', $page);
    }

    public function testRenamesKeepValuesAndRemovalsNeedConfirmation(): void
    {
        $model = $this->createEvent();
        $event = $this->entry(['subtitle' => 'Seven loaves', 'starts' => '2026-12-01', 'level' => 'hard', 'speakers' => [['name' => 'Ana'], ['name' => 'Eve']]]);
        $def = $model['definition'];

        // rename three fields (same ids): one indexed, one inside the repeater
        $def['fields'][0]['name'] = 'tagline';
        $def['fields'][1]['name'] = 'begins';
        $def['fields'][3]['children'][0]['name'] = 'full_name';
        $changes = $this->json($this->api('POST', "/content-models/{$model['id']}/preview", ['definition' => $def]))['data']['changes'];
        $this->assertSame(
            [['renamed', 'subtitle', 'tagline', 1], ['renamed', 'starts', 'begins', 1], ['renamed', 'speakers.[].name', 'speakers.[].full_name', 1]],
            array_map(static fn($c) => [$c['change'], $c['from'], $c['to'], $c['entries']], $changes),
        );
        $saved = $this->api('PATCH', "/content-models/{$model['id']}", ['definition' => $def]);
        $this->assertSame(200, $saved->statusCode(), 'renames lose nothing, so no confirmation');
        $this->assertEquals(
            ['tagline' => 'Seven loaves', 'begins' => '2026-12-01', 'level' => 'hard', 'speakers' => [['full_name' => 'Ana'], ['full_name' => 'Eve']]],
            array_intersect_key($this->stored($event['id']), array_flip(['tagline', 'begins', 'level', 'speakers'])),
        );
        $this->assertSame(['begins'], array_column(QueryBuilder::table('entry_index')->where('entry_id', $event['id'])->get(), 'field'), 'the sort index follows');
        $this->reboot();
        $this->assertSame('Seven loaves', $this->json($this->api('GET', "/content/event/entries/{$event['id']}"))['data']['data']['tagline']);

        // rename the repeater and a field inside it at once: the values follow both
        $def = $this->json($this->api('GET', "/content-models/{$model['id']}"))['data']['definition'];
        $def['fields'][3]['name'] = 'talks';
        $def['fields'][3]['children'][0]['name'] = 'speaker';
        $this->assertSame(['speakers', 'speakers.[].full_name'], array_column($this->json($this->api('POST', "/content-models/{$model['id']}/preview", ['definition' => $def]))['data']['changes'], 'from'));
        $this->assertSame(200, $this->api('PATCH', "/content-models/{$model['id']}", ['definition' => $def])->statusCode());
        $this->assertSame([['speaker' => 'Ana'], ['speaker' => 'Eve']], $this->stored($event['id'])['talks']);

        // moving a field into a repeater can't carry one value into many items
        $moved = $def;
        $moved['fields'][3]['children'][] = $moved['fields'][0];
        array_splice($moved['fields'], 0, 1);
        $changes = $this->json($this->api('POST', "/content-models/{$model['id']}/preview", ['definition' => $moved]))['data']['changes'];
        $this->assertSame([['removed', 'tagline', true]], array_map(static fn($c) => [$c['change'], $c['from'], $c['destructive']], $changes));

        // remove a field that has values: confirmation first, values kept unless purged
        $def = $this->json($this->api('GET', "/content-models/{$model['id']}"))['data']['definition'];
        $withLevel = $def;
        array_splice($def['fields'], 2, 1);
        $r = $this->api('PATCH', "/content-models/{$model['id']}", ['definition' => $def]);
        $this->assertSame(409, $r->statusCode());
        $this->assertSame([['removed', true, 1]], array_map(
            static fn($c) => [$c['change'], $c['destructive'], $c['entries']],
            $this->json($this->api('POST', "/content-models/{$model['id']}/preview", ['definition' => $def]))['data']['changes'],
        ));
        $this->assertSame(200, $this->api('PATCH', "/content-models/{$model['id']}", ['definition' => $def, 'confirm' => true])->statusCode());
        $this->assertSame('hard', $this->stored($event['id'])['level'], 'kept: putting the field back brings it back');
        $this->assertSame(200, $this->api('PATCH', "/content-models/{$model['id']}", ['definition' => $withLevel])->statusCode());
        $this->assertSame(200, $this->api('PATCH', "/content-models/{$model['id']}", ['definition' => $def, 'confirm' => true, 'purge' => true])->statusCode());
        $this->assertFalse(array_key_exists('level', $this->stored($event['id'])), 'purged');

        // retyping a field with values asks too
        $def['fields'][0]['type'] = 'number';
        $this->assertSame(409, $this->api('PATCH', "/content-models/{$model['id']}", ['definition' => $def])->statusCode());

        // a new address keeps the old links working
        $def['fields'][0]['type'] = 'text';
        $def['path'] = '/whats-on';
        $this->assertSame(200, $this->api('PATCH', "/content-models/{$model['id']}", ['definition' => $def])->statusCode());
        $this->assertSame(1, QueryBuilder::table('redirects')->where('source', '/events')->where('target', '/whats-on')->count());
    }

    public function testDeletingExportingAndCodeWins(): void
    {
        $model = $this->createEvent();
        $event = $this->entry(['subtitle' => 'Seven loaves']);

        $php = $this->json($this->api('GET', "/content-models/{$model['id']}"))['data']['php'];
        $cms = (object) ['content' => new ContentRegistry()];
        eval(str_replace(['use Kestello\\Cms\\Content\\Field;', 'Field::'], ['', '\\Kestello\\Cms\\Content\\Field::'], $php));
        $copy = $cms->content->get('event');
        $this->assertSame(['subtitle', 'starts', 'level', 'speakers'], array_map(static fn($f) => $f->name, $copy->getFields()), 'the export is working PHP');
        $this->assertSame([true, true, ['easy' => 'Easy', 'hard' => 'Hard'], '/events'], [$copy->getField('subtitle')->isRequired(), $copy->getField('starts')->isIndexed(), $copy->getField('level')->getOption('choices'), $copy->getPath()]);

        $this->assertSame(409, $this->api('DELETE', "/content-models/{$model['id']}")->statusCode(), 'not while entries use it');
        $this->api('DELETE', "/content/event/entries/{$event['id']}");                   // the trash
        $this->assertSame(409, $this->api('DELETE', "/content-models/{$model['id']}")->statusCode(), 'the trash counts too');
        $r = $this->api('DELETE', "/content/event/entries/{$event['id']}?permanent=true");
        $this->assertTrue($r->statusCode() < 300, $r->content());
        $this->assertSame(0, QueryBuilder::table('entries')->where('type', 'event')->count());
        $this->assertSame(204, $this->api('DELETE', "/content-models/{$model['id']}")->statusCode());
        $this->reboot();
        $this->assertNull($this->cms->content->get('event'));

        // a plugin that later declares the same name takes over; the stored one waits
        QueryBuilder::table('content_models')->insert(['kind' => 'type', 'name' => 'post', 'definition' => Json::encode(self::EVENT), 'created_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s')]);
        $this->reboot();
        $this->assertSame('core', $this->cms->content->get('post')->owner);
        $this->assertStringContainsString('core', $this->cms->container->make(ContentModels::class)->skipped()['type:post']);
    }

    public function testABuiltTypeCarriesItsChecklist(): void
    {
        $bad = $this->api('POST', '/content-models', ['kind' => 'type', 'name' => 'event', 'definition' => self::EVENT + ['checklist' => [
            ['check' => 'nope'], ['check' => 'acme.signed'], ['check' => 'terms', 'taxonomy' => 'nothing'], ['check' => 'words', 'min' => 10], ['check' => 'words', 'min' => 20],
        ]]]);
        $this->assertSame(422, $bad->statusCode());
        foreach (['checklist.0', 'checklist.1', 'checklist.2', 'checklist.4'] as $key) {
            $this->assertStringContainsString($key, $bad->content());
        }
        $this->assertStringNotContainsString('checklist.3', $bad->content());

        $r = $this->api('POST', '/content-models', ['kind' => 'type', 'name' => 'event', 'definition' => self::EVENT + ['checklist' => [
            ['check' => 'words', 'min' => 3, 'level' => 'required'], ['check' => 'featured_image'],
        ]]]);
        $this->assertSame(200, $r->statusCode(), $r->content());
        $this->reboot();
        $this->assertSame([['check' => 'words', 'level' => 'required', 'min' => 3], ['check' => 'featured_image', 'level' => 'recommended']], $this->cms->content->get('event')->getChecklist());

        $r = $this->api('POST', '/content/event/entries', ['title' => 'Bread week', 'status' => 'published', 'data' => ['subtitle' => 'Two']]);
        $this->assertSame(422, $r->statusCode(), 'a built type holds publishing back too');
        $this->assertStringContainsString('At least 3 words', $r->content());

        $id = (int) QueryBuilder::table('content_models')->where('name', 'event')->value('id');
        $php = $this->json($this->api('GET', "/content-models/{$id}"))['data']['php'];
        $this->assertStringContainsString("->checklist([\n        ['check' => 'words', 'level' => 'required', 'min' => 3],", $php);
        $cms = (object) ['content' => new ContentRegistry()];
        eval(str_replace(['use Kestello\\Cms\\Content\\Field;', 'Field::'], ['', '\\Kestello\\Cms\\Content\\Field::'], $php));
        $this->assertSame($this->cms->content->get('event')->getChecklist(), $cms->content->get('event')->getChecklist(), 'the export keeps it');
    }

    public function testConditionalFieldsAreRequiredOnlyWhenShown(): void
    {
        $r = $this->api('POST', '/content-models', ['kind' => 'type', 'name' => 'venue', 'definition' => ['singular' => 'Venue', 'plural' => 'Venues', 'fields' => [
            ['name' => 'kind', 'type' => 'select', 'options' => ['choices' => ['hall' => 'Hall', 'online' => 'Online']]],
            ['name' => 'link', 'type' => 'url', 'required' => true, 'conditions' => ['match' => 'all', 'rules' => [['field' => 'kind', 'op' => 'is', 'value' => 'online']]]],
        ]]]);
        $this->assertSame(200, $r->statusCode(), $r->content());
        $this->reboot();
        $this->assertSame('online', $this->cms->content->get('venue')->getField('link')->getOption('conditions')['rules'][0]['value']);

        $post = fn(array $data) => $this->api('POST', '/content/venue/entries', ['title' => 'Somewhere', 'status' => 'published', 'data' => $data])->statusCode();
        $this->assertSame(200, $post(['kind' => 'hall']), 'hidden: not required');
        $this->assertSame(422, $post(['kind' => 'online']), 'shown: required');
        $this->assertSame(200, $post(['kind' => 'online', 'link' => 'https://meet.example/x']));

        $bad = $this->api('POST', '/content-models', ['kind' => 'type', 'name' => 'loop', 'definition' => ['singular' => 'Loop', 'plural' => 'Loops', 'fields' => [
            ['name' => 'a', 'type' => 'text', 'conditions' => ['rules' => [['field' => 'a', 'op' => 'filled']]]],
        ]]]);
        $this->assertTrue(array_key_exists('fields.0.conditions', $this->json($bad)['errors'] ?? []), 'a field can\'t depend on itself');
        $this->assertStringContainsString("'conditions'", $this->json($this->api('GET', '/content-models/' . $this->json($r)['data']['id']))['data']['php'], 'and the export keeps them');
    }
}
