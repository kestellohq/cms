<?php

declare(strict_types=1);

namespace Kestello\Tests\Content;

use Kestello\Cms\Content\ContentModule;
use Kestello\Cms\Media\Media;
use Kestello\Cms\Media\MediaModule;
use Kestello\Cms\Media\MediaStorage;
use Kestello\Cms\Media\Optimizer;
use Kestello\Cms\Media\PdfPoster;
use Kestello\Contracts\Storage\FileNotFound;
use Kestello\Contracts\Storage\StorageException;
use Kestello\Framework\Cache\ArrayStore;
use Kestello\Framework\Database\Model;
use Kestello\Framework\Settings\Settings;
use Kestello\Tests\Auth\AuthTestCase;

/** Media: mirror and cached disks, quotas, moving between disks, PDF first pages, optimizers. */
final class MediaStorageTest extends AuthTestCase
{
    private const array DISKS = [
        'media2' => ['driver' => 'local', 'root' => 'media2', 'url' => '/uploads2', 'visibility' => 'public'],
        'hidden' => ['driver' => 'local', 'root' => 'hidden'],
        'copy-a' => ['driver' => 'local', 'root' => 'copy-a'],
        'copy-b' => ['driver' => 'local', 'root' => 'copy-b'],
        'both' => ['driver' => 'mirror', 'disks' => ['copy-a', 'copy-b']],
        'remembered' => ['driver' => 'cached', 'disk' => 'copy-a', 'ttl' => 600],
    ];

    private string $root;
    private ArrayStore $cache;

    protected function setUp(): void
    {
        parent::setUp();
        Model::resetRegistry();
        $this->root = $this->tempDir();
        $this->cache = new ArrayStore();
        $cache = $this->cache;
        $this->cms->storage->configure($this->root, self::DISKS, cache: static fn() => $cache);
        $this->cms->container->instance(\Kestello\Contracts\Cache\Store::class, $cache);
        foreach ([new \Kestello\Cms\Storage\StorageModule(), new ContentModule(), new MediaModule()] as $module) {
            $module->register($this->cms->container);
            $module->boot($this->cms);
        }
        ContentModule::finalize($this->cms);
        $this->createUser();
        $this->signIn('ana');
    }

    protected function tearDown(): void
    {
        Model::resetRegistry();
        foreach (['media_disk', 'media_quotas', 'media_move', 'media_optimize', 'media_pdf_thumbnails'] as $key) {
            Settings::getInstance()->delete($key);
        }
        Optimizer::use(null);
        PdfPoster::forget();
        $this->cms->media->useEngine(null);
        parent::tearDown();
    }

    private function signIn(string $username): void
    {
        $this->api('DELETE', '/auth/session');
        $this->assertSame(200, $this->api('POST', '/auth/session', ['login' => $username, 'password' => self::PASSWORD])->statusCode());
    }

    /** @return array<string, mixed> */
    private function upload(string $filename, string $bytes): array
    {
        $up = $this->json($this->api('POST', '/media/uploads', ['filename' => $filename, 'size' => strlen($bytes)]))['data'];
        foreach (str_split($bytes, $up['chunk_size']) as $i => $chunk) {
            $this->assertSame(200, $this->api('PUT', "/media/uploads/{$up['id']}/chunks/{$i}", raw: $chunk)->statusCode());
        }
        $r = $this->api('POST', "/media/uploads/{$up['id']}/complete");
        $this->assertSame(200, $r->statusCode(), $r->content());

        return $this->json($r)['data'];
    }

    private static function png(int $w, int $h, int $seed = 0): string
    {
        $img = imagecreatetruecolor($w, $h);
        for ($x = 0; $x < $w; $x += 10) {
            imagefilledrectangle($img, $x, 0, $x + 9, $h - 1, imagecolorallocate($img, ($x * 7 + $seed) % 256, ($x * 3) % 256, 90));
        }
        ob_start();
        imagepng($img);

        return (string) ob_get_clean();
    }

    /** One A6 page with a black square. */
    private static function pdf(string $box = '0 0 298 420'): string
    {
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [' . $box . '] /Contents 4 0 R /Resources << >> >>',
        ];
        $stream = '0 0 0 rg 50 150 200 200 re f';
        $objects[] = '<< /Length ' . strlen($stream) . " >>\nstream\n{$stream}\nendstream";
        $out = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $i => $body) {
            $offsets[] = strlen($out);
            $out .= ($i + 1) . " 0 obj\n{$body}\nendobj\n";
        }
        $xref = strlen($out);
        $out .= "xref\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
        foreach ($offsets as $o) {
            $out .= sprintf("%010d 00000 n \n", $o);
        }

        return $out . "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";
    }

    private function onDisk(string $disk, string $path): bool
    {
        return is_file($this->root . '/' . $disk . '/' . $path);
    }

    // ── disks ───────────────────────────────────────────────────────────

    public function testMirrorAndCachedDisks(): void
    {
        $both = $this->cms->storage->disk('both');
        $both->put('a/one.txt', 'first');
        $this->assertSame('first', file_get_contents($this->root . '/copy-a/a/one.txt'));
        $this->assertSame('first', file_get_contents($this->root . '/copy-b/a/one.txt'), 'writes reach both disks');
        $stream = fopen('php://memory', 'w+');
        fwrite($stream, 'streamed');
        rewind($stream);
        $both->putStream('a/two.txt', $stream);
        $this->assertSame('streamed', file_get_contents($this->root . '/copy-b/a/two.txt'), 'a stream is copied to the second disk too');
        $both->delete('a/one.txt');
        $this->assertFileDoesNotExist($this->root . '/copy-a/a/one.txt');
        $this->assertFileDoesNotExist($this->root . '/copy-b/a/one.txt');
        try {
            $both->temporaryUploadUrl('x.txt', 60);
            $this->fail('direct uploads would skip the second disk');
        } catch (StorageException) {
        }

        $cached = $this->cms->storage->disk('remembered');
        $this->assertSame(8, $cached->size('a/two.txt'));
        file_put_contents($this->root . '/copy-a/a/two.txt', 'changed behind its back');
        $this->assertSame(8, $cached->size('a/two.txt'), 'the size is remembered');
        $cached->put('a/two.txt', 'new');
        $this->assertSame(3, $cached->size('a/two.txt'), 'writes through the disk forget what they change');
        $this->assertFalse($cached->exists('a/none.txt'));
        file_put_contents($this->root . '/copy-a/a/none.txt', 'x');
        $this->assertFalse($cached->exists('a/none.txt'), 'missing is remembered briefly too');
        try {
            $cached->size('a/gone.txt');
            $this->fail('missing files stay missing');
        } catch (FileNotFound) {
        }
        $this->assertSame('x', $cached->get('a/none.txt'), 'contents are never cached');

        foreach ([['driver' => 'mirror', 'disks' => ['copy-a']], ['driver' => 'mirror', 'disks' => ['copy-a', 'copy-a']], ['driver' => 'cached']] as $i => $bad) {
            $this->cms->storage->define("bad-{$i}", $bad);
            try {
                $this->cms->storage->disk("bad-{$i}");
                $this->fail('invalid wrapper config is refused');
            } catch (StorageException) {
            }
        }
    }

    // ── quotas ──────────────────────────────────────────────────────────

    public function testQuotasPerRole(): void
    {
        $r = $this->api('PATCH', '/system/storage/quotas', ['quotas' => ['author' => 1, 'editor' => null]]);
        $this->assertSame(200, $r->statusCode(), $r->content());
        $this->assertSame(['author' => 1], $this->json($r)['data']['quotas']);
        $this->assertSame(422, $this->api('PATCH', '/system/storage/quotas', ['quotas' => ['admin' => 5]])->statusCode(), 'administrators are never limited');
        $this->assertSame(422, $this->api('PATCH', '/system/storage/quotas', ['quotas' => ['author' => -3]])->statusCode());
        $this->assertSame(422, $this->api('PATCH', '/system/storage/quotas', ['quotas' => ['nobody' => 3]])->statusCode());

        $r = $this->api('POST', '/media/uploads', ['filename' => 'big.png', 'size' => 3_000_000]);
        $this->assertSame(200, $r->statusCode(), 'admins upload past any quota: ' . $r->content());

        $this->createUser(['username' => 'bo', 'email' => 'bo@example.test', 'role' => 'author']);
        $this->signIn('bo');
        $limits = $this->json($this->api('GET', '/media/limits'))['data'];
        $this->assertSame(['limit_bytes' => 1_048_576, 'used_bytes' => 0], $limits['quota']);
        $r = $this->api('POST', '/media/uploads', ['filename' => 'big.png', 'size' => 2_000_000]);
        $this->assertSame(422, $r->statusCode());
        $this->assertStringContainsString('1 MB of storage', $this->json($r)['errors']['size'][0] ?? '');
        $this->assertSame(200, $this->api('POST', '/media/uploads', ['filename' => 'a.png', 'size' => 600_000])->statusCode());
        $this->assertSame(422, $this->api('POST', '/media/uploads', ['filename' => 'b.png', 'size' => 600_000])->statusCode(), 'unfinished uploads count too');

        // checking and reserving are one step: while another request of bo's holds it, this one waits, then gives up
        $bo = (int) $this->db->table('users')->where('username', 'bo')->value('id');
        $held = $this->cms->container->make(\Kestello\Contracts\Cache\Store::class)->lock('media-quota:' . $bo, 30, 'another-request');
        $this->assertTrue($held->acquire());
        $wait = \Kestello\Cms\Media\MediaLibrary::$quotaLockWait;
        \Kestello\Cms\Media\MediaLibrary::$quotaLockWait = 1;
        try {
            $this->assertSame(409, $this->api('POST', '/media/uploads', ['filename' => 'c.png', 'size' => 10])->statusCode());
        } finally {
            \Kestello\Cms\Media\MediaLibrary::$quotaLockWait = $wait;
            $held->release();
        }
        $this->assertSame(200, $this->api('POST', '/media/uploads', ['filename' => 'c.png', 'size' => 10])->statusCode(), 'and goes ahead once it is free');
        $this->assertSame(403, $this->api('GET', '/system/storage')->statusCode(), 'only administrators see storage');
    }

    // ── moving ──────────────────────────────────────────────────────────

    public function testMovingTheLibraryToAnotherDisk(): void
    {
        $this->cms->media->useEngine('gd');
        $a = $this->upload('one.png', self::png(700, 400, 1));
        $b = $this->upload('two.png', self::png(500, 300, 2));
        $c = $this->upload('three.png', self::png(400, 400, 3));
        $mediaA = Media::query()->find($a['id']);
        $originalB = (string) $this->db->table('media')->where('id', $b['id'])->value('path');
        $this->assertSame(200, $this->api('POST', "/media/{$b['id']}/edit", ['crop' => ['x' => 0, 'y' => 0, 'width' => 0.5, 'height' => 1]])->statusCode());
        $editedB = (string) $this->db->table('media')->where('id', $b['id'])->value('path');
        $variant = (string) $this->db->table('media_variants')->where('media_id', $a['id'])->value('path');
        $this->assertTrue($variant !== '', 'the thumbnail was made on upload');

        $overview = $this->json($this->api('GET', '/system/storage'))['data'];
        $this->assertSame('media', $overview['current']);
        $byName = array_column($overview['disks'], null, 'name');
        $this->assertSame(3, $byName['media']['files']);
        $this->assertNotNull($byName['hidden']['problem'], 'a disk without public addresses can\'t hold the library');
        $this->assertNull($byName['media2']['problem']);
        $this->assertFalse(isset($byName['private']));

        $this->assertSame(422, $this->api('POST', '/system/storage/move', ['to' => 'hidden'])->statusCode());
        $this->assertSame(422, $this->api('POST', '/system/storage/move', ['to' => 'backups'])->statusCode());
        $this->assertSame(422, $this->api('POST', '/system/storage/move', ['to' => 'nowhere'])->statusCode());

        // a damaged original is left where it is
        file_put_contents($this->root . '/media/' . $this->db->table('media')->where('id', $c['id'])->value('path'), 'tampered');

        $r = $this->api('POST', '/system/storage/move', ['to' => 'media2']);
        $this->assertSame(200, $r->statusCode(), $r->content());
        $move = $this->json($r)['data']['move'];
        $this->assertSame(['running', 3, 0], [$move['status'], $move['total'], $move['done']]);
        $this->assertSame(409, $this->api('POST', '/system/storage/move', ['to' => 'media2'])->statusCode(), 'one move at a time');
        $this->assertSame('media2', $this->cms->container->make(MediaStorage::class)->current(), 'new uploads go to the new disk at once');

        for ($i = 0; $i < 5 && $move['status'] === 'running'; $i++) {
            $move = $this->json($this->api('POST', '/system/storage/move/step'))['data']['move'];
        }
        $this->assertSame('done', $move['status']);
        $this->assertSame(2, $move['done']);
        $this->assertSame([(string) $c['id']], array_map('strval', array_keys($move['failed'])));
        $this->assertStringContainsString('checksum', $move['failed'][(string) $c['id']]);

        $this->assertSame('media2', $this->db->table('media')->where('id', $a['id'])->value('disk'));
        $this->assertSame('media', $this->db->table('media')->where('id', $c['id'])->value('disk'), 'the damaged file stays on the old disk');
        $this->assertTrue($this->onDisk('media2', $mediaA->path));
        $this->assertTrue($this->onDisk('media2', $variant), 'sizes move with their file');
        $this->assertFalse($this->onDisk('media', $mediaA->path), 'the old copy is removed');
        $this->assertFalse($this->onDisk('media', $variant));
        $this->assertTrue($this->onDisk('media2', $editedB) && $this->onDisk('media2', $originalB), 'an edited image moves with its original');
        $this->assertSame(0600, fileperms($this->root . '/media2/' . $originalB) & 0777, 'which stays private');
        $this->assertFalse($this->onDisk('media', $originalB));
        $this->assertAnswerMatches(\Kestello\Cms\Api\Endpoints\Media\GetMedia::class, $this->api('GET', "/media/{$a['id']}"));
        $this->assertAnswerMatches(\Kestello\Cms\Api\Endpoints\Media\ListMedia::class, $this->api('GET', '/media'));
        $got = $this->json($this->api('GET', "/media/{$a['id']}"))['data'];
        $this->assertTrue(str_starts_with($got['url'], '/uploads2/'), 'addresses come from the row, nothing in content changes');
        $this->assertTrue(str_starts_with($got['sizes']['thumb']['src'] ?? '/uploads2/', '/uploads2/'));

        $d = $this->upload('four.png', self::png(300, 300, 4));
        $this->assertSame('media2', $this->db->table('media')->where('id', $d['id'])->value('disk'));

        // back again, keeping the copies: the CLI drives it without a worker
        $command = new \Kestello\Cms\Console\Media\MediaMoveCommand();
        $command->setOutput(new \Kestello\Framework\Console\Output(fopen('php://memory', 'w'), false));
        $this->assertSame(0, $command->handle([], ['to' => 'media', 'keep-source' => true]), 'the damaged file never left, so nothing fails');
        $this->assertSame(0, $this->db->table('media')->where('disk', 'media2')->count());
        $this->assertTrue($this->onDisk('media2', $mediaA->path), 'kept on the old disk when asked');
        $this->assertTrue($this->onDisk('media', $mediaA->path));

        $this->assertSame(200, $this->api('POST', '/system/storage/move', ['to' => 'media2'])->statusCode());
        $stopped = $this->json($this->api('DELETE', '/system/storage/move'))['data']['move'];
        $this->assertSame('stopped', $stopped['status']);
        $this->assertSame('stopped', $this->json($this->api('POST', '/system/storage/move/step'))['data']['move']['status'], 'a stopped move does nothing');
        $this->assertSame(1, $this->db->table('audit_log')->where('event', 'media.storage.move_stopped')->count());
    }

    // ── PDFs and optimizers ─────────────────────────────────────────────

    public function testAnImageEditedDuringAMoveKeepsTheEdit(): void
    {
        $this->cms->media->useEngine('gd');
        $up = $this->upload('one.png', self::png(600, 400, 4));
        $stale = Media::query()->find($up['id']);           // the move has read the row and starts copying
        $this->assertSame(200, $this->api('POST', "/media/{$up['id']}/edit", ['crop' => ['x' => 0, 'y' => 0, 'width' => 0.5, 'height' => 1]])->statusCode());
        $edited = $this->db->table('media')->where('id', $up['id'])->first();

        $moved = $this->cms->container->make(\Kestello\Cms\Media\MediaStorage::class)->moveOne($stale, 'media2');

        $this->assertFalse($moved, 'the row changed meanwhile: it is moved on the next pass');
        $now = $this->db->table('media')->where('id', $up['id'])->first();
        $this->assertSame(['media', $edited['path'], $edited['meta']], [$now['disk'], $now['path'], $now['meta']], 'the edit stands, on the disk that has its file');
        $this->assertTrue($this->onDisk('media', (string) $now['path']));
        $this->assertFalse($this->onDisk('media2', (string) $stale->path), 'copies of the old version are not left on the new disk');

        $storage = $this->cms->container->make(\Kestello\Cms\Media\MediaStorage::class);
        $slow = Media::query()->find($up['id']);             // two passes picked the same file
        $this->assertTrue($storage->moveOne(Media::query()->find($up['id']), 'media2'));
        $this->assertFalse($storage->moveOne($slow, 'media2'), 'the slower one finds it moved');
        $this->assertTrue($this->onDisk('media2', (string) $now['path']), 'and leaves the files it now lives in');
    }

    public function testAnUploadFinishesOnTheDiskItStartedOn(): void
    {
        $bytes = self::png(300, 200, 4);
        $up = $this->json($this->api('POST', '/media/uploads', ['filename' => 'late.png', 'size' => strlen($bytes)]))['data'];
        foreach (str_split($bytes, $up['chunk_size']) as $i => $chunk) {
            $this->api('PUT', "/media/uploads/{$up['id']}/chunks/{$i}", raw: $chunk);
        }
        // the library moves to another disk while this upload is still open
        $this->assertSame(200, $this->api('POST', '/system/storage/move', ['to' => 'media2'])->statusCode());

        $r = $this->api('POST', "/media/uploads/{$up['id']}/complete");
        $this->assertSame(200, $r->statusCode(), $r->content());
        $row = $this->db->table('media')->where('id', $this->json($r)['data']['id'])->first();
        $this->assertSame('media', $row['disk'], 'it belongs to the disk it was started on');
        $this->assertTrue($this->onDisk('media', (string) $row['path']), 'and its file is there, where the row says');
        $this->assertFalse($this->onDisk('media2', (string) $row['path']));
    }

    public function testPdfFirstPageBecomesItsPicture(): void
    {
        if (PdfPoster::binary() === null) {
            $this->assertNull(PdfPoster::binary(), 'Ghostscript is not installed here');

            return;
        }
        $this->cms->media->useEngine('gd');
        $pdf = $this->upload('leaflet.pdf', self::pdf());
        $media = Media::query()->find($pdf['id']);
        $poster = $media->meta['poster'] ?? null;
        $this->assertTrue(is_array($poster));
        $this->assertTrue($this->onDisk('media', $poster['path']));
        $this->assertTrue(abs(298 / 420 - $poster['width'] / $poster['height']) < 0.02, 'the page keeps its shape');
        $img = imagecreatefromstring((string) file_get_contents($this->root . '/media/' . $poster['path']));
        $this->assertTrue((imagecolorat($img, intdiv($poster['width'], 2), intdiv($poster['height'], 2)) & 0xFF) < 60, 'the black square is drawn');
        $this->assertTrue((imagecolorat($img, 3, 3) & 0xFF) > 200, 'on a white page');

        $got = $this->json($this->api('GET', "/media/{$pdf['id']}"))['data'];
        $this->assertTrue(isset($got['sizes']['thumb']), 'PDFs get thumbnails like images');
        $this->assertTrue($this->db->table('media_variants')->where('media_id', $pdf['id'])->count() > 0, 'the thumbnail was made');

        Settings::getInstance()->set('media_pdf_thumbnails', '0', 'media');
        $plain = Media::query()->find($this->upload('plain.pdf', self::pdf())['id']);
        $this->assertFalse(isset($plain->meta['poster']), 'turned off in Settings → Media');

        $this->assertSame(204, $this->api('DELETE', "/media/{$pdf['id']}")->statusCode());
        $this->assertFalse($this->onDisk('media', $poster['path']), 'deleting the PDF deletes its picture');
    }

    public function testAPosterNeverOutgrowsItsLimitWhateverThePageClaims(): void
    {
        $this->assertSame(PdfPoster::DPI, PdfPoster::dpiFor("File has 1 page.\nPage 1 MediaBox: [0 0 595 842]"));
        $this->assertSame(10, PdfPoster::dpiFor('Page 1 MediaBox: [0 0 14400 14400]'), '200 inches square: 2000 px at 10 dpi');
        $this->assertSame(PdfPoster::DPI, PdfPoster::dpiFor('Page 1 MediaBox: [-10.5 -10.5 289.5 409.5]'));
        foreach (['', 'File has 1 page.', 'Page 1 MediaBox: [0 0 0 0]', 'Page 1 MediaBox: [0 0 9999999 1]'] as $report) {
            $this->assertNull(PdfPoster::dpiFor($report), "no poster from: {$report}");
        }

        if (PdfPoster::binary() === null) {
            return;
        }
        $this->cms->media->useEngine('gd');
        $huge = Media::query()->find($this->upload('huge.pdf', self::pdf('0 0 14400 14400'))['id']);
        $poster = $huge->meta['poster'] ?? null;
        $this->assertTrue(is_array($poster), 'a giant page still gets a picture');
        $this->assertTrue(max($poster['width'], $poster['height']) <= PdfPoster::MAX_SIDE, 'drawn small, never at full size');
    }

    public function testOptimizersKeepOnlySmallerValidFiles(): void
    {
        $dir = $this->tempDir();
        $shrink = $dir . '/shrink';
        file_put_contents($shrink, "#!/bin/sh\nhead -c 200 \"\$1\" > \"\$1.t\" && mv \"\$1.t\" \"\$1\"\n");
        $grow = $dir . '/grow';
        file_put_contents($grow, "#!/bin/sh\nhead -c 99999 /dev/zero >> \"\$1\"\n");
        $fail = $dir . '/fail';
        file_put_contents($fail, "#!/bin/sh\nexit 3\n");
        $mangle = $dir . '/mangle';
        file_put_contents($mangle, "#!/bin/sh\nprintf 'GIF89a' > \"\$1\"\n");
        foreach ([$shrink, $grow, $fail, $mangle] as $f) {
            chmod($f, 0755);
        }
        $png = self::png(300, 200);

        Optimizer::use(['png' => $shrink]);
        $this->assertSame(substr($png, 0, 200), Optimizer::optimize($png, 'png'), 'a smaller file is kept');
        Settings::getInstance()->set('media_optimize', '0', 'media');
        $this->assertSame($png, Optimizer::optimize($png, 'png'), 'turned off in Settings → Media');
        Settings::getInstance()->delete('media_optimize');
        foreach ([$grow, $fail, $mangle] as $tool) {
            Optimizer::use(['png' => $tool]);
            $this->assertSame($png, Optimizer::optimize($png, 'png'), basename($tool) . ': the original is kept');
        }
        Optimizer::use([]);
        $this->assertSame($png, Optimizer::optimize($png, 'png'), 'no tool, no change');

        Optimizer::use(null);
        $real = Optimizer::tools();
        if (isset($real['jpeg'])) {
            $img = imagecreatefromstring($png);
            ob_start();
            imagejpeg($img, null, 80);
            $jpeg = (string) ob_get_clean();
            $out = Optimizer::optimize($jpeg, 'jpeg');
            $this->assertTrue(strlen($out) <= strlen($jpeg));
            $a = imagecreatefromstring($jpeg);
            $b = imagecreatefromstring($out);
            foreach ([[5, 5], [150, 100], [299, 199]] as [$x, $y]) {
                $this->assertSame(imagecolorat($a, $x, $y), imagecolorat($b, $x, $y), 'jpegoptim changes no pixel');
            }
        }
    }
}
