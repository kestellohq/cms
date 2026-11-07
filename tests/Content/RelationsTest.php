<?php

declare(strict_types=1);

namespace Kestello\Tests\Content;

use Kestello\Cms\Blocks\BlockContext;
use Kestello\Cms\Blocks\BlocksModule;
use Kestello\Cms\Content\ContentModule;
use Kestello\Cms\Content\Entry;
use Kestello\Cms\Content\EntryRelations;
use Kestello\Cms\Content\Field;
use Kestello\Cms\Media\MediaModule;
use Kestello\Framework\Core\ExecutionContext;
use Kestello\Framework\Database\Model;
use Kestello\Tests\Auth\AuthTestCase;

/** Two-way relationships: an entry knows which entries link to it, through any relationship field. */
final class RelationsTest extends AuthTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Model::resetRegistry();
        $this->cms->storage->configure($this->tempDir(), []);
        foreach ([new ContentModule(), new MediaModule(), new BlocksModule()] as $module) {
            $module->register($this->cms->container);
            $module->boot($this->cms);
        }
        ExecutionContext::run('plugin:talks', function (): void {
            $this->cms->content->type('talks')->label('Talk', 'Talks')->path('/talks')
                ->fields([
                    Field::entry('speakers', ['post'], multiple: true)->label('Speakers'),
                    Field::group('host', [Field::entry('by', ['post'])])->label('Host'),
                    Field::repeater('sessions', [Field::entry('about', ['post', 'talks'])])->label('Sessions'),
                ]);
        });
        ContentModule::finalize($this->cms);
        $this->createUser();
        $this->createUser(['username' => 'contributor', 'email' => 'contributor@example.test', 'role' => 'contributor']);
    }

    protected function tearDown(): void
    {
        Model::resetRegistry();
        parent::tearDown();
    }

    private function signIn(string $login = 'ana'): void
    {
        $this->assertSame(200, $this->api('POST', '/auth/session', ['login' => $login, 'password' => self::PASSWORD])->statusCode());
    }

    /** @return array<string, mixed> */
    private function create(string $type, array $body): array
    {
        $r = $this->api('POST', "/content/{$type}/entries", $body);
        $this->assertSame(200, $r->statusCode(), $r->content());

        return $this->json($r)['data'];
    }

    /** @return list<array{source_id: int, target_id: int, field: string}> */
    private function rows(): array
    {
        return array_map(
            static fn($r) => ['source_id' => (int) $r['source_id'], 'target_id' => (int) $r['target_id'], 'field' => (string) $r['field']],
            array_map(static fn($r) => (array) $r, $this->db->table(EntryRelations::TABLE)->orderBy('source_id')->orderBy('target_id')->orderBy('field')->get()),
        );
    }

    public function testEverySaveRecordsWhatTheEntryLinksToAtAnyDepth(): void
    {
        $this->signIn();
        $ada = $this->create('post', ['title' => 'Ada']);
        $grace = $this->create('post', ['title' => 'Grace']);
        $talk = $this->create('talks', ['title' => 'Engines', 'data' => [
            'speakers' => [$ada['id'], $grace['id']],
            'host' => ['by' => $ada['id']],
            'sessions' => [['about' => $grace['id']], ['about' => $grace['id']]],
        ]]);

        $this->assertSame([
            ['source_id' => $talk['id'], 'target_id' => $ada['id'], 'field' => 'host'],
            ['source_id' => $talk['id'], 'target_id' => $ada['id'], 'field' => 'speakers'],
            ['source_id' => $talk['id'], 'target_id' => $grace['id'], 'field' => 'sessions'],
            ['source_id' => $talk['id'], 'target_id' => $grace['id'], 'field' => 'speakers'],
        ], $this->rows(), 'nested links count under their top-level field, once each');

        $this->assertSame(200, $this->api('PATCH', "/content/talks/entries/{$talk['id']}", ['data' => ['speakers' => [$grace['id']], 'host' => ['by' => null], 'sessions' => []], 'version' => $talk['version']])->statusCode());
        $this->assertSame([['source_id' => $talk['id'], 'target_id' => $grace['id'], 'field' => 'speakers']], $this->rows(), 'a save replaces the links');

        $this->db->table('entries')->where('id', $grace['id'])->delete();
        $this->assertSame([], $this->rows(), 'deleting the target removes the link');
    }

    public function testLinkedFromListsWhatThePersonMaySee(): void
    {
        $this->signIn();
        $ada = $this->create('post', ['title' => 'Ada']);
        $mine = $this->create('talks', ['title' => 'Engines', 'data' => ['speakers' => [$ada['id']], 'host' => ['by' => $ada['id']]]]);
        $gone = $this->create('talks', ['title' => 'Old talk', 'data' => ['speakers' => [$ada['id']]]]);
        $this->assertSame(200, $this->api('DELETE', "/content/talks/entries/{$gone['id']}")->statusCode());

        $r = $this->api('GET', "/content/post/entries/{$ada['id']}/linked-from");
        $this->assertSame(200, $r->statusCode(), $r->content());
        $links = $this->json($r)['data'];
        $this->assertSame([[
            'id' => $mine['id'], 'type' => 'talks', 'type_label' => 'Talk', 'title' => 'Engines', 'status' => 'draft',
            'fields' => [['name' => 'host', 'label' => 'Host'], ['name' => 'speakers', 'label' => 'Speakers']],
        ]], $links, 'one row per entry, trashed ones left out');

        // A contributor sees only what they could open: their own talk, not the administrator's.
        $this->signIn('contributor');
        $own = $this->create('post', ['title' => 'My draft']);
        $this->assertSame(404, $this->api('GET', "/content/post/entries/{$ada['id']}/linked-from")->statusCode(), "someone else's post");
        $this->assertSame([], $this->json($this->api('GET', "/content/post/entries/{$own['id']}/linked-from"))['data']);
    }

    public function testListsFilterToEntriesThatLinkToAnother(): void
    {
        $this->signIn();
        $ada = $this->create('post', ['title' => 'Ada']);
        $grace = $this->create('post', ['title' => 'Grace']);
        $one = $this->create('talks', ['title' => 'One', 'data' => ['speakers' => [$ada['id']]]]);
        $two = $this->create('talks', ['title' => 'Two', 'data' => ['host' => ['by' => $ada['id']], 'speakers' => [$grace['id']]]]);
        $this->create('talks', ['title' => 'Three', 'data' => ['speakers' => [$grace['id']]]]);

        $ids = fn(string $query) => array_column($this->json($this->api('GET', "/content/talks/entries?{$query}&sort=title"))['data'], 'id');
        $this->assertSame([$one['id'], $two['id']], $ids("links_to={$ada['id']}"));
        $this->assertSame([$one['id']], $ids("links_to={$ada['id']}&links_to_field=speakers"));
        $this->assertSame(422, $this->api('GET', "/content/talks/entries?links_to={$ada['id']}&links_to_field=nope")->statusCode(), 'only relationship fields of the type');
    }

    public function testEntryListsShowWhatLinksToTheEntryBeingViewedOrWhatItLinksTo(): void
    {
        $this->signIn();
        $ada = $this->create('post', ['title' => 'Ada', 'status' => 'published']);
        $this->create('post', ['title' => 'Grace', 'status' => 'published']);
        $talk = $this->create('talks', ['title' => 'Engines', 'status' => 'published', 'data' => ['speakers' => [$ada['id']]]]);
        $this->create('talks', ['title' => 'Compilers', 'status' => 'published']);
        $titles = function (string $type, string $related, ?int $viewing): array {
            $node = ['type' => 'core/query', 'props' => ['type' => $type, 'related' => $related], 'children' => [['type' => 'core/entry-field', 'props' => ['field' => 'title'], 'children' => []]]];
            [$clean, $errors] = $this->cms->blocks->validate([$node]);
            $this->assertSame([], $errors);
            $entry = $viewing === null ? null : Entry::query()->find($viewing);
            $out = $this->cms->blocks->prepare($clean, new BlockContext($entry, $entry === null ? null : $this->cms->content->get($entry->type)));

            return array_map(static fn($item) => ((array) $item['children'][0]['props'])['value'], $out[0]['children']);
        };

        $this->assertSame(['Engines'], $titles('talks', 'links_here', $ada['id']), "on Ada's page: the talks that name her");
        $this->assertSame(['Ada'], $titles('post', 'linked', $talk['id']), "on the talk's page: the posts it names");
        $this->assertSame([], $titles('talks', 'links_here', null), 'with no entry being viewed, nothing');
        $this->assertSame(['Compilers', 'Engines'], array_values(array_intersect(['Compilers', 'Engines'], $titles('talks', '', null))), 'without the option, every talk');
    }

    public function testRebuildingFillsTheTableForEntriesSavedBefore(): void
    {
        $this->signIn();
        $ada = $this->create('post', ['title' => 'Ada']);
        $talk = $this->create('talks', ['title' => 'Engines', 'data' => ['speakers' => [$ada['id']]]]);
        $this->db->table(EntryRelations::TABLE)->delete();

        $this->assertSame(1, $this->cms->container->make(EntryRelations::class)->rebuild());
        $this->assertSame([['source_id' => $talk['id'], 'target_id' => $ada['id'], 'field' => 'speakers']], $this->rows());
    }
}
