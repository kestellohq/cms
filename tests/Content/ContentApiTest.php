<?php

declare(strict_types=1);

namespace Kestello\Tests\Content;

use Kestello\Cms\Content\ContentModule;
use Kestello\Cms\Content\Entries;
use Kestello\Cms\Content\Entry;
use Kestello\Cms\Content\Field;
use Kestello\Cms\Events\EntryPublished;
use Kestello\Framework\Core\ExecutionContext;
use Kestello\Framework\Database\Model;
use Kestello\Support\Clock;
use Kestello\Tests\Auth\AuthTestCase;

/** Content types, entries, revisions and permissions through the API. */
final class ContentApiTest extends AuthTestCase
{
    /** @var list<EntryPublished> */
    private array $published = [];

    protected function setUp(): void
    {
        parent::setUp();
        Model::resetRegistry();
        $module = new ContentModule();
        $module->register($this->cms->container);
        $module->boot($this->cms);
        ExecutionContext::run('plugin:events', function (): void {
            $this->cms->content->type('events')->label('Event', 'Events')->revisions(3)->path('/events')
                ->fields([
                    Field::datetime('starts_at')->required()->indexed(),
                    Field::select('level', ['intro' => 'Intro', 'pro' => 'Pro'])->indexed(),
                    Field::number('seats')->option('integer', true)->min(1)->indexed(),
                    Field::url('website'),
                    Field::richText('body'),
                    Field::entry('related', ['post'], multiple: true),
                    Field::repeater('speakers', [Field::text('name')->required(), Field::email('email')]),
                    Field::group('venue', [Field::text('city')]),
                    Field::textarea('notes')->internal(),
                ]);
        });
        ContentModule::finalize($this->cms);
        $this->cms->events->on(EntryPublished::class, fn(EntryPublished $e) => $this->published[] = $e);
        $this->createUser();
        foreach (['editor', 'author', 'contributor'] as $role) {
            $this->createUser(['username' => $role, 'email' => "{$role}@example.test", 'role' => $role]);
        }
    }

    protected function tearDown(): void
    {
        Clock::unfreeze();
        Model::resetRegistry();
        parent::tearDown();
    }

    private function signIn(string $login = 'ana'): void
    {
        $this->assertSame(200, $this->api('POST', '/auth/session', ['login' => $login, 'password' => self::PASSWORD])->statusCode());
    }

    /** @return array<string, mixed> */
    private function create(string $type, array $body, int $status = 200): array
    {
        $r = $this->api('POST', "/content/{$type}/entries", $body);
        $this->assertSame($status, $r->statusCode(), $r->content());

        return $this->json($r)['data'] ?? $this->json($r);
    }

    public function testPluginsPurgeOnlyTheirOwnContent(): void
    {
        $this->signIn();
        $kept = $this->create('post', ['title' => 'Stays']);
        $this->create('events', ['title' => 'One']);
        $gone = $this->create('events', ['title' => 'Two']);
        $this->assertSame(200, $this->api('DELETE', "/content/events/entries/{$gone['id']}")->statusCode(), 'to the trash');

        $entries = $this->cms->container->make(Entries::class);
        $this->assertThrows(\InvalidArgumentException::class, fn() => ExecutionContext::run('plugin:events', fn() => $entries->purgeType('post')));
        $this->assertThrows(\InvalidArgumentException::class, fn() => ExecutionContext::run('plugin:events', fn() => $this->cms->container->make(\Kestello\Cms\Content\Terms::class)->purgeTaxonomy('tag')));
        $this->assertSame(2, ExecutionContext::run('plugin:events', fn() => $entries->purgeType('events')), 'trashed entries too');
        $this->assertSame(0, $this->db->table('entries')->where('type', 'events')->count());
        $this->assertSame(0, $this->db->table('revisions')->whereNotIn('entry_id', [$kept['id']])->count(), 'revisions went with them');
        $this->assertSame(1, $this->db->table('entries')->where('type', 'post')->count());

        $this->assertSame(['admin', 'editor', 'author'], $this->cms->auth->roles->rolesWith('content.post.publish'), 'who to tell about something');
    }

    public function testTwoSavesCannotTakeTheSameAddress(): void
    {
        // Choosing a free slug and committing it happen under one lock per content type, so two people
        // saving "Contact" at the same moment can't both get /contact. Another request holds it here.
        $store = new \Kestello\Framework\Cache\ArrayStore();
        $this->cms->container->instance(\Kestello\Cms\Content\Entries::class, new \Kestello\Cms\Content\Entries($this->cms->content, $this->cms->events, null, $store));
        $this->signIn();
        $kept = $this->create('page', ['title' => 'About', 'status' => 'published']);

        $this->assertTrue($store->lock('entry-slug:page', 2)->acquire(), 'another save is choosing a page address (expiry counts whole seconds: at least one)');
        $start = microtime(true);
        $this->assertSame(200, $this->api('PATCH', "/content/page/entries/{$kept['id']}", ['menu_order' => 3])->statusCode());
        $this->assertTrue(microtime(true) - $start < 0.5, 'a save that keeps its address does not wait');

        $start = microtime(true);
        $this->assertSame('contact', $this->create('page', ['title' => 'Contact', 'status' => 'published'])['slug']);
        $this->assertGreaterThanOrEqual(0.9, microtime(true) - $start, 'a new address waits for the other save to finish');
    }

    public function testTypesDescribeFieldsAndRespectCapabilities(): void
    {
        $this->signIn('contributor');
        $types = array_column($this->json($this->api('GET', '/content/types'))['data'], null, 'name');
        $this->assertSame(['post', 'events'], array_keys($types), 'not pages: hierarchical types go to editors only');
        $this->assertSame(['create' => true, 'edit' => true, 'edit_others' => false, 'publish' => false, 'delete' => false, 'delete_others' => false], $types['post']['can']);

        $this->signIn('ana');
        $types = array_column($this->json($this->api('GET', '/content/types'))['data'], null, 'name');
        $this->assertSame(['page', 'post', 'events'], array_keys($types));
        $fields = array_column($types['events']['fields'], null, 'name');
        $this->assertSame('@core/fields/Date', $fields['starts_at']['component']);
        $this->assertSame([['value' => 'intro', 'label' => 'Intro'], ['value' => 'pro', 'label' => 'Pro']], $fields['level']['options']['choices']);
        $this->assertSame(['name', 'email'], array_column($fields['speakers']['fields'], 'name'));
        $this->assertTrue($fields['notes']['internal']);
        $this->assertSame('plugin:events', $types['events']['owner']);

        $this->assertThrows(\InvalidArgumentException::class, fn() => ExecutionContext::run('plugin:shop', fn() => $this->cms->content->type('events2')));
        $this->assertThrows(\LogicException::class, fn() => $this->cms->content->type('late'));
    }

    public function testDraftsMayBeIncompletePublishingValidatesEverything(): void
    {
        $this->signIn();
        $draft = $this->create('events', ['data' => ['level' => 'pro']]);
        $this->assertSame(['draft', '', 'event', 1], [$draft['status'], $draft['title'], $draft['slug'], $draft['version']]);

        $r = $this->api('PATCH', "/content/events/entries/{$draft['id']}", ['status' => 'published']);
        $this->assertSame(422, $r->statusCode());
        $this->assertSame(['data.starts_at', 'title'], self::sorted(array_keys($this->json($r)['errors'])));

        $bad = $this->api('POST', '/content/events/entries', ['title' => 'X', 'data' => [
            'level' => 'expert', 'seats' => 0, 'website' => 'javascript:alert(1)', 'nope' => 1,
            'speakers' => [['email' => 'not-mail']], 'related' => [999],
        ]]);
        $this->assertSame(422, $bad->statusCode());
        $errors = $this->json($bad)['errors'];
        $this->assertSame(['data.level', 'data.nope', 'data.seats', 'data.speakers.0.email', 'data.speakers.0.name', 'data.website'], self::sorted(array_keys($errors)), json_encode($errors));

        $post = $this->create('post', ['title' => 'Related post', 'status' => 'published']);
        $bad = $this->api('POST', '/content/events/entries', ['title' => 'X', 'data' => ['website' => 'https://ok.example', 'related' => [$post['id'], 999]]]);
        $this->assertSame(['data.related'], self::sorted(array_keys($this->json($bad)['errors'])));

        $ok = $this->create('events', ['title' => 'Kestello Conf 2027', 'status' => 'published', 'data' => [
            'starts_at' => '2027-03-01T09:00:00+01:00', 'level' => 'pro', 'seats' => 120, 'website' => 'https://conf.example',
            'body' => '<p>Hi <script>alert(1)</script><strong>all</strong></p>', 'related' => [$post['id'], $post['id']],
            'speakers' => [['name' => 'Ana', 'email' => 'ana@example.test']], 'venue' => ['city' => 'Belgrade'],
        ]]);
        $this->assertSame(['published', 'kestello-conf-2027', '/events/kestello-conf-2027'], [$ok['status'], $ok['slug'], $ok['path']]);
        $this->assertSame('2027-03-01T08:00:00Z', $ok['data']['starts_at'], 'datetime stored in UTC');
        $this->assertSame('<p>Hi <strong>all</strong></p>', $ok['data']['body'], 'rich text sanitized');
        $this->assertSame([$post['id']], $ok['data']['related'], 'relation ids deduplicated');
        $this->assertSame(['Related post', 'Kestello Conf 2027'], array_map(fn(EntryPublished $e) => $e->title, $this->published));

        $same = $this->create('events', ['title' => 'Kestello Conf 2027']);
        $this->assertSame('kestello-conf-2027-2', $same['slug'], 'unique slugs');
        $renamed = $this->json($this->api('PATCH', "/content/events/entries/{$same['id']}", ['title' => 'Workshop day']))['data'];
        $this->assertSame('workshop-day', $renamed['slug'], 'an unpublished slug follows the title');
        $kept = $this->json($this->api('PATCH', "/content/events/entries/{$ok['id']}", ['title' => 'Renamed conf']))['data'];
        $this->assertSame('kestello-conf-2027', $kept['slug'], 'a published slug stays put');
    }

    public function testSchedulingAndPublishingDueEntries(): void
    {
        Clock::freeze('2026-11-05 12:00:00 UTC');
        $this->signIn();
        $later = $this->create('post', ['title' => 'Later', 'status' => 'published', 'published_at' => '2026-11-06T08:00:00Z']);
        $this->assertSame('scheduled', $later['status'], 'a future publish date schedules');
        $bad = $this->api('POST', '/content/post/entries', ['title' => 'Past', 'status' => 'scheduled', 'published_at' => '2026-11-01T08:00:00Z']);
        $this->assertSame(['published_at'], array_keys($this->json($bad)['errors']));
        $this->assertSame([], $this->published);

        $entries = $this->cms->container->make(Entries::class);
        $this->assertSame(0, $entries->publishDue());
        Clock::freeze('2026-11-06 08:00:30 UTC');
        $this->assertSame(1, $entries->publishDue());
        $this->assertSame('published', Entry::find($later['id'])->status);
        $this->assertSame(['Later'], array_map(fn(EntryPublished $e) => $e->title, $this->published));
        $this->assertSame('/blog/later', $this->published[0]->path);
    }

    public function testRolesAndOwnership(): void
    {
        $this->signIn('contributor');
        $mine = $this->create('post', ['title' => 'My draft']);
        $this->assertSame(403, $this->api('PATCH', "/content/post/entries/{$mine['id']}", ['status' => 'published'])->statusCode(), 'contributors cannot publish');
        $this->assertSame(403, $this->api('POST', '/content/page/entries', ['title' => 'Page'])->statusCode());
        $this->assertSame(['edit' => true, 'publish' => false, 'delete' => false], $mine['can']);

        $this->signIn('author');
        $theirs = $this->create('post', ['title' => 'Author post', 'status' => 'published']);
        $this->assertSame(404, $this->api('GET', "/content/post/entries/{$mine['id']}")->statusCode(), "others' entries are invisible");
        $list = $this->json($this->api('GET', '/content/post/entries'));
        $this->assertSame(['Author post'], array_column($list['data'], 'title'), 'own entries only');
        $this->assertSame(422, $this->api('PATCH', "/content/post/entries/{$theirs['id']}", ['author_id' => 1])->statusCode(), 'authors cannot reassign');

        $this->signIn('contributor');
        $this->assertSame(404, $this->api('PATCH', "/content/post/entries/{$theirs['id']}", ['title' => 'x'])->statusCode());

        $this->signIn('ana');
        $this->api('PATCH', "/content/post/entries/{$mine['id']}", ['status' => 'published']);
        $this->signIn('contributor');
        $r = $this->api('PATCH', "/content/post/entries/{$mine['id']}", ['title' => 'Edited after publishing']);
        $this->assertSame(403, $r->statusCode(), 'published entries need the publish capability');

        $this->signIn('editor');
        $all = $this->json($this->api('GET', '/content/post/entries?sort=title'));
        $this->assertSame(['Author post', 'My draft'], array_column($all['data'], 'title'));
        $this->assertSame(200, $this->api('PATCH', "/content/post/entries/{$mine['id']}", ['author_id' => 3])->statusCode());
    }

    public function testVersionsRevisionsAndAutosave(): void
    {
        $this->signIn();
        $e = $this->create('events', ['title' => 'v1', 'data' => ['seats' => 10]]);
        foreach (['v2', 'v3', 'v4'] as $i => $title) {
            $r = $this->api('PATCH', "/content/events/entries/{$e['id']}", ['title' => $title, 'version' => $i + 1]);
            $this->assertSame(200, $r->statusCode(), $r->content());
        }
        $stale = $this->api('PATCH', "/content/events/entries/{$e['id']}", ['title' => 'stale', 'version' => 2]);
        $this->assertSame(409, $stale->statusCode(), 'someone saved in between');

        // Two saves of the same version at once: both pass the version check before either
        // writes. The second to write must be refused, not silently replace the first.
        $entries = $this->cms->container->make(Entries::class);
        $loaded = \Kestello\Cms\Content\Entry::query()->find($e['id']);
        $this->db->table('entries')->where('id', $e['id'])->update(['version' => (int) $loaded->version + 1, 'title' => 'theirs']);   // the other save lands first
        $me = new \Kestello\Contracts\Auth\Principal(1, ['id' => 1], 'session', ['*']);
        $this->assertThrows(\Kestello\Cms\Content\ContentException::class, fn() => $entries->update($loaded, ['title' => 'mine'], $me, (int) $loaded->version));
        $this->assertSame('theirs', $this->db->table('entries')->where('id', $e['id'])->value('title'), 'their save stands');
        $this->db->table('entries')->where('id', $e['id'])->update(['version' => (int) $loaded->version, 'title' => 'v4']);

        $r = $this->api('GET', "/content/events/entries/{$e['id']}/revisions");
        $this->assertSame(200, $r->statusCode(), $r->content());
        $revisions = $this->json($r)['data'];
        $this->assertSame(['v4', 'v3', 'v2'], array_column($revisions, 'title'), 'pruned to the type limit (3)');
        $this->assertSame(['title', 'slug'], $revisions[0]['changed']);
        $this->assertTrue($revisions[0]['current']);

        $noop = $this->json($this->api('PATCH', "/content/events/entries/{$e['id']}", ['title' => 'v4']))['data'];
        $this->assertCount(3, $this->json($this->api('GET', "/content/events/entries/{$e['id']}/revisions"))['data'], 'unchanged content adds no revision');

        $restored = $this->json($this->api('POST', "/content/events/entries/{$e['id']}/revisions/{$revisions[2]['id']}/restore"))['data'];
        $this->assertSame(['v2', $noop['version'] + 1], [$restored['title'], $restored['version']]);

        $save = $this->api('PUT', "/content/events/entries/{$e['id']}/autosave", ['title' => 'typing…', 'data' => ['seats' => 11]]);
        $this->assertSame(200, $save->statusCode(), $save->content());
        $loaded = $this->json($this->api('GET', "/content/events/entries/{$e['id']}"))['data'];
        $this->assertSame(['title' => 'typing…', 'data' => ['seats' => 11]], $loaded['autosave']['snapshot']);
        $this->assertSame(10, $loaded['data']['seats'], 'the entry itself is untouched');
        $this->api('PATCH', "/content/events/entries/{$e['id']}", ['title' => 'saved']);
        $this->assertNull($this->json($this->api('GET', "/content/events/entries/{$e['id']}"))['data']['autosave'], 'saving clears it');
    }

    public function testSavesAndTrashingAreEvents(): void
    {
        $seen = [];
        $this->cms->events->on(\Kestello\Cms\Events\EntrySaved::class, static function ($e) use (&$seen): void {
            $seen[] = ['saved', $e->status, $e->created];
        });
        $this->cms->events->on(\Kestello\Cms\Events\EntryTrashed::class, static function ($e) use (&$seen): void {
            $seen[] = ['trashed', $e->title];
        });
        $this->signIn();
        $e = $this->create('events', ['title' => 'Meetup']);
        $r = $this->api('PATCH', "/content/events/entries/{$e['id']}", ['status' => 'published', 'data' => ['starts_at' => '2027-03-01T18:00:00Z']]);
        $this->assertSame(200, $r->statusCode(), $r->content());
        $this->assertSame(422, $this->api('PATCH', "/content/events/entries/{$e['id']}", ['title' => ''])->statusCode());
        $this->api('DELETE', "/content/events/entries/{$e['id']}");
        $this->assertSame([['saved', 'draft', true], ['saved', 'published', false], ['trashed', 'Meetup']], $seen, 'a refused save is no event');
    }

    public function testRevisionsCompareAsPeopleReadThem(): void
    {
        $this->signIn();
        $post = $this->create('post', ['title' => 'Related talk', 'status' => 'published']);
        $e = $this->create('events', ['title' => 'Spring meetup', 'data' => [
            'level' => 'intro', 'body' => '<p>Doors open at six.</p><p>Bring a friend.</p>',
            'speakers' => [['name' => 'Ada']], 'notes' => 'secret plan',
        ]]);
        $r = $this->api('PATCH', "/content/events/entries/{$e['id']}", ['title' => 'Spring meetup 2027', 'data' => [
            'level' => 'pro', 'body' => '<p>Doors open at seven.</p><p>Bring a friend.</p>',
            'speakers' => [['name' => 'Ada'], ['name' => 'Grace']], 'related' => [$post['id']], 'notes' => 'other plan',
        ]]);
        $this->assertSame(200, $r->statusCode(), $r->content());
        $revisions = $this->json($this->api('GET', "/content/events/entries/{$e['id']}/revisions"))['data'];

        $r = $this->api('GET', "/content/events/entries/{$e['id']}/revisions/{$revisions[0]['id']}/diff");
        $this->assertSame(200, $r->statusCode(), $r->content());
        $diff = $this->json($r)['data'];
        $this->assertSame($revisions[1]['id'], $diff['against']['id'], 'against the revision before it');
        $changes = array_column($diff['changes'], null, 'key');
        $this->assertSame(['title', 'slug', 'data.level', 'data.body', 'data.related', 'data.speakers'], array_keys($changes), 'editor order; internal fields left out');
        $this->assertSame([['equal', 'Spring meetup'], ['insert', ' 2027']], $changes['title']['lines'][0]['parts']);
        $this->assertSame([['op' => 'change', 'parts' => [['delete', 'Intro'], ['insert', 'Pro']]]], $changes['data.level']['lines'], 'choice labels, not keys');
        $this->assertSame([
            ['op' => 'change', 'parts' => [['equal', 'Doors open at '], ['delete', 'six.'], ['insert', 'seven.']]],
            ['op' => 'equal', 'text' => 'Bring a friend.'],
        ], $changes['data.body']['lines'], 'rich text as text, line by line');
        $this->assertSame([['op' => 'insert', 'text' => 'Related talk']], $changes['data.related']['lines'], 'entry titles, not ids');
        $this->assertSame([['op' => 'equal', 'text' => '1. Name: Ada'], ['op' => 'insert', 'text' => '2. Name: Grace']], $changes['data.speakers']['lines']);

        $current = $this->json($this->api('GET', "/content/events/entries/{$e['id']}/revisions/{$revisions[1]['id']}/diff?against=current"))['data'];
        $this->assertSame(['current' => true], $current['against']);
        $this->assertSame(count($diff['changes']), count($current['changes']), 'the older revision against now: the same changes');
        $this->assertSame([], $this->json($this->api('GET', "/content/events/entries/{$e['id']}/revisions/{$revisions[0]['id']}/diff?against=current"))['data']['changes']);
        $first = $this->json($this->api('GET', "/content/events/entries/{$e['id']}/revisions/{$revisions[1]['id']}/diff"))['data'];
        $this->assertNull($first['against'], 'the first revision compares with nothing');
        $this->assertSame(422, $this->api('GET', "/content/events/entries/{$e['id']}/revisions/{$revisions[0]['id']}/diff?against=oops")->statusCode());
        $this->assertSame(404, $this->api('GET', "/content/events/entries/{$e['id']}/revisions/999999/diff")->statusCode());
    }

    public function testRangeFiltersOnIndexedFields(): void
    {
        $this->signIn();
        foreach ([['A', 'intro', 30, '2027-01-03T10:00:00Z'], ['B', 'pro', 10, '2027-01-01T10:00:00Z'], ['C', 'pro', 20, '2027-01-02T23:30:00Z']] as [$title, $level, $seats, $at]) {
            $this->create('events', ['title' => $title, 'status' => 'published', 'data' => ['level' => $level, 'seats' => $seats, 'starts_at' => $at]]);
        }
        $this->create('events', ['title' => 'D']);       // a draft with no seats or level

        $titles = function (string $q): array {
            $r = $this->api('GET', '/content/events/entries?sort=title&' . $q);
            $this->assertSame(200, $r->statusCode(), $q . ': ' . $r->content());
            return array_column($this->json($r)['data'], 'title');
        };
        $this->assertSame(['A', 'C'], $titles('filter[seats][gte]=20'));
        $this->assertSame(['C'], $titles('filter[seats][gt]=10&filter[seats][lt]=30'), 'both bounds');
        $this->assertSame(['B', 'C'], $titles('filter[seats][lte]=20'));
        $this->assertSame(['A'], $titles('filter[level][]=intro&filter[seats][in]=10,30'), 'a list, and in, together');
        $this->assertSame(['A', 'B'], $titles('filter[seats][in]=10,30'));
        $this->assertSame(['A', 'B', 'C'], $titles('filter[level][]=intro&filter[level][]=pro'), 'a list means one of');
        $this->assertSame(['D'], $titles('filter[seats][exists]=false'));
        $this->assertSame(['A', 'B', 'C'], $titles('filter[seats][exists]=true'));

        $this->assertSame(['C'], $titles('filter[starts_at]=2027-01-02'), 'a bare date is the whole day');
        $this->assertSame(['A', 'C'], $titles('filter[starts_at][gt]=2027-01-01'), 'after that day');
        $this->assertSame(['B', 'C'], $titles('filter[starts_at][lte]=2027-01-02'), 'up to the end of that day');
        $this->assertSame(['B'], $titles('filter[starts_at][lt]=2027-01-02'));
        $this->assertSame(['C'], $titles('filter[starts_at][gte]=2027-01-02T23:00:00Z&filter[starts_at][lt]=2027-01-03'));
        $this->assertSame(['A'], $titles('filter[starts_at]=2027-01-03T10:00:00Z'), 'an exact moment, in any ISO form');
        $this->assertSame(['A'], $titles('filter[starts_at]=2027-01-03T12:00:00%2B02:00'));

        $problem = fn(string $q) => $this->json($this->api('GET', '/content/events/entries?' . $q))['errors'] ?? [];
        $this->assertSame(['query.filter.level' => ['Ranges work on number and date fields.']], $problem('filter[level][gt]=a'));
        $this->assertSame(['query.filter.seats' => ['Must be a number.']], $problem('filter[seats][gte]=many'));
        $this->assertSame(['query.filter.starts_at' => ['Must be a date (2027-03-01) or a date and time (2027-03-01T18:00:00Z).']], $problem('filter[starts_at][gte]=2027-02-30'));
        $this->assertStringContainsString('Unknown operator "near"', $problem('filter[seats][near]=1')['query.filter.seats'][0] ?? '');
        $this->assertSame(['query.filter.website' => ['Not an indexed field.']], $problem('filter[website][eq]=x'));
        $this->assertSame(422, $this->api('GET', '/content/events/entries?filter=x')->statusCode());
    }

    public function testTrashListsFiltersAndSorting(): void
    {
        $this->signIn();
        foreach ([['A', 'intro', 30, '2027-01-03'], ['B', 'pro', 10, '2027-01-01'], ['C', 'pro', 20, '2027-01-02']] as [$title, $level, $seats, $day]) {
            $this->create('events', ['title' => $title, 'status' => 'published', 'data' => ['level' => $level, 'seats' => $seats, 'starts_at' => "{$day}T10:00:00Z"]]);
        }
        $draft = $this->create('events', ['title' => 'D']);

        $list = fn(string $q) => $this->json($this->api('GET', '/content/events/entries?' . $q));
        $this->assertSame(['B', 'C'], array_column($list('filter[level]=pro&sort=title')['data'], 'title'), 'filter by an indexed field');
        $this->assertSame(['B', 'C', 'A'], array_column($list('sort=field:seats&status=published')['data'], 'title'), 'sort by an indexed number');
        $this->assertSame(['A', 'C', 'B'], array_column($list('sort=-field:starts_at&status=published')['data'], 'title'), 'sort by an indexed date');
        $this->assertSame(422, $this->api('GET', '/content/events/entries?filter[website]=x')->statusCode());
        $this->assertSame(['D'], array_column($list('search=d&status=draft')['data'], 'title'));

        $this->assertSame(200, $this->api('DELETE', "/content/events/entries/{$draft['id']}")->statusCode());
        $counts = $list('status=any')['meta']['counts'];
        $this->assertSame([3, 3, 0, 1], [$counts['any'], $counts['published'], $counts['draft'], $counts['trash']]);
        $this->assertSame(409, $this->api('DELETE', "/content/events/entries/{$draft['id']}")->statusCode());
        $this->assertSame(200, $this->api('POST', "/content/events/entries/{$draft['id']}/restore")->statusCode());
        $this->assertSame(409, $this->api('DELETE', "/content/events/entries/{$draft['id']}?permanent=1")->statusCode(), 'trash first');
        $this->api('DELETE', "/content/events/entries/{$draft['id']}");
        $this->assertSame(204, $this->api('DELETE', "/content/events/entries/{$draft['id']}?permanent=1")->statusCode());
        $this->assertSame(0, $this->db->table('revisions')->where('entry_id', $draft['id'])->count(), 'revisions go with it');
        $this->assertSame(3, $this->db->table('entry_index')->where('field', 'level')->count());
    }

    public function testPagesNestAndResolveByPath(): void
    {
        $this->signIn();
        $about = $this->create('page', ['title' => 'About', 'status' => 'published']);
        $team = $this->create('page', ['title' => 'Team', 'status' => 'published', 'parent_id' => $about['id']]);
        $this->assertSame('/about/team', $team['path']);
        $other = $this->create('page', ['title' => 'Team', 'status' => 'published']);
        $this->assertSame('team', $other['slug'], 'slugs are unique per parent');
        $this->assertSame('blog-2', $this->create('page', ['title' => 'Blog'])['slug'], 'the post archive answers at /blog');
        $this->assertSame('search-2', $this->create('page', ['title' => 'Search'])['slug']);
        $this->assertSame('blog', $this->create('page', ['title' => 'Blog', 'parent_id' => $about['id']])['slug'], 'below the root it is free');
        $taken = $this->json($this->api('POST', '/content/page/entries', ['title' => 'x', 'slug' => 'admin']));
        $this->assertSame(['The address /admin belongs to the site itself. Pick another.'], $taken['errors']['slug']);
        $this->assertStringContainsString('archive', $this->json($this->api('POST', '/content/page/entries', ['title' => 'x', 'slug' => 'blog']))['errors']['slug'][0]);
        $this->cms->routes->get('/shop/{item}', static fn() => null);
        $this->assertSame('shop-2', $this->create('page', ['title' => 'Shop'])['slug'], 'routes from themes and plugins count too');

        $loop = $this->api('PATCH', "/content/page/entries/{$about['id']}", ['parent_id' => $team['id']]);
        $this->assertSame(['parent_id'], array_keys($this->json($loop)['errors']), 'no cycles');
        $this->assertSame(422, $this->api('PATCH', "/content/page/entries/{$about['id']}", ['parent_id' => $about['id']])->statusCode());
        $this->assertSame(422, $this->api('POST', '/content/post/entries', ['title' => 'x', 'parent_id' => $about['id']])->statusCode(), 'posts have no parents');

        $entries = $this->cms->container->make(Entries::class);
        $page = $this->cms->content->get('page');
        $this->assertSame($team['id'], $entries->findPublic($page, 'about/team')?->getKey());
        $this->assertSame($other['id'], $entries->findPublic($page, 'team')?->getKey());
        $this->assertNull($entries->findPublic($page, 'about/nope'));
        $this->assertNull($entries->findPublic($page, '../etc'));
        $this->api('PATCH', "/content/page/entries/{$team['id']}", ['status' => 'draft']);
        $this->assertNull($entries->findPublic($page, 'about/team'), 'drafts are not public');
        $this->assertSame(['title' => 'About', 'path' => '/about'], array_intersect_key(\Kestello\Cms\Content\ContentController::present(Entry::find($about['id']), $page, true), ['title' => 1, 'path' => 1]));

        $event = $this->create('events', ['title' => 'Secret notes', 'data' => ['notes' => 'internal']]);
        $presented = \Kestello\Cms\Content\ContentController::present(Entry::find($event['id']), $this->cms->content->get('events'), true);
        $this->assertFalse(property_exists($presented['data'], 'notes'), 'internal fields stay out of public pages');
    }

    public function testAPageCanBeTheHomePage(): void
    {
        $this->signIn();
        $home = $this->create('page', ['title' => 'Welcome', 'status' => 'published']);
        $this->create('page', ['title' => 'Unfinished']);
        $get = function (string $path): \Kestello\Framework\Core\Response {
            return $this->cms->routes->dispatch(new \Kestello\Framework\Core\Request('GET', $path, $path, [], [], [], [], [], ['REMOTE_ADDR' => '203.0.113.7'], ''));
        };
        $this->cms->routes->get('/', static fn() => new \Kestello\Framework\Core\Response('theme home'));   // what a theme registers

        $this->assertSame('theme home', $get('/')->content(), 'no choice: the theme answers');
        $reading = array_column($this->json($this->api('GET', '/settings'))['meta']['groups'], null, 'id')['reading'] ?? null;
        $choices = $reading['schema']['properties']['front_page']['x-enum-labels'] ?? [];
        $this->assertSame(["The theme's home page", 'Welcome'], array_values($choices), 'published pages only, labelled');

        $this->assertSame(422, $this->api('PATCH', '/settings', ['reading' => ['front_page' => '999']])->statusCode());
        $this->assertSame(200, $this->api('PATCH', '/settings', ['reading' => ['front_page' => (string) $home['id']]])->statusCode());
        $page = $get('/')->payload();
        $this->assertSame(['@core/content/Entry', 'Welcome'], [$page->component, $page->props['title']], 'the page answers at /');
        $moved = $get('/welcome');
        $this->assertSame([301, '/'], [$moved->statusCode(), $moved->headers()['Location'] ?? null], 'and its own address redirects there');
        $this->assertSame('/', $page->props['entry']['path'], 'the page knows where it lives');

        // Links to it go to / too, so nobody follows a redirect.
        $this->cms->menus->location('primary', 'Main');
        $menus = $this->cms->container->make(\Kestello\Cms\Menus\Menus::class);
        $menus->assign('primary', $menus->save(null, 'Main', [['kind' => 'entry', 'ref' => $home['id']]]));
        $this->assertSame('/', $menus->forLocations()['primary'][0]['url'] ?? null, 'menus');
        $entry = Entry::find($home['id']);
        $this->assertSame(['/', '/welcome'], [$entry?->url(), $entry?->path()], 'url() is where links go; path() is what its slug makes');
        $child = $this->create('page', ['title' => 'Team', 'status' => 'published', 'parent_id' => $home['id']]);
        $this->assertSame('/welcome/team', Entry::find($child['id'])?->url(), 'pages under it keep their addresses');

        $this->api('PATCH', "/content/page/entries/{$home['id']}", ['status' => 'draft']);
        $this->assertSame('theme home', $get('/')->content(), 'an unpublished choice falls back to the theme');
    }

    public function testPickerLookupsRespectOwnershipAndHideEmails(): void
    {
        $this->signIn('author');
        $mine = $this->create('post', ['title' => 'Author pick']);
        $this->signIn('editor');
        $other = $this->create('post', ['title' => 'Editor pick']);
        $page = $this->create('page', ['title' => 'Pick page']);

        $titles = fn(string $q) => array_column($this->json($this->api('GET', '/content/lookup?' . $q))['data'], 'title');
        $this->assertSame(['Author pick'], $titles('search=author'));
        $this->assertSame(['Pick page'], $titles('search=pick&types=page'));
        $this->assertSame(['Author pick', 'Editor pick'], self::sorted($titles("ids={$mine['id']},{$other['id']},{$page['id']}&types=post")));

        $this->signIn('author');
        $this->assertSame(['Author pick'], $titles("ids={$mine['id']},{$other['id']},{$page['id']}"), 'own posts only; no pages');

        $users = $this->json($this->api('GET', '/content/users?search=edi'))['data'];
        $this->assertSame([['id' => 2, 'username' => 'editor']], $users, 'names, never emails or roles');
        $this->assertSame(['ana'], array_column($this->json($this->api('GET', '/content/users?ids=1'))['data'], 'username'));
    }

    public function testLinkFieldsFindEveryPublishedPageEvenOnesTheyCannotEdit(): void
    {
        $this->signIn('editor');
        $this->create('page', ['title' => 'Contact us', 'slug' => 'contact', 'status' => 'published']);
        $this->create('page', ['title' => 'Contact draft', 'slug' => 'contact-draft']);
        $this->create('post', ['title' => 'Contact the baker', 'slug' => 'baker', 'status' => 'published']);

        $this->signIn('author');
        $found = $this->json($this->api('GET', '/content/lookup?linkable=1&search=contact'))['data'];
        $this->assertSame(['/blog/baker', '/contact'], self::sorted(array_column($found, 'path')), 'published only, pages an author may not edit included');
        $this->assertSame([], $this->json($this->api('GET', '/content/lookup?search=contact&types=page'))['data'], 'without linkable, still only what they may edit');
    }

    /** @param list<string> $keys */
    private static function sorted(array $keys): array
    {
        sort($keys);

        return $keys;
    }

    public function testABrokenJsonBodyIsRefusedNotTreatedAsEmpty(): void
    {
        $send = function (string $raw): \Kestello\Framework\Core\Response {
            $this->nextRequest();
            $uri = '/api/v1/content/post/entries';
            $post = \Kestello\Framework\Core\Request::parseBody('POST', 'application/json', [], $raw);

            return $this->cms->routes->dispatch(new \Kestello\Framework\Core\Request('POST', $uri, $uri, ['Content-Type' => 'application/json'], [], $post, [], [], ['REMOTE_ADDR' => '203.0.113.7'], $raw));
        };
        $this->signIn();
        $before = \Kestello\Framework\Database\QueryBuilder::table('entries')->count();
        $broken = $send('{"title":"x","data":{"image":,}}');
        $this->assertSame(400, $broken->statusCode());
        $this->assertSame('urn:kestello:problem:malformed_body', $this->json($broken)['type']);
        $this->assertSame(400, $send('"just a string"')->statusCode());
        $this->assertSame($before, \Kestello\Framework\Database\QueryBuilder::table('entries')->count(), 'no blank draft');
        $this->assertSame(200, $send('{"title":"Fine"}')->statusCode());
    }
}
