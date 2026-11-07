<?php

declare(strict_types=1);

namespace Kestello\Tests\Content;

use Kestello\Cms\Blocks\BlocksModule;
use Kestello\Cms\Content\ContentModule;
use Kestello\Cms\Content\Previews;
use Kestello\Cms\Media\MediaModule;
use Kestello\Framework\Core\Request;
use Kestello\Framework\Core\Response;
use Kestello\Framework\Database\Model;
use Kestello\Support\Clock;
use Kestello\Tests\Auth\AuthTestCase;

/** Preview links: the editor's own, and share links for people without an account. */
final class PreviewsTest extends AuthTestCase
{
    protected function setUp(): void
    {
        Model::resetRegistry();
        parent::setUp();
        foreach ([new ContentModule(), new MediaModule(), new BlocksModule()] as $module) {
            $module->register($this->cms->container);
            $module->boot($this->cms);
        }
        ContentModule::finalize($this->cms);
        $this->createUser();
        $this->createUser(['username' => 'cleo', 'email' => 'cleo@example.test', 'role' => 'contributor']);
        $this->createUser(['username' => 'aria', 'email' => 'aria@example.test', 'role' => 'author']);
    }

    protected function tearDown(): void
    {
        Clock::unfreeze();
        Model::resetRegistry();
        parent::tearDown();
    }

    public function testTheEditorPreviewsTheirDraftWithUnsavedChanges(): void
    {
        $this->signIn('cleo');
        $post = $this->draft('Draft title');
        $this->assertSame(404, $this->visit('/blog/draft-title')->statusCode(), 'a draft has no public page');

        $this->assertSame(200, $this->api('PUT', "/content/post/entries/{$post['id']}/autosave", ['title' => 'Unsaved title', 'data' => ['body' => '<p>Unsaved body</p>']])->statusCode());
        $link = $this->link($post['id']);
        $this->assertMatchesRegularExpression('#^/preview/[A-Za-z0-9_-]{43}$#', $link['url']);

        $page = $this->visit($link['url']);
        $this->assertSame(200, $page->statusCode());
        $this->assertSame('Unsaved title', $page->payload()->props['entry']['title'], 'the editor sees what they are typing');
        $this->assertStringContainsString('Unsaved body', (string) json_encode($page->payload()->props['entry']['data']));
        $this->assertStringContainsString('no-store', $page->headers()['Cache-Control']);
        $this->assertSame('noindex', $page->headers()['X-Robots-Tag']);
        $this->assertStringContainsString('This post is a draft, shown with your unsaved changes.', (string) $this->cms->filters->apply('page.body_open', '', []));
        $this->assertSame('Draft title', $this->db->table('entries')->where('id', $post['id'])->value('title'), 'previewing saves nothing');

        $this->signIn('ana');
        $this->assertSame(404, $this->visit($link['url'])->statusCode(), "someone else cannot use the editor's own link");
        $this->api('DELETE', '/auth/session');
        $this->assertSame(404, $this->visit($link['url'])->statusCode());

        $this->signIn('cleo');
        Clock::freeze(Clock::now()->modify('+61 minutes'));
        $this->assertSame(404, $this->visit($link['url'])->statusCode(), 'editor links last an hour');
    }

    public function testShareLinksWorkForGuestsUntilRevokedOrExpired(): void
    {
        $this->signIn('cleo');
        $post = $this->draft('For the client');
        $this->api('PUT', "/content/post/entries/{$post['id']}/autosave", ['title' => 'Not for the client']);
        $share = $this->link($post['id'], ['kind' => 'share', 'days' => 3, 'label' => 'Client']);
        $this->assertSame(1, $this->db->table('audit_log')->where('event', 'content.preview_shared')->count());
        $this->assertSame(0, $this->db->table('preview_links')->where('token_hash', substr($share['url'], 9))->count(), 'only the hash is stored');

        $list = $this->json($this->api('GET', "/content/post/entries/{$post['id']}/previews"))['data'];
        $this->assertSame([['Client', 0, 'cleo']], array_map(static fn(array $l) => [$l['label'], $l['views'], $l['created_by']], $list));
        $this->assertArrayNotHasKey('url', $list[0], 'the link is shown once, when made');

        $this->api('DELETE', '/auth/session');
        $page = $this->visit($share['url']);
        $this->assertSame(200, $page->statusCode());
        $this->assertSame('For the client', $page->payload()->props['entry']['title'], 'share links show the saved entry, never an autosave');
        $this->assertSame(1, (int) $this->db->table('preview_links')->where('id', $share['id'])->value('views'));

        Clock::freeze(Clock::now()->modify('+2 days'));
        $this->assertSame(200, $this->visit($share['url'])->statusCode());
        Clock::freeze(Clock::now()->modify('+2 days'));
        $this->assertSame(404, $this->visit($share['url'])->statusCode(), 'expired');
        $this->assertSame(1, $this->cms->container->make(Previews::class)->prune());
        Clock::unfreeze();

        $this->signIn('cleo');
        $again = $this->link($post['id'], ['kind' => 'share', 'days' => 1]);
        $this->assertSame(204, $this->api('DELETE', "/content/post/entries/{$post['id']}/previews/{$again['id']}")->statusCode());
        $this->assertSame(404, $this->api('DELETE', "/content/post/entries/{$post['id']}/previews/{$again['id']}")->statusCode());
        $this->api('DELETE', '/auth/session');
        $this->assertSame(404, $this->visit($again['url'])->statusCode(), 'revoked links stop at once');
    }

    public function testOnlyThoseWhoCanEditMakePreviews(): void
    {
        $this->signIn('cleo');
        $post = $this->draft('Cleo only');
        $this->signIn('aria');
        $this->assertSame(404, $this->api('POST', "/content/post/entries/{$post['id']}/previews")->statusCode(), "an author cannot see a contributor's draft");
        $this->signIn('ana');
        $this->assertSame(200, $this->api('POST', "/content/post/entries/{$post['id']}/previews", ['kind' => 'share'])->statusCode(), 'an admin edits anyone\'s');
        $this->assertSame(422, $this->api('POST', "/content/post/entries/{$post['id']}/previews", ['kind' => 'share', 'days' => 31])->statusCode());
        $this->assertSame(404, $this->visit('/preview/not-a-token')->statusCode());
        $this->assertSame(404, $this->visit('/preview/' . str_repeat('a', 43))->statusCode());

        $share = $this->json($this->api('POST', "/content/post/entries/{$post['id']}/previews", ['kind' => 'share']))['data'];
        $this->assertSame(200, $this->api('DELETE', "/content/post/entries/{$post['id']}")->statusCode(), 'to the trash');
        $this->assertSame(404, $this->visit($share['url'])->statusCode(), 'a trashed entry has no preview');
    }

    public function testBlockTreesCompareAsOneLinePerBlock(): void
    {
        $types = $this->cms->content->fields;
        $field = \Kestello\Cms\Content\Field::blocks('body');
        $tree = [
            ['type' => 'core/heading', 'id' => 'h1', 'props' => ['text' => 'Opening hours', 'level' => '2'], 'children' => []],
            ['type' => 'core/group', 'id' => 'g1', 'props' => [], 'children' => [
                ['type' => 'core/text', 'id' => 't1', 'props' => ['text' => '<p>Monday to <strong>Friday</strong></p>'], 'children' => []],
            ]],
        ];
        $this->assertSame("Heading: Opening hours · level 2\nGroup\n  Text: Monday to Friday", $types->get('blocks')->text($tree, $field, $types));
    }

    private function signIn(string $login): void
    {
        $this->assertSame(200, $this->api('POST', '/auth/session', ['login' => $login, 'password' => self::PASSWORD])->statusCode());
    }

    /** @return array<string, mixed> */
    private function draft(string $title): array
    {
        $r = $this->api('POST', '/content/post/entries', ['title' => $title, 'data' => ['body' => '<p>Saved body</p>']]);
        $this->assertSame(200, $r->statusCode(), $r->content());

        return $this->json($r)['data'];
    }

    /** @return array<string, mixed> */
    private function link(int $id, array $body = []): array
    {
        $r = $this->api('POST', "/content/post/entries/{$id}/previews", $body);
        $this->assertSame(200, $r->statusCode(), $r->content());

        return $this->json($r)['data'];
    }

    private function visit(string $uri): Response
    {
        $this->nextRequest();

        return $this->cms->routes->dispatch(new Request('GET', $uri, $uri, [], [], [], [], [], ['REMOTE_ADDR' => '203.0.113.7'], ''));
    }
}
