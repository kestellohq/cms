<?php

declare(strict_types=1);

namespace Kestello\Tests\Search;

use Kestello\Cms\Api\Schema;
use Kestello\Cms\Blocks\BlocksModule;
use Kestello\Cms\Content\ContentModule;
use Kestello\Cms\Content\Entry;
use Kestello\Cms\Content\Field;
use Kestello\Cms\Media\MediaModule;
use Kestello\Cms\Platform\CoreDrivers;
use Kestello\Cms\Platform\DriverDefinition;
use Kestello\Cms\Search\DatabaseEngine;
use Kestello\Cms\Search\SearchManager;
use Kestello\Cms\Search\SearchModule;
use Kestello\Contracts\Search\Engine;
use Kestello\Contracts\Search\SearchException;
use Kestello\Contracts\Search\SearchQuery;
use Kestello\Contracts\Search\SearchResults;
use Kestello\Framework\Cache\ArrayStore;
use Kestello\Framework\Core\ExecutionContext;
use Kestello\Framework\Database\Model;
use Kestello\Framework\Http\RateLimiter;
use Kestello\Tests\Auth\AuthTestCase;

/** Site search: what gets indexed, when, and who can find what. */
final class SearchTest extends AuthTestCase
{
    protected function setUp(): void
    {
        Model::resetRegistry();      // the Cms built by the parent takes the fresh registry
        parent::setUp();
        $this->cms->storage->configure($this->tempDir(), []);
        CoreDrivers::registerSearch($this->cms->drivers);
        foreach ([new ContentModule(), new MediaModule(), new BlocksModule(), new SearchModule()] as $module) {
            $module->register($this->cms->container);
            $module->boot($this->cms);
        }
        ExecutionContext::run('plugin:events', function (): void {
            $this->cms->content->type('events')->label('Event', 'Events')->path('/events')
                ->fields([Field::textarea('summary'), Field::text('secret')->internal(), Field::email('contact'), Field::text('code')->searchable(false),
                    Field::repeater('speakers', [Field::text('name')])]);
            $this->cms->content->type('events-internal')->label('Note', 'Notes')->path(null)->fields([Field::text('summary')]);
        });
        ContentModule::finalize($this->cms);
        $this->createUser();
        $this->createUser(['username' => 'cora', 'email' => 'cora@example.test', 'role' => 'contributor']);
        $this->assertSame(200, $this->api('POST', '/auth/session', ['login' => 'ana', 'password' => self::PASSWORD])->statusCode());
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        Model::resetRegistry();
    }

    private function create(string $type, array $body): array
    {
        $r = $this->api('POST', "/content/{$type}/entries", $body);
        $this->assertSame(200, $r->statusCode(), $r->content());

        return $this->json($r)['data'];
    }

    /** @return list<string> */
    private function find(string $q, array $options = []): array
    {
        return array_map(static fn($h) => $h['title'], $this->cms->search->search($q, $options)['hits']);
    }

    public function testOnlyWhatVisitorsSeeIsIndexedAndKeptInSync(): void
    {
        $this->create('events', ['title' => 'Harbour festival', 'status' => 'published', 'data' => [
            'summary' => 'Music by the water', 'secret' => 'tiramisu', 'contact' => 'boss@example.test', 'code' => 'ZX81', 'speakers' => [['name' => 'Ada Lovelace']],
        ]]);
        $draft = $this->create('post', ['title' => 'Harbour draft']);
        $this->create('post', ['title' => 'Harbour later', 'status' => 'scheduled', 'published_at' => gmdate('Y-m-d\TH:i:s\Z', time() + 3600)]);
        $this->create('events-internal', ['title' => 'Harbour notes', 'status' => 'published']);
        $page = $this->create('page', ['title' => 'Visit us', 'status' => 'published', 'data' => ['body' => [
            ['type' => 'core/heading', 'props' => ['text' => 'Lighthouse tours']],
            ['type' => 'core/columns', 'props' => [], 'children' => [['type' => 'core/column', 'children' => [['type' => 'core/text', 'props' => ['text' => '<p>Ferries <b>every hour</b></p>']]]]]],
            ['type' => 'core/heading', 'props' => ['text' => ['bind' => 'site.name']]],
            ['type' => 'core/heading', 'props' => ['text' => 'Coupon SECRET123'], 'visibility' => ['auth' => 'user']],
            ['type' => 'core/group', 'props' => [], 'visibility' => ['roles' => ['editor']], 'children' => [['type' => 'core/text', 'props' => ['text' => '<p>Staff rota</p>']]]],
            ['type' => 'core/heading', 'props' => ['text' => 'Launch price embargoed'], 'visibility' => ['from' => '2099-01-01T00:00:00Z']],
            ['type' => 'core/heading', 'props' => ['text' => 'Welcome stranger'], 'visibility' => ['auth' => 'guest']],
        ]]]);

        $this->assertSame(['Harbour festival'], $this->find('harbour'), 'drafts, scheduled entries and types without addresses stay out');
        $this->assertSame(['Harbour festival'], $this->find('ada'), 'repeater text is searchable');
        $this->assertSame(['Harbour festival'], $this->find('music water'));
        foreach (['tiramisu', 'boss', 'ZX81'] as $private) {
            $this->assertSame([], $this->find($private), "{$private}: internal, email and opted-out fields are never indexed");
        }
        $this->assertSame(['Visit us'], $this->find('lighthouse ferries'), 'block text, nested, without HTML');
        foreach (['SECRET123', 'rota', 'embargoed'] as $hidden) {
            $this->assertSame([], $this->find($hidden), "{$hidden}: blocks not every visitor sees stay out of the public index");
        }
        $this->assertSame(['Visit us'], $this->find('stranger'), 'blocks every visitor sees are indexed');
        $hit = $this->cms->search->search('ferries')['hits'][0];
        $this->assertSame(['/visit-us', 'page', 'Page'], [$hit['url'], $hit['type'], $hit['type_label']]);
        $this->assertContains(['text' => 'Ferries', 'match' => true], $hit['snippet'], 'matches come as parts, never as HTML');

        $this->api('PATCH', "/content/post/entries/{$draft['id']}", ['status' => 'published']);
        $this->assertSame(['Harbour draft', 'Harbour festival'], $this->find('harbour'), 'publishing indexes');
        $this->api('PATCH', "/content/post/entries/{$draft['id']}", ['title' => 'Harbour news']);
        $this->assertSame(['Harbour news', 'Harbour festival'], $this->find('harbour'), 'edits update');
        $this->api('DELETE', "/content/post/entries/{$draft['id']}");
        $this->assertSame(['Harbour festival'], $this->find('harbour'), 'trash removes');
        $this->api('POST', "/content/post/entries/{$draft['id']}/restore");
        $this->assertSame(2, count($this->find('harbour')), 'restore brings it back');
        $this->api('PATCH', "/content/post/entries/{$draft['id']}", ['status' => 'draft']);
        $this->assertSame(['Harbour festival'], $this->find('harbour'), 'unpublishing removes');

        $child = $this->create('page', ['title' => 'Ferry timetable', 'status' => 'published', 'parent_id' => $page['id']]);
        $this->assertSame('/visit-us/ferry-timetable', $this->cms->search->search('timetable')['hits'][0]['url']);
        $this->api('PATCH', "/content/page/entries/{$page['id']}", ['slug' => 'visit']);
        $this->assertSame('/visit/ferry-timetable', $this->cms->search->search('timetable')['hits'][0]['url'], 'children follow a moved parent');
        $this->api('PATCH', "/content/page/entries/{$page['id']}", ['status' => 'draft']);
        $this->assertSame([], $this->find('timetable'), 'a draft parent takes its children out of search');
        $this->api('PATCH', "/content/page/entries/{$page['id']}", ['status' => 'published']);
        $this->assertSame(['Ferry timetable'], $this->find('timetable'), 'and publishing it brings them back');

        Entry::query()->where('id', $child['id'])->update(['title' => 'Ferry times']);   // a write that skips model events
        $this->assertSame(['content' => 3], $this->cms->search->reindex(), 'reindex rebuilds from the database');
        $this->assertSame(['Ferry times'], $this->find('times'));
    }

    public function testResultsFacetsAndThePublicEndpoints(): void
    {
        $this->create('post', ['title' => 'Boat building', 'status' => 'published']);
        $this->create('page', ['title' => 'Boat tours', 'status' => 'published']);
        $this->create('events', ['title' => 'Boat race', 'status' => 'published']);

        $all = $this->cms->search->search('boat');
        $this->assertSame(3, $all['total']);
        $facets = array_column($all['facets'], 'count', 'value');
        $this->assertSame(['post' => 1, 'page' => 1, 'events' => 1], [...['post' => $facets['post'], 'page' => $facets['page'], 'events' => $facets['events']]]);
        $this->assertSame('Events', array_column($all['facets'], 'label', 'value')['events']);
        $this->assertSame(['Boat race'], $this->find('boat', ['types' => ['events']]));
        $this->assertSame(3, count($this->find('boat', ['types' => ['events-internal', 'nope']])), 'unknown or unsearchable types are ignored');

        $this->api('DELETE', '/auth/session');
        $r = $this->api('GET', '/search?q=boat&type=post');
        $this->assertSame(200, $r->statusCode(), $r->content());
        $this->assertSame(['Boat building'], array_column($this->json($r)['data']['hits'], 'title'), 'public, no sign-in');
        $this->assertSame(422, $this->api('GET', '/search?q=')->statusCode());

        $this->cms->container->instance(RateLimiter::class, new RateLimiter(new ArrayStore()));
        for ($i = 0; $i < \Kestello\Cms\Search\SearchController::PER_MINUTE; $i++) {
            $this->api('GET', '/search?q=boat', ip: '198.51.100.20');
        }
        $this->assertSame(429, $this->api('GET', '/search?q=boat', ip: '198.51.100.20')->statusCode(), 'limited per client');
        $this->assertSame(200, $this->api('GET', '/search?q=boat', ip: '198.51.100.21')->statusCode());
    }

    public function testTheAdminFinderShowsOnlyWhatTheUserMayOpen(): void
    {
        $mine = $this->create('post', ['title' => 'Quarterly plan', 'data' => []]);
        $this->create('events', ['title' => 'Quarterly meetup']);

        $groups = array_column($this->json($this->api('GET', '/admin/find?q=quart'))['data']['groups'], null, 'id');
        $this->assertSame(['Quarterly meetup', 'Quarterly plan'], array_column($groups['entries']['items'], 'label'), 'drafts too, newest first');
        $this->assertSame("/admin/content/post/{$mine['id']}", $groups['entries']['items'][1]['href']);
        $this->assertSame(['Categories'], array_column(array_column($this->json($this->api('GET', '/admin/find?q=categ'))['data']['groups'], null, 'id')['screens']['items'], 'label'), 'screens from the admin menu');
        $this->assertSame(['cora'], array_column(array_column($this->json($this->api('GET', '/admin/find?q=CORA'))['data']['groups'], null, 'id')['people']['items'], 'label'), 'case-insensitive');

        $this->api('DELETE', '/auth/session');
        $this->assertSame(200, $this->api('POST', '/auth/session', ['login' => 'cora', 'password' => self::PASSWORD])->statusCode());
        $this->create('post', ['title' => 'Quarterly notes by cora']);
        $groups = array_column($this->json($this->api('GET', '/admin/find?q=quart'))['data']['groups'], null, 'id');
        $this->assertSame(['Quarterly notes by cora'], array_column($groups['entries']['items'], 'label'), 'contributors find only their own entries');
        $this->assertFalse(isset($groups['people']), 'no people without users.read');
        $this->assertSame([], array_column($this->json($this->api('GET', '/admin/find?q=categ'))['data']['groups'], 'id'), 'screens they cannot open stay hidden');
    }

    public function testAnExternalEngineIsFedInTheBackgroundAndFailsSafely(): void
    {
        $fake = new class implements Engine {
            public array $docs = [];
            public bool $down = false;
            public function name(): string
            {
                return 'fake';
            }
            public function index(array $documents): void
            {
                $this->guard();
                foreach ($documents as $d) {
                    $this->docs[$d->id] = $d;
                }
            }
            public function remove(string $collection, array $ids): void
            {
                $this->guard();
                foreach ($ids as $id) {
                    unset($this->docs[$id]);
                }
            }
            public function flush(string $collection): void
            {
                $this->guard();
                $this->docs = [];
            }
            public function search(SearchQuery $query): SearchResults
            {
                $this->guard();
                return new SearchResults([], 0, 1, 10, [], 'fake');
            }
            public function ping(): void
            {
                $this->guard();
            }
            private function guard(): void
            {
                if ($this->down) {
                    throw new SearchException('connection refused');
                }
            }
        };
        $this->cms->drivers->register('search', new DriverDefinition(name: 'fake', label: 'Fake', schema: Schema::object([]), factory: static fn() => $fake));
        $this->cms->drivers->setConfigResolver(static fn(string $s) => $s === 'search' ? ['driver' => 'fake', 'options' => []] : ['driver' => 'database', 'options' => []]);
        $this->cms->drivers->forget('search');

        $this->create('post', ['title' => 'Quiet cove', 'status' => 'published']);
        $this->assertSame(['entry:1'], array_keys($fake->docs), 'with the sync queue the job runs right away');

        $fake->down = true;
        $this->create('post', ['title' => 'Stormy cove', 'status' => 'published']);   // saving still works
        $this->assertSame(1, count($fake->docs));
        (new DatabaseEngine())->index([$this->cms->search->document(Entry::query()->find(2))]);
        $this->assertSame(['Stormy cove'], $this->find('stormy'), 'a failing engine answers from the database');
        $this->assertSame('critical', $this->cms->health->run(true, ['search'])['checks'][0]['status'], 'and Site Health says so');
    }

    public function testSnippetsAndHighlights(): void
    {
        $long = str_repeat('Filler words go here. ', 30) . 'The lighthouse keeper waves. ' . str_repeat('More filler. ', 30);
        $snippet = SearchManager::snippet($long, ['lighthouse']);
        $this->assertTrue(str_starts_with($snippet, '…') && str_ends_with($snippet, '…') && str_contains($snippet, 'lighthouse keeper'), $snippet);
        $this->assertTrue(mb_strlen($snippet) <= 205);
        $this->assertSame([['text' => 'Light', 'match' => true], ['text' => ' and ', 'match' => false], ['text' => 'lighthouses', 'match' => true]], SearchManager::highlight('Light and lighthouses', ['light']));
        $this->assertSame([['text' => '<b>x</b> ', 'match' => false], ['text' => 'café', 'match' => true]], SearchManager::highlight('<b>x</b> café', ['caf']), 'text stays text');
    }
}
