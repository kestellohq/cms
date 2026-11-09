<?php

declare(strict_types=1);

namespace Kestello\Tests\Content;

use Kestello\Cms\Blocks\BlocksModule;
use Kestello\Cms\Content\ContentModule;
use Kestello\Cms\Media\MediaModule;
use Kestello\Framework\Database\Model;
use Kestello\Tests\Auth\AuthTestCase;

/** The review workflow: submit, request changes, approve, comments and assignment. */
final class ReviewsTest extends AuthTestCase
{
    /** @var array<string, int> */
    private array $ids = [];

    protected function setUp(): void
    {
        Model::resetRegistry();
        parent::setUp();
        foreach ([new ContentModule(), new MediaModule(), new BlocksModule()] as $module) {
            $module->register($this->cms->container);
            $module->boot($this->cms);
        }
        ContentModule::finalize($this->cms);
        $this->ids['ana'] = $this->createUser()['id'];
        foreach ([['cleo', 'contributor'], ['eddie', 'editor'], ['aria', 'author'], ['una', 'user'], ['olga', 'editor']] as [$name, $role]) {
            $this->ids[$name] = $this->createUser(['username' => $name, 'email' => "{$name}@example.test", 'role' => $role])['id'];
        }
        $this->db->table('users')->where('id', $this->ids['olga'])->update(['is_active' => false]);
    }

    protected function tearDown(): void
    {
        Model::resetRegistry();
        parent::tearDown();
    }

    public function testSubmitRequestChangesAndApprove(): void
    {
        $this->signIn('cleo');
        $post = $this->json($this->api('POST', '/content/post/entries', ['title' => '', 'data' => ['body' => '<p>Hi</p>']]))['data'];
        $r = $this->api('PATCH', "/content/post/entries/{$post['id']}", ['status' => 'pending']);
        $this->assertSame(422, $r->statusCode(), 'a submission needs a title');
        $this->assertStringContainsString('before submitting it for review', $r->content());
        $this->assertSame(403, $this->api('PATCH', "/content/post/entries/{$post['id']}", ['title' => 'Mine', 'status' => 'published'])->statusCode(), 'a contributor cannot publish');

        $r = $this->api('PATCH', "/content/post/entries/{$post['id']}", ['title' => 'Mine', 'status' => 'pending']);
        $this->assertSame(200, $r->statusCode(), $r->content());
        $this->assertSame('pending', $this->json($r)['data']['status']);
        $this->assertSame(['ana', 'eddie'], $this->notified('content-review'), 'everyone active who may publish it, except the submitter');
        $this->assertSame(1, $this->db->table('audit_log')->where('event', 'content.submitted')->count());

        $r = $this->api('PATCH', "/content/post/entries/{$post['id']}", ['title' => 'Mine, better']);
        $this->assertSame(200, $r->statusCode(), 'the author can keep editing while it waits');
        $this->assertSame('pending', $this->json($r)['data']['status']);
        $this->assertSame(2, $this->db->table('notifications')->count(), 'saving again is not a new submission');
        $this->assertSame(403, $this->api('POST', "/content/post/entries/{$post['id']}/request-changes", ['note' => 'x'])->statusCode(), 'only reviewers send it back');

        $this->signIn('aria');
        $this->assertSame(404, $this->api('POST', "/content/post/entries/{$post['id']}/request-changes", ['note' => 'x'])->statusCode(), "an author cannot see others' entries");

        $this->signIn('eddie');
        $this->assertSame(422, $this->api('POST', "/content/post/entries/{$post['id']}/request-changes", ['note' => '  '])->statusCode());
        $r = $this->api('POST', "/content/post/entries/{$post['id']}/request-changes", ['note' => 'Add a source for the second paragraph.']);
        $this->assertSame(200, $r->statusCode(), $r->content());
        $this->assertSame('draft', $this->json($r)['data']['status']);
        $this->assertSame(['cleo'], $this->notified('content-review', after: 2));
        $this->assertSame(422, $this->api('POST', "/content/post/entries/{$post['id']}/request-changes", ['note' => 'Again'])->statusCode(), 'only an entry in review');

        $this->signIn('cleo');
        $this->api('PATCH', "/content/post/entries/{$post['id']}", ['status' => 'pending']);
        $this->signIn('eddie');
        $r = $this->api('PATCH', "/content/post/entries/{$post['id']}", ['status' => 'published']);
        $this->assertSame(200, $r->statusCode(), $r->content());

        $timeline = $this->json($this->api('GET', "/content/post/entries/{$post['id']}/comments"))['data'];
        $this->assertSame([['submitted', 'cleo'], ['changes_requested', 'eddie'], ['submitted', 'cleo'], ['approved', 'eddie']], array_map(static fn(array $c) => [$c['kind'], $c['user']['username']], $timeline));
        $this->assertSame('Add a source for the second paragraph.', $timeline[1]['body']);
        $last = $this->db->table('notifications')->orderByDesc('created_at')->orderByDesc('id')->first();
        $this->assertSame($this->ids['cleo'], (int) $last['user_id'], 'the submitter hears it was approved');
        $this->assertStringContainsString('approved your post', (string) $last['data']);
    }

    public function testCommentsReachTheOthersInTheConversation(): void
    {
        $this->signIn('cleo');
        $post = $this->json($this->api('POST', '/content/post/entries', ['title' => 'Draft']))['data'];
        $this->signIn('eddie');
        $r = $this->api('POST', "/content/post/entries/{$post['id']}/comments", ['body' => "Nice start.\n\nCan you add a photo?"]);
        $this->assertSame(200, $r->statusCode(), $r->content());
        $editorComment = $this->json($r)['data'];
        $this->assertSame(['comment', 'eddie', "Nice start.\n\nCan you add a photo?"], [$editorComment['kind'], $editorComment['user']['username'], $editorComment['body']]);
        $this->assertSame(['cleo'], $this->notified('content-comment'), 'the author');
        $this->assertSame(422, $this->api('POST', "/content/post/entries/{$post['id']}/comments", ['body' => ''])->statusCode());

        $this->signIn('cleo');
        $mine = $this->json($this->api('POST', "/content/post/entries/{$post['id']}/comments", ['body' => 'Will do.']))['data'];
        $this->assertSame(['eddie'], $this->notified('content-comment', after: 1), 'and whoever wrote before');
        $this->assertSame(403, $this->api('DELETE', "/content/post/entries/{$post['id']}/comments/{$editorComment['id']}")->statusCode(), "not someone else's");
        $this->assertSame(204, $this->api('DELETE', "/content/post/entries/{$post['id']}/comments/{$mine['id']}")->statusCode());

        $this->signIn('aria');
        $this->assertSame(404, $this->api('POST', "/content/post/entries/{$post['id']}/comments", ['body' => 'Hi'])->statusCode(), 'only those who can see the entry');

        $this->signIn('cleo');
        $this->api('PATCH', "/content/post/entries/{$post['id']}", ['status' => 'pending']);
        $submitted = $this->db->table('entry_comments')->where('kind', 'submitted')->value('id');
        $this->signIn('eddie');
        $this->assertSame(404, $this->api('DELETE', "/content/post/entries/{$post['id']}/comments/{$submitted}")->statusCode(), 'review steps stay');
        $this->assertSame(1, $this->db->table('entry_comments')->where('kind', 'comment')->count());
    }

    public function testAssignment(): void
    {
        $this->signIn('cleo');
        $post = $this->json($this->api('POST', '/content/post/entries', ['title' => 'For Aria']))['data'];
        $this->signIn('una');
        $this->assertSame(403, $this->api('GET', '/content/post/assignees')->statusCode(), 'only people who may edit posts see the list');
        $this->signIn('cleo');
        $people = array_column($this->json($this->api('GET', '/content/post/assignees'))['data'], 'username');
        $this->assertSame(['ana', 'aria', 'cleo', 'eddie'], $people, 'whoever may edit posts and is active');

        $this->assertSame(422, $this->api('PUT', "/content/post/entries/{$post['id']}/assignee", ['user_id' => $this->ids['una']])->statusCode(), 'a plain user cannot edit posts');
        $r = $this->api('PUT', "/content/post/entries/{$post['id']}/assignee", ['user_id' => $this->ids['eddie']]);
        $this->assertSame(200, $r->statusCode(), $r->content());
        $this->assertSame('eddie', $this->json($r)['data']['assignee']['username']);
        $this->assertSame(['eddie'], $this->notified('content-review'));
        $this->assertSame(1, $this->db->table('audit_log')->where('event', 'content.assigned')->count());

        $this->api('PATCH', "/content/post/entries/{$post['id']}", ['status' => 'pending']);
        $this->assertSame(['eddie'], $this->notified('content-review', after: 1), 'a submission goes to the assignee only');

        $this->signIn('eddie');
        $mine = $this->json($this->api('GET', '/content/post/entries?assignee=me'))['data'];
        $this->assertSame([$post['id']], array_column($mine, 'id'));
        $this->assertSame(['pending'], array_column($this->json($this->api('GET', '/content/post/entries?status=pending'))['data'], 'status'));
        $this->assertNull($this->json($this->api('PUT', "/content/post/entries/{$post['id']}/assignee", ['user_id' => null]))['data']['assignee']);
    }

    public function testMentionsFindTheNamedPeople(): void
    {
        $this->assertSame(['ann', 'bo_b'], \Kestello\Cms\Content\Reviews::mentions('@ann, (@bo_b) mail x@y.example or @ab, and @Ann again'), 'once each, not emails or names too short');
        $this->assertCount(\Kestello\Cms\Content\Reviews::MAX_MENTIONS, \Kestello\Cms\Content\Reviews::mentions(implode(' ', array_map(static fn(int $i) => "@user{$i}", range(1, 15)))));

        $this->signIn('cleo');
        $post = $this->json($this->api('POST', '/content/post/entries', ['title' => 'Draft']))['data'];
        $this->signIn('eddie');
        $r = $this->api('POST', "/content/post/entries/{$post['id']}/comments", ['body' => 'Thanks @Cleo. @aria and @olga, have a look. @nobody']);
        $this->assertSame(200, $r->statusCode(), $r->content());
        $this->assertSame(['cleo'], $this->notified('content-comment'), "named, and once: not aria (who can't open others' posts), not olga (switched off)");
        $this->assertStringContainsString('mentioned you on the post', (string) $this->db->table('notifications')->value('data'));

        $this->signIn('cleo');
        $this->api('POST', "/content/post/entries/{$post['id']}/comments", ['body' => '@ana could you check the dates?']);
        $this->assertSame(['ana', 'eddie'], $this->notified('content-comment', after: 1), 'the named admin, and the earlier writer as usual');
        $toAna = (string) $this->db->table('notifications')->where('user_id', $this->ids['ana'])->value('data');
        $this->assertStringContainsString('mentioned you', $toAna);
    }

    public function testWhatIsWaitingOnYou(): void
    {
        $waiting = fn(): array => array_map(static fn(array $w) => [$w['title'], $w['reason']], $this->json($this->api('GET', '/content/waiting'))['data']);

        $this->signIn('cleo');
        $a = $this->json($this->api('POST', '/content/post/entries', ['title' => 'A', 'status' => 'pending']))['data'];
        $b = $this->json($this->api('POST', '/content/post/entries', ['title' => 'B']))['data'];
        $this->assertSame([], $waiting(), 'your own submission waits on others');

        $this->signIn('eddie');
        $this->assertSame([['A', 'review']], $waiting());
        $this->api('POST', "/content/post/entries/{$a['id']}/request-changes", ['note' => 'Shorter, please.']);
        $this->assertSame([], $waiting());

        $this->signIn('cleo');
        $this->assertSame([['A', 'changes_requested']], $waiting());
        $this->api('PUT', "/content/post/entries/{$b['id']}/assignee", ['user_id' => $this->ids['eddie']]);
        $this->api('PATCH', "/content/post/entries/{$a['id']}", ['status' => 'pending']);
        $this->assertSame([], $waiting(), 'sent in again');

        $this->signIn('eddie');
        $this->assertSame([['B', 'assigned'], ['A', 'review']], $waiting(), 'assigned first, then reviews');
        $first = $this->json($this->api('GET', '/content/waiting'))['data'][0];
        $this->assertSame(['post', 'Post', 'draft'], [$first['type'], $first['type_label'], $first['status']]);
        $this->assertNotNull($first['since']);

        $this->signIn('aria');
        $this->assertSame([], $waiting(), "an author can't open other people's posts");

        $this->signIn('ana');
        $this->assertSame(200, $this->api('DELETE', "/content/post/entries/{$b['id']}")->statusCode(), 'to the trash');
        $this->signIn('eddie');
        $this->assertSame([['A', 'review']], $waiting(), 'trashed entries wait on no one');
        $this->api('PATCH', "/content/post/entries/{$a['id']}", ['status' => 'published']);
        $this->assertSame([], $waiting());
    }

    private function signIn(string $login): void
    {
        $this->api('DELETE', '/auth/session');
        $this->assertSame(200, $this->api('POST', '/auth/session', ['login' => $login, 'password' => self::PASSWORD])->statusCode());
    }

    /** @return list<string> usernames notified with this type, skipping the first $after notifications */
    private function notified(string $type, int $after = 0): array
    {
        $rows = $this->db->table('notifications')->orderBy('created_at')->orderBy('id')->get();
        $names = array_flip($this->ids);
        $out = [];
        foreach (array_slice($rows, $after) as $row) {
            if ($row['type'] === $type) {
                $out[] = $names[(int) $row['user_id']];
            }
        }
        sort($out);

        return $out;
    }
}
