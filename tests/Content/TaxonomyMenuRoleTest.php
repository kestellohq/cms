<?php

declare(strict_types=1);

namespace Kestello\Tests\Content;

use Kestello\Cms\Auth\RoleStore;
use Kestello\Cms\Content\ContentModule;
use Kestello\Cms\Content\Field;
use Kestello\Cms\Menus\Menus;
use Kestello\Framework\Core\ExecutionContext;
use Kestello\Framework\Database\Model;
use Kestello\Tests\Auth\AuthTestCase;

/** Taxonomies and terms, menus and locations, roles edited in the admin. */
final class TaxonomyMenuRoleTest extends AuthTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Model::resetRegistry();
        $module = new ContentModule();
        $module->register($this->cms->container);
        $module->boot($this->cms);
        ExecutionContext::run('plugin:events', function (): void {
            $this->cms->content->type('events')->label('Event', 'Events')->path('/events')
                ->fields([Field::term('topic', 'events-topic')->indexed()]);
            $this->cms->content->taxonomy('events-topic')->label('Topic', 'Topics')->for('events', 'post');
        });
        $this->cms->menus->location('primary', 'Main navigation');
        ContentModule::finalize($this->cms);
        $this->createUser();
        foreach (['editor', 'author', 'contributor', 'user'] as $role) {
            $this->createUser(['username' => $role, 'email' => "{$role}@example.test", 'role' => $role]);
        }
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
    private function ok(string $method, string $path, array $body = []): array
    {
        $r = $this->api($method, $path, $body);
        $this->assertTrue($r->statusCode() < 300, "{$method} {$path}: {$r->statusCode()} {$r->content()}");

        return $this->json($r)['data'] ?? [];
    }

    public function testTermsNestRenameAndDelete(): void
    {
        $this->cms->api->extend('terms')->field('acme_colour', static fn(array $row): string => 'teal');
        $this->signIn('editor');
        $news = $this->ok('POST', '/taxonomies/category/terms', ['name' => 'News']);
        $local = $this->ok('POST', '/taxonomies/category/terms', ['name' => 'Local news', 'parent_id' => $news['id']]);
        $this->assertSame(['news', 'local-news', '/category/news'], [$news['slug'], $local['slug'], $news['path']]);
        $this->assertSame('teal', $news['acme_colour'], 'plugins add fields to terms');
        $dupe = $this->ok('POST', '/taxonomies/category/terms', ['name' => 'News']);
        $this->assertSame('news-2', $dupe['slug']);

        $tree = $this->ok('GET', '/taxonomies/category/terms');
        $this->assertSame([['News', 0], ['Local news', 1], ['News', 0]], array_map(fn($t) => [$t['name'], $t['depth']], $tree));

        $loop = $this->api('PATCH', "/taxonomies/category/terms/{$news['id']}", ['parent_id' => $local['id']]);
        $this->assertSame(['parent_id'], array_keys($this->json($loop)['errors']), 'no cycles');
        $this->assertSame(422, $this->api('POST', '/taxonomies/tag/terms', ['name' => 'x', 'parent_id' => $news['id']])->statusCode(), 'tags do not nest');

        $this->assertSame(204, $this->api('DELETE', "/taxonomies/category/terms/{$news['id']}")->statusCode());
        $this->assertNull($this->db->table('terms')->where('id', $local['id'])->value('parent_id'), 'children move up');

        $this->signIn('author');
        $this->assertSame(403, $this->api('POST', '/taxonomies/category/terms', ['name' => 'Nope'])->statusCode(), 'authors assign, editors manage');
        $this->assertSame(200, $this->api('GET', '/taxonomies/category/terms')->statusCode());
        $this->signIn('user');
        $this->assertSame(403, $this->api('GET', '/taxonomies/category/terms')->statusCode());
    }

    public function testEntriesGetTermsAndTypedTagsAreCreated(): void
    {
        $this->signIn('editor');
        $news = $this->ok('POST', '/taxonomies/category/terms', ['name' => 'News']);

        $this->signIn('author');
        $post = $this->ok('POST', '/content/post/entries', ['title' => 'Tagged', 'status' => 'published', 'terms' => ['category' => [$news['id']], 'tag' => ['Bread', 'bread', 'Rye']]]);
        $this->assertSame(['News'], array_column($post['terms']->category ?? $post['terms']['category'], 'name'));
        $this->assertSame(['Bread', 'Rye'], array_column($post['terms']['tag'], 'name'), 'typed tags are created once');

        $bad = $this->api('PATCH', "/content/post/entries/{$post['id']}", ['terms' => ['category' => ['Brand new'], 'events-topic' => [999], 'nope' => [1]]]);
        $this->assertSame(['terms.category', 'terms.events-topic', 'terms.nope'], self::sorted(array_keys($this->json($bad)['errors'])), 'no inline categories for authors; unknown ids and taxonomies');

        $rye = $this->db->table('terms')->where('slug', 'rye')->value('id');
        $list = $this->json($this->api('GET', "/content/post/entries?term={$rye}"));
        $this->assertSame(['Tagged'], array_column($list['data'], 'title'));

        $this->ok('PATCH', "/content/post/entries/{$post['id']}", ['terms' => ['tag' => []]]);
        $this->assertSame(0, $this->db->table('entry_term')->where('taxonomy', 'tag')->count());
        $revisions = $this->ok('GET', "/content/post/entries/{$post['id']}/revisions");
        $this->assertSame(['terms'], $revisions[0]['changed']);
        $restored = $this->ok('POST', "/content/post/entries/{$post['id']}/revisions/{$revisions[1]['id']}/restore");
        $this->assertSame(['Bread', 'Rye'], array_column($restored['terms']['tag'], 'name'), 'revisions bring terms back');

        $counts = array_column($this->ok('GET', '/taxonomies/tag/terms'), 'count', 'name');
        $this->assertSame(['Bread' => 1, 'Rye' => 1], $counts);

        $this->signIn('editor');
        $topic = $this->ok('POST', '/taxonomies/events-topic/terms', ['name' => 'Workshops']);
        $event = $this->ok('POST', '/content/events/entries', ['title' => 'Bake-off', 'data' => ['topic' => $topic['id']]]);
        $this->assertSame($topic['id'], $event['data']['topic']);
        $this->assertSame(422, $this->api('POST', '/content/events/entries', ['title' => 'x', 'data' => ['topic' => $news['id']]])->statusCode(), 'term field checks the taxonomy');
    }

    public function testMenusValidateResolveAndFillLocations(): void
    {
        $this->signIn('editor');
        $about = $this->ok('POST', '/content/page/entries', ['title' => 'About', 'status' => 'published']);
        $draft = $this->ok('POST', '/content/page/entries', ['title' => 'Draft page']);
        $cat = $this->ok('POST', '/taxonomies/category/terms', ['name' => 'News']);
        $this->ok('POST', '/content/post/entries', ['title' => 'Opening day', 'status' => 'published', 'terms' => ['category' => [$cat['id']]]]);   // a term needs a published entry to be public

        $bad = $this->api('POST', '/menus', ['name' => 'Main', 'items' => [
            ['kind' => 'url', 'url' => 'javascript:alert(1)', 'label' => 'x'],
            ['kind' => 'url', 'url' => '/contact'],
            ['kind' => 'entry', 'ref' => 99999],
            ['kind' => 'archive', 'ref' => 'page'],
            ['kind' => 'weird'],
        ]]);
        $this->assertSame(['items.0.url', 'items.1.label', 'items.2.ref', 'items.3.ref', 'items.4.kind'], self::sorted(array_keys($this->json($bad)['errors'])));

        $menu = $this->ok('POST', '/menus', ['name' => 'Main', 'items' => [
            ['kind' => 'entry', 'ref' => $about['id'], 'children' => [['kind' => 'entry', 'ref' => $draft['id']], ['kind' => 'term', 'ref' => $cat['id'], 'label' => 'Latest']]],
            ['kind' => 'archive', 'ref' => 'post'],
            ['kind' => 'url', 'url' => 'https://example.org', 'label' => 'Elsewhere', 'new_tab' => true],
        ]]);
        $this->assertSame([true, false], [$menu['items'][0]['resolved']['live'], $menu['items'][0]['children'][0]['resolved']['live']], 'the editor sees drafts as hidden');
        $this->assertSame(['Page', 'Category'], [$menu['items'][0]['resolved']['type_label'], $menu['items'][0]['children'][1]['resolved']['type_label']], 'the editor says what each item is');

        $this->cms->api->extend('menus')->field('acme_note', static fn(array $row): string => 'menu ' . $row['id']);
        $read = $this->ok('GET', "/menus/{$menu['id']}");
        $this->assertSame([$menu['items'], "menu {$menu['id']}"], [$read['items'], $read['acme_note']], 'read back as saved; plugins add fields');
        $this->assertMatchesRegularExpression('/Z$/', (string) $this->ok('GET', '/menus')['menus'][0]['updated_at'], 'UTC with its zone');
        $this->assertSame(404, $this->api('GET', '/menus/99999')->statusCode());

        $deep = ['kind' => 'url', 'url' => '/a', 'label' => 'a'];
        for ($i = 0; $i < Menus::MAX_DEPTH; $i++) {
            $deep = ['kind' => 'url', 'url' => '/a', 'label' => 'a', 'children' => [$deep]];
        }
        $this->assertSame(422, $this->api('PUT', "/menus/{$menu['id']}", ['name' => 'Main', 'items' => [$deep]])->statusCode());

        $this->assertSame(422, $this->api('PUT', '/menus/locations', ['locations' => ['sidebar' => $menu['id']]])->statusCode(), 'undeclared location');
        $this->assertSame(['primary' => $menu['id']], $this->ok('PUT', '/menus/locations', ['locations' => ['primary' => $menu['id']]]));

        $public = $this->cms->container->make(Menus::class)->forLocations();
        $this->assertSame([
            ['label' => 'About', 'url' => '/about', 'target' => null, 'children' => [['label' => 'Latest', 'url' => '/category/news', 'target' => null, 'children' => []]]],
            ['label' => 'Posts', 'url' => '/blog', 'target' => null, 'children' => []],
            ['label' => 'Elsewhere', 'url' => 'https://example.org', 'target' => '_blank', 'children' => []],
        ], $public['primary'], 'drafts drop out; titles and paths come from the targets');

        $this->ok('PATCH', "/content/page/entries/{$about['id']}", ['title' => 'Our story', 'slug' => 'story']);
        $this->assertSame(['Our story', '/story'], [$this->cms->container->make(Menus::class)->forLocations()['primary'][0]['label'], $this->cms->container->make(Menus::class)->forLocations()['primary'][0]['url']]);

        $this->assertSame(204, $this->api('DELETE', "/menus/{$menu['id']}")->statusCode());
        $this->assertSame(['primary' => []], $this->cms->container->make(Menus::class)->forLocations());

        $this->signIn('author');
        $this->assertSame(403, $this->api('GET', '/menus')->statusCode());
    }

    public function testRolesAreEditedWithoutEscalation(): void
    {
        $this->signIn('ana');
        // capabilities that let someone make themselves an administrator, or act as one, are flagged
        $caps = array_column($this->json($this->api('GET', '/capabilities'))['data'], 'sensitive', 'name');
        foreach (['users.manage', 'roles.manage', 'security.manage', 'system.manage'] as $name) {
            $this->assertTrue($caps[$name], $name);
        }
        foreach (['snippets.manage', 'backups.manage', 'plugins.manage', 'core.update', 'theme.manage'] as $name) {
            $this->assertTrue($caps[$name] ?? true, "{$name}, when its module runs");
        }
        $this->assertFalse($caps['menus.manage']);
        $this->cms->auth->roles->capability('code.run', 'Run code', sensitive: true);
        $this->assertTrue(array_column($this->json($this->api('GET', '/capabilities'))['data'], 'sensitive', 'name')['code.run'], 'others may flag theirs');

        $reviewer = $this->ok('POST', '/roles', ['name' => 'reviewer', 'label' => 'Reviewer', 'capabilities' => ['admin.access', 'content.access', 'content.post.edit', 'content.post.edit_others', 'roles.manage', 'users.read']]);
        $this->assertTrue($reviewer['custom']);
        $this->assertSame(422, $this->api('POST', '/roles', ['name' => 'editor', 'label' => 'Dupe'])->statusCode());
        $this->assertSame(422, $this->api('POST', '/roles', ['name' => 'x2', 'label' => 'X', 'capabilities' => ['made.up']])->statusCode());
        $this->assertSame(403, $this->api('PATCH', '/roles/admin', ['capabilities' => []])->statusCode());

        $editor = $this->ok('PATCH', '/roles/editor', ['capabilities' => array_values(array_diff($this->cms->auth->roles->capabilitiesFor('editor'), ['menus.manage']))]);
        $this->assertTrue($editor['customized']);
        $this->assertNotContains('menus.manage', $this->cms->auth->roles->capabilitiesFor('editor'));
        $this->signIn('editor');
        $this->assertSame(403, $this->api('GET', '/menus')->statusCode(), 'takes effect on the next request');

        // A plugin capability granted in code later still reaches the customized role.
        $this->cms->auth->roles->grant('editor', 'audit.read');
        $this->assertContains('audit.read', $this->cms->auth->roles->capabilitiesFor('editor'));

        $this->signIn('ana');
        $this->ok('POST', '/roles', ['name' => 'helper', 'label' => 'Helper', 'capabilities' => ['admin.access', 'content.access']]);
        $this->db->table('users')->where('username', 'author')->update(['role' => 'reviewer']);
        $this->signIn('author'); // now a reviewer: holds roles.manage
        $this->assertSame(403, $this->api('PATCH', '/roles/reviewer', ['capabilities' => ['admin.access']])->statusCode(), 'not your own role');
        $this->assertSame(403, $this->api('PATCH', '/roles/contributor', ['capabilities' => ['admin.access']])->statusCode(), 'nor a role that can do what you cannot');
        $grab = $this->api('PATCH', '/roles/helper', ['capabilities' => ['admin.access', 'settings.manage']]);
        $this->assertSame(422, $grab->statusCode(), "can't grant what you don't hold");
        $this->assertSame(200, $this->api('PATCH', '/roles/helper', ['capabilities' => ['admin.access', 'content.access', 'content.post.edit']])->statusCode());

        $this->signIn('ana');
        $this->assertSame(200, $this->api('DELETE', '/roles/editor')->statusCode(), 'built-in roles reset');
        $this->assertContains('menus.manage', $this->cms->auth->roles->capabilitiesFor('editor'));
        $this->assertSame(409, $this->api('DELETE', '/roles/editor')->statusCode());
        $this->assertSame(204, $this->api('DELETE', '/roles/reviewer?reassign=contributor')->statusCode());
        $this->assertSame('contributor', $this->db->table('users')->where('username', 'author')->value('role'));
        $this->assertFalse($this->cms->auth->roles->exists('reviewer'));
        $this->assertSame(['role.created', 'role.updated', 'role.created', 'role.updated', 'role.reset', 'role.deleted'], array_values(array_filter($this->auditEvents(), fn($e) => str_starts_with($e, 'role.'))));

        RoleStore::forget($this->cms->auth->roles, 'helper');
    }

    /** @param list<string> $keys */
    private static function sorted(array $keys): array
    {
        sort($keys);

        return $keys;
    }
}
