<?php

declare(strict_types=1);

namespace Kestello\Tests\Content;

use Kestello\Cms\Content\ContentModule;
use Kestello\Cms\Content\Field;
use Kestello\Cms\Media\MediaLibrary;
use Kestello\Cms\Media\MediaModule;
use Kestello\Framework\Core\ExecutionContext;
use Kestello\Framework\Database\Model;
use Kestello\Framework\Settings\Settings;
use Kestello\Tests\Auth\AuthTestCase;

/** The media library: uploads and what they must pass, permissions, usage. */
final class MediaTest extends AuthTestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        Model::resetRegistry();
        $this->root = $this->tempDir();
        $this->cms->storage->configure($this->root, []);
        foreach ([new ContentModule(), new MediaModule()] as $module) {
            $module->register($this->cms->container);
            $module->boot($this->cms);
        }
        ExecutionContext::run('plugin:events', function (): void {
            $this->cms->content->type('events')->label('Event', 'Events')->path('/events')
                ->fields([Field::image('poster'), Field::repeater('docs', [Field::media('file', ['document'])])]);
        });
        ContentModule::finalize($this->cms);
        $this->createUser();
        foreach (['editor', 'author', 'contributor', 'user'] as $role) {
            $this->createUser(['username' => $role, 'email' => "{$role}@example.test", 'role' => $role]);
        }
    }

    protected function tearDown(): void
    {
        Model::resetRegistry();
        foreach (['media_max_upload_mb', 'media_max_megapixels', 'media_allow_document'] as $key) {
            Settings::getInstance()->delete($key);
        }
        parent::tearDown();
    }

    private function signIn(string $login = 'ana'): void
    {
        $this->assertSame(200, $this->api('POST', '/auth/session', ['login' => $login, 'password' => self::PASSWORD])->statusCode());
    }

    /** @return array<string, mixed> */
    private function ok(string $method, string $path, array $body = [], ?string $raw = null): array
    {
        $r = $this->api($method, $path, $body, raw: $raw);
        $this->assertTrue($r->statusCode() < 300, "{$method} {$path}: {$r->statusCode()} {$r->content()}");

        return $this->json($r)['data'] ?? [];
    }

    /** Upload through the chunked API; returns the media resource or the failing response's status and errors. */
    private function upload(string $filename, string $bytes, array $details = []): array
    {
        $up = $this->ok('POST', '/media/uploads', ['filename' => $filename, 'size' => strlen($bytes)]);
        foreach (str_split($bytes, $up['chunk_size']) as $i => $chunk) {
            $this->ok('PUT', "/media/uploads/{$up['id']}/chunks/{$i}", raw: $chunk);
        }
        $r = $this->api('POST', "/media/uploads/{$up['id']}/complete", $details);

        return $r->statusCode() === 200 ? $this->json($r)['data'] : ['status' => $r->statusCode(), 'errors' => $this->json($r)['errors'] ?? $this->json($r)];
    }

    private static function png(int $w = 4, int $h = 3): string
    {
        $img = imagecreatetruecolor($w, $h);
        ob_start();
        imagepng($img);

        return (string) ob_get_clean();
    }

    public function testChunkedUploadStoresUnderARandomKey(): void
    {
        $this->signIn('author');
        $limits = $this->ok('GET', '/media/limits');
        $this->assertSame(['image', 'document', 'audio', 'video'], $limits['kinds']);
        $this->assertStringContainsString('.pdf', $limits['accept']);

        $media = $this->upload('../../My Holiday "Photo".PNG', self::png(), ['alt' => 'A tiny black square']);
        $this->assertSame(['image', 'image/png', 4, 3, 'My Holiday Photo', 'My Holiday Photo.PNG', 'A tiny black square'], [$media['kind'], $media['mime'], $media['width'], $media['height'], $media['title'], $media['filename'], $media['alt']]);
        $this->assertMatchesRegularExpression('#^/uploads/\d{4}/\d{2}/[a-f0-9]{20}/my-holiday-photo\.png$#', $media['url']);
        $path = $this->db->table('media')->where('id', $media['id'])->value('path');
        $this->assertSame(self::png(), file_get_contents($this->root . '/media/' . $path));
        $this->assertSame(hash('sha256', self::png()), $this->db->table('media')->where('id', $media['id'])->value('checksum'));
        $this->assertSame(0, $this->db->table('uploads')->count(), 'finished uploads are forgotten');
        $this->assertSame([], glob($this->root . '/uploads-tmp/*') ?: []);
        $this->assertContains('media.uploaded', $this->auditEvents());

        // Several chunks, in order; a retried chunk is fine, skipping ahead is not.
        $text = str_repeat("line of text\n", (int) ceil((MediaLibrary::CHUNK_SIZE * 2 + 10) / 13));
        $up = $this->ok('POST', '/media/uploads', ['filename' => 'notes.txt', 'size' => strlen($text)]);
        $this->assertSame([3, 'chunked'], [$up['chunks'], $up['mode']]);
        $parts = str_split($text, MediaLibrary::CHUNK_SIZE);
        $this->assertSame(409, $this->api('PUT', "/media/uploads/{$up['id']}/chunks/1", raw: $parts[1])->statusCode());
        $this->assertSame(422, $this->api('PUT', "/media/uploads/{$up['id']}/chunks/0", raw: 'short')->statusCode());
        $this->ok('PUT', "/media/uploads/{$up['id']}/chunks/0", raw: $parts[0]);
        $this->ok('PUT', "/media/uploads/{$up['id']}/chunks/0", raw: $parts[0]);
        $this->assertSame(409, $this->api('POST', "/media/uploads/{$up['id']}/complete")->statusCode(), 'not complete yet');
        $this->assertSame(1, $this->ok('GET', "/media/uploads/{$up['id']}")['received'], 'resumable');
        $this->ok('PUT', "/media/uploads/{$up['id']}/chunks/1", raw: $parts[1]);
        $this->ok('PUT', "/media/uploads/{$up['id']}/chunks/2", raw: $parts[2]);
        // Two "complete" requests at once (a client retrying a slow one): both read the
        // finished row, the first claims it, the second must not make a twin sharing the file.
        $stale = (array) $this->db->table('uploads')->where('id', $up['id'])->first();
        $this->db->table('uploads')->where('id', $up['id'])->update(['received' => -1]);    // the first request's claim
        $before = $this->db->table('media')->count();
        $principal = new \Kestello\Contracts\Auth\Principal((int) $stale['user_id'], ['id' => (int) $stale['user_id']], 'session', ['media.upload']);
        $this->assertThrows(\Kestello\Cms\Media\MediaException::class, fn() => $this->cms->container->make(\Kestello\Cms\Media\MediaLibrary::class)->complete($stale, $principal));
        $this->assertSame($before, $this->db->table('media')->count(), 'no second item');
        $this->db->table('uploads')->where('id', $up['id'])->update(['received' => $stale['received']]);
        $doc = $this->ok('POST', "/media/uploads/{$up['id']}/complete");
        $this->assertSame(['document', strlen($text)], [$doc['kind'], $doc['size']]);

        // Someone else's upload is invisible.
        $other = $this->ok('POST', '/media/uploads', ['filename' => 'a.png', 'size' => 10]);
        $this->signIn('editor');
        $this->assertSame(404, $this->api('PUT', "/media/uploads/{$other['id']}/chunks/0", raw: '0123456789')->statusCode());
        $this->assertSame(404, $this->api('DELETE', "/media/uploads/{$other['id']}")->statusCode());
    }

    public function testFilesMustBeWhatTheySayAndWithinLimits(): void
    {
        $this->signIn('author');
        $refused = fn(string $name, int $size = 10) => array_keys($this->json($this->api('POST', '/media/uploads', ['filename' => $name, 'size' => $size]))['errors'] ?? []);
        $this->assertSame(['filename'], $refused('shell.php'));
        $this->assertSame(['filename'], $refused('shell.php.jpg'), 'hidden blocked extension');
        $this->assertSame(['filename'], $refused('drawing.svg'));
        $this->assertSame(['filename'], $refused('page.html'));
        $this->assertSame(['filename'], $refused('noextension'));
        $this->assertSame(['filename'], $refused('backup.zip'), 'archives are off by default');
        $this->assertSame(['size'], $refused('empty.png', 0));
        Settings::getInstance()->set('media_max_upload_mb', '1', 'media');
        $this->assertSame(['size'], $refused('big.png', 1_048_577));
        Settings::getInstance()->set('media_allow_document', '0', 'media');
        $this->assertSame(['filename'], $refused('report.pdf'));

        $php = "<?php system(\$_GET['c']); ?>";
        $this->assertSame(422, $this->upload('photo.jpg', $php)['status'], 'content must match the extension');
        $this->assertSame(422, $this->upload('photo.jpg', self::png())['status'], 'a PNG is not a JPEG');
        $html = '<html><script>alert(1)</script></html>';
        $this->assertSame(422, $this->upload('notes.png', $html)['status']);
        $this->assertSame(0, $this->db->table('media')->count());
        $this->assertSame(0, $this->db->table('uploads')->count(), 'refused uploads are dropped');

        // Decompression bomb: a PNG header announcing 20000 × 20000 pixels.
        $ihdr = pack('NN', 20000, 20000) . "\x08\x02\x00\x00\x00";
        $bomb = "\x89PNG\r\n\x1a\n" . pack('N', 13) . 'IHDR' . $ihdr . pack('N', crc32('IHDR' . $ihdr)) . str_repeat("\0", 64);
        $result = $this->upload('bomb.png', $bomb);
        $this->assertSame(422, $result['status']);
        $this->assertStringContainsString('megapixels', $result['errors']['file'][0]);

        // A scanner plugin can refuse.
        $this->cms->filters->add('media.scan', fn($verdict, array $file) => str_contains((string) file_get_contents($file['path']), 'EICAR') ? 'Malware found.' : $verdict);
        Settings::getInstance()->set('media_allow_document', '1', 'media');
        $this->assertSame(['file' => ['Malware found.']], $this->upload('virus.txt', 'X5O EICAR test')['errors']);
        $this->assertSame('document', $this->upload('fine.txt', 'hello')['kind']);
    }

    public function testPermissionsAndUsage(): void
    {
        $this->signIn('user');
        $this->assertSame(403, $this->api('POST', '/media/uploads', ['filename' => 'a.png', 'size' => 10])->statusCode());
        $this->assertSame(403, $this->api('GET', '/media')->statusCode());

        $this->signIn('author');
        $mine = $this->upload('mine.png', self::png());
        $this->signIn('contributor');
        $theirs = $this->upload('theirs.png', self::png(2, 2));
        $this->assertSame(['theirs', 'mine'], array_column($this->json($this->api('GET', '/media'))['data'], 'title'), 'everyone who uploads sees the library');
        $this->assertSame(['theirs'], array_column($this->json($this->api('GET', '/media?mine=1'))['data'], 'title'));
        $this->assertSame(['mine'], array_column($this->json($this->api('GET', '/media?search=min'))['data'], 'title'));
        $this->assertFalse($this->json($this->api('GET', "/media/{$mine['id']}"))['data']['can']['edit']);
        $this->assertSame(403, $this->api('PATCH', "/media/{$mine['id']}", ['alt' => 'x'])->statusCode());
        $this->assertSame(403, $this->api('DELETE', "/media/{$mine['id']}")->statusCode());
        $this->assertSame('Two by two', $this->ok('PATCH', "/media/{$theirs['id']}", ['title' => 'Two by two'])['title']);

        $this->signIn('editor');
        $doc = $this->upload('agenda.pdf', "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF");
        $this->assertSame('document', $doc['kind']);
        $bad = $this->api('POST', '/content/events/entries', ['title' => 'Wrong kind', 'data' => ['poster' => $doc['id']]]);
        $this->assertSame(['data.poster'], array_keys($this->json($bad)['errors']), 'image fields take images only');
        $event = $this->ok('POST', '/content/events/entries', ['title' => 'Bake-off', 'data' => ['poster' => $mine['id'], 'docs' => [['file' => $doc['id']], ['file' => $doc['id']]]]]);
        $usage = $this->ok('GET', "/media/{$mine['id']}")['usage'];
        $this->assertSame([['id' => $event['id'], 'type' => 'events', 'title' => 'Bake-off', 'status' => 'draft', 'field' => 'poster']], $usage);
        $this->assertSame(['docs'], array_column($this->ok('GET', "/media/{$doc['id']}")['usage'], 'field'), 'nested fields count, once');

        // the editor's draft uses the author's file: the author may see that it is used, not what uses it
        $this->signIn('author');
        $seen = $this->ok('GET', "/media/{$mine['id']}");
        $this->assertSame([[], 1], [$seen['usage'], $seen['usage_hidden']], 'someone else\'s draft is counted, never named');
        $this->signIn('editor');

        $public = \Kestello\Cms\Content\ContentController::present(\Kestello\Cms\Content\Entry::query()->find($event['id']), $this->cms->content->get('events'), full: true);
        $poster = $public['data']->poster;
        $this->assertSame(['id' => $mine['id'], 'url' => $mine['url'], 'alt' => '', 'title' => 'mine', 'caption' => null, 'width' => 4, 'height' => 3, 'kind' => 'image', 'mime' => 'image/png'], array_intersect_key($poster, array_flip(['id', 'url', 'alt', 'title', 'caption', 'width', 'height', 'kind', 'mime'])), 'public pages get the file, not its id');
        $this->assertSame([0.5, 0.5], $poster['focus']);
        $this->assertMatchesRegularExpression('/^#[0-9a-f]{6}$/', (string) $poster['color']);

        $this->assertSame(409, $this->api('DELETE', "/media/{$mine['id']}")->statusCode(), 'in use');
        $this->ok('PATCH', "/content/events/entries/{$event['id']}", ['data' => ['poster' => $theirs['id']]]);
        $this->assertSame([], $this->ok('GET', "/media/{$mine['id']}")['usage'], 'usage follows edits');
        $path = $this->db->table('media')->where('id', $mine['id'])->value('path');
        $this->assertSame(204, $this->api('DELETE', "/media/{$mine['id']}")->statusCode(), 'editors delete anyone\'s');
        $this->assertFileDoesNotExist($this->root . '/media/' . $path);
        $this->assertSame(204, $this->api('DELETE', "/media/{$theirs['id']}?force=1")->statusCode());
        $this->assertSame(0, $this->db->table('media_usage')->where('entry_id', $event['id'])->where('field', 'poster')->count());
    }

    public function testUnfinishedUploadsArePurged(): void
    {
        $this->signIn('author');
        $up = $this->ok('POST', '/media/uploads', ['filename' => 'slow.txt', 'size' => 10]);
        $this->assertSame(10, strlen(str_repeat('x', 10)));
        $this->ok('PUT', "/media/uploads/{$up['id']}/chunks/0", raw: str_repeat('x', 10));
        $this->assertFileExists($this->root . "/uploads-tmp/{$up['id']}.part");
        $this->db->table('uploads')->where('id', $up['id'])->update(['expires_at' => gmdate('Y-m-d H:i:s', time() - 1)]);
        $this->assertSame(404, $this->api('POST', "/media/uploads/{$up['id']}/complete")->statusCode(), 'expired');
        $this->assertSame(1, $this->cms->container->make(MediaLibrary::class)->purgeExpired());
        $this->assertFileDoesNotExist($this->root . "/uploads-tmp/{$up['id']}.part");
    }

    public function testDirectUploadToABucket(): void
    {
        $json = (string) getenv('KESTELLO_TEST_S3');
        if ($json === '') {
            $this->assertTrue(true); // set KESTELLO_TEST_S3 to test direct uploads against MinIO or S3

            return;
        }
        $config = (array) json_decode($json, true);
        $this->cms->storage->configure($this->root, ['media' => $config + ['driver' => 's3', 'path_style' => true, 'direct_uploads' => true, 'visibility' => 'private']]);
        $this->signIn('author');

        $png = self::png(5, 5);
        $up = $this->ok('POST', '/media/uploads', ['filename' => 'direct.png', 'size' => strlen($png)]);
        $this->assertSame('direct', $up['mode']);
        $this->assertSame(409, $this->api('POST', "/media/uploads/{$up['id']}/complete")->statusCode(), 'nothing arrived yet');

        $post = function (array $direct, string $bytes, string $type): int {
            $ch = curl_init($direct['url']);
            curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_POSTFIELDS => $direct['fields'] + ['file' => new \CURLStringFile($bytes, 'f', $type)]]);
            curl_exec($ch);

            return (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        };
        $this->assertSame(400, $post($up['direct'], $png . 'extra', 'image/png'), 'the policy pins the size (EntityTooLarge)');
        $this->assertSame(204, $post($up['direct'], $png, 'image/png'));
        $media = $this->ok('POST', "/media/uploads/{$up['id']}/complete");
        $this->assertSame(['image', 5, 5], [$media['kind'], $media['width'], $media['height']]);

        // A disguised file is caught after the fact and removed from the bucket.
        $fake = str_pad('<?php echo 1; ?>', strlen($png));
        $up2 = $this->ok('POST', '/media/uploads', ['filename' => 'evil.png', 'size' => strlen($fake)]);
        $this->assertSame(204, $post($up2['direct'], $fake, 'image/png'));
        $key = $this->db->table('uploads')->where('id', $up2['id'])->value('path');
        $this->assertSame(422, $this->api('POST', "/media/uploads/{$up2['id']}/complete")->statusCode());
        $this->assertFalse($this->cms->storage->disk('media')->exists($key));

        $this->assertSame(204, $this->api('DELETE', "/media/{$media['id']}")->statusCode());
    }

    public function testAFileAlreadyOnTheServerGoesThroughTheSameChecks(): void
    {
        $library = $this->cms->container->make(MediaLibrary::class);
        $ana = $this->cms->auth->principalFor((array) $this->db->table('users')->where('username', 'ana')->first(), 'cli');
        $local = $this->tempDir() . '/download.tmp';
        file_put_contents($local, self::png(8, 6));

        $media = $library->addFile($ana, $local, 'Studio Night.PNG', ['alt' => 'The studio', 'caption' => 'At night']);

        $this->assertSame(['Studio Night.PNG', 'The studio', 'At night', 8, 6], [$media->filename, $media->alt, $media->caption, (int) $media->width, (int) $media->height]);
        $this->assertFileDoesNotExist($local, 'the library owns it now');
        $this->assertSame(0, $this->db->table('uploads')->count());

        file_put_contents($local, '<?php echo 1;');
        $this->assertThrows(\Kestello\Cms\Media\MediaException::class, fn() => $library->addFile($ana, $local, 'logo.png'));
        $user = $this->cms->auth->principalFor((array) $this->db->table('users')->where('username', 'user')->first(), 'cli');
        file_put_contents($local, self::png());
        $this->assertThrows(\Kestello\Cms\Media\MediaException::class, fn() => $library->addFile($user, $local, 'logo.png'));
        $this->assertSame(1, $this->db->table('media')->count());
    }
}
