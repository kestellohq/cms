<?php

declare(strict_types=1);

namespace Kestello\Tests\Storage;

use Kestello\Cms\Storage\FileResponder;
use Kestello\Cms\Storage\SignedFileUrl;
use Kestello\Contracts\Storage\Disk;
use Kestello\Contracts\Storage\FileNotFound;
use Kestello\Contracts\Storage\StorageException;
use Kestello\Framework\Core\ExecutionContext;
use Kestello\Framework\Core\Request;
use Kestello\Framework\Http\Client\FakeTransport;
use Kestello\Framework\Http\Client\HttpClient;
use Kestello\Framework\Http\Client\HttpResponse;
use Kestello\Framework\Http\Client\PendingRequest;
use Kestello\Framework\Storage\LocalDisk;
use Kestello\Framework\Storage\ReadOnlyDisk;
use Kestello\Framework\Storage\S3\S3Disk;
use Kestello\Framework\Storage\S3\SigV4;
use Kestello\Framework\Storage\ScopedDisk;
use Kestello\Framework\Storage\StorageManager;
use Kestello\Framework\Storage\StoragePath;
use Kestello\Framework\Testing\TestCase;
use Kestello\Support\AppKey;

final class StorageTest extends TestCase
{
    protected function tearDown(): void
    {
        ExecutionContext::reset();
        AppKey::clear();
    }

    /** @return array<string, Disk> */
    private function disks(): array
    {
        $disks = [
            'local' => new LocalDisk($this->tempDir() . '/disk'),
            'scoped' => new ScopedDisk(new LocalDisk($this->tempDir() . '/scoped'), 'sites/42'),
        ];
        // Real S3 API when available: KESTELLO_TEST_S3='{"endpoint":"http://minio:9000","bucket":"…","key":"…","secret":"…"}'
        if (($json = (string) getenv('KESTELLO_TEST_S3')) !== '') {
            $config = (array) json_decode($json, true) + ['path_style' => true];
            $disks['s3'] = new ScopedDisk(new S3Disk($config), 'kestello-tests/' . bin2hex(random_bytes(4)));
        }

        return $disks;
    }

    public function testPathRules(): void
    {
        $this->assertSame('a/b.txt', StoragePath::file('a/b.txt'));
        $this->assertSame('a/b/', StoragePath::directory('a/b'));
        $this->assertSame('', StoragePath::directory(''));
        $this->assertSame('2026/a%20b%23.jpg', StoragePath::encode('2026/a b#.jpg'));
        foreach (['', '/etc/passwd', '../x', 'a/../../x', 'a/./b', 'a//b', "a\0b", 'a\\b', "a\nb", 'dir/', str_repeat('a', 1025)] as $bad) {
            $this->assertThrows(StorageException::class, fn() => StoragePath::file($bad), json_encode($bad));
        }
    }

    public function testDiskContract(): void
    {
        foreach ($this->disks() as $name => $disk) {
            $disk->put('docs/hello world.txt', "hello\n");
            $this->assertTrue($disk->exists('docs/hello world.txt'), $name);
            $this->assertFalse($disk->exists('docs/nope.txt'), $name);
            $this->assertSame("hello\n", $disk->get('docs/hello world.txt'), $name);
            $this->assertSame(6, $disk->size('docs/hello world.txt'), $name);
            $this->assertSame(md5("hello\n"), $disk->checksum('docs/hello world.txt'), $name);
            $this->assertContains('text/plain', (string) $disk->mimeType('docs/hello world.txt'), $name);
            $this->assertTrue(abs($disk->lastModified('docs/hello world.txt') - time()) < 120, $name);

            $in = fopen('php://temp', 'w+');
            fwrite($in, $bytes = random_bytes(70000));
            rewind($in);
            $disk->putStream('docs/deep/blob.bin', $in);
            $out = $disk->readStream('docs/deep/blob.bin');
            $this->assertSame($bytes, stream_get_contents($out), "{$name}: stream round trip");

            $disk->copy('docs/hello world.txt', 'docs/copy.txt');
            $disk->move('docs/copy.txt', 'other/moved.txt');
            $this->assertFalse($disk->exists('docs/copy.txt'), $name);
            $paths = array_map(static fn($f) => $f->path, iterator_to_array($disk->list(''), false));
            sort($paths);
            $this->assertSame(['docs/deep/blob.bin', 'docs/hello world.txt', 'other/moved.txt'], $paths, $name);
            $this->assertSame(['docs/hello world.txt'], array_map(static fn($f) => $f->path, iterator_to_array($disk->list('docs', false), false)), "{$name}: non-recursive");

            $this->assertThrows(FileNotFound::class, fn() => $disk->get('missing.txt'), $name);
            $this->assertThrows(FileNotFound::class, fn() => $disk->size('missing.txt'), $name);
            $this->assertThrows(StorageException::class, fn() => $disk->put('../escape.txt', 'x'), $name);

            $this->assertTrue($disk->delete('other/moved.txt'), $name);
            $this->assertFalse($disk->delete('other/moved.txt'), $name);
            $this->assertSame(2, $disk->deleteDirectory('docs'), $name);
            $this->assertSame([], iterator_to_array($disk->list(''), false), $name);
        }
    }

    public function testLocalDiskSafetyAndVisibility(): void
    {
        $root = $this->tempDir() . '/root';
        $outside = $this->tempDir() . '/outside';
        mkdir($outside);
        file_put_contents($outside . '/secret.txt', 'secret');
        $disk = new LocalDisk($root, '/files');
        symlink($outside, $root . '/link');
        symlink($outside . '/secret.txt', $root . '/file-link.txt');
        $this->assertThrows(StorageException::class, fn() => $disk->get('link/secret.txt'), 'symlinked dir out of the root');
        $this->assertThrows(StorageException::class, fn() => $disk->get('file-link.txt'), 'symlinked file out of the root');
        $this->assertThrows(StorageException::class, fn() => $disk->put('link/new.txt', 'x'));
        $this->assertFalse(is_file($outside . '/new.txt'));
        $this->assertThrows(StorageException::class, fn() => $disk->deleteDirectory(''), 'never the whole disk');

        $disk->put('a.txt', 'x');
        $this->assertSame('private', $disk->visibility('a.txt'));
        $disk->put('b.jpg', 'x', ['visibility' => 'public']);
        $this->assertSame('public', $disk->visibility('b.jpg'));
        $this->assertSame('/files/b.jpg', $disk->url('b.jpg'));
        $this->assertSame([], glob($root . '/.tmp-*'), 'no temp files left');
        $this->assertThrows(StorageException::class, fn() => (new LocalDisk($root))->url('a.txt'), 'no public URL');
        $this->assertThrows(StorageException::class, fn() => $disk->temporaryUrl('a.txt', 60), 'no signer');
    }

    public function testReadOnlyAndScoped(): void
    {
        $local = new LocalDisk($this->tempDir() . '/ro');
        $local->put('sites/42/a.txt', 'a');
        $ro = new ReadOnlyDisk($local);
        $this->assertSame('a', $ro->get('sites/42/a.txt'));
        foreach ([fn() => $ro->put('x', 'y'), fn() => $ro->delete('sites/42/a.txt'), fn() => $ro->move('sites/42/a.txt', 'b'), fn() => $ro->deleteDirectory('sites')] as $write) {
            $this->assertThrows(StorageException::class, $write);
        }
        $scoped = new ScopedDisk($local, 'sites/42/');
        $this->assertSame('a', $scoped->get('a.txt'));
        $this->assertThrows(StorageException::class, fn() => $scoped->get('../43/a.txt'), 'cannot climb out of the scope');
    }

    public function testManagerDisksPluginsAndDrivers(): void
    {
        $root = $this->tempDir();
        $m = new StorageManager($root);
        $m->configure($root, [
            'media' => ['url' => 'https://cdn.example.com'],                 // merged into the default
            'backups' => ['driver' => 'readonly', 'disk' => 'private'],     // replaces it
            'loop' => ['driver' => 'scoped', 'disk' => 'loop'],
            'acme-reports' => ['root' => 'elsewhere'],
        ]);
        $this->assertSame(['acme-reports', 'backups', 'loop', 'media', 'private', 'public'], $m->names());
        $this->assertSame('https://cdn.example.com/x.jpg', $m->disk('media')->url('x.jpg'));
        $this->assertSame($m->disk('media'), $m->disk('media'), 'resolved once');
        $this->assertInstanceOf(ReadOnlyDisk::class, $m->disk('backups'));
        $this->assertThrows(StorageException::class, fn() => $m->disk('loop'), 'self reference');
        $this->assertThrows(StorageException::class, fn() => $m->disk('nope'));
        $this->assertSame(['public' => '/files'], $m->servedLocally());

        ExecutionContext::run('plugin:acme', function () use ($m) {
            $m->define('acme-reports', ['driver' => 'local', 'root' => 'reports']);
            $m->extend('memory', fn(array $c) => new LocalDisk(sys_get_temp_dir() . '/kestello-mem-' . getmypid()));
        });
        $this->assertSame('elsewhere', $m->config('acme-reports')['root'], 'site config wins over plugin defaults');
        $this->assertSame('plugin:acme', $m->config('acme-reports')['_owner']);
        $this->assertThrows(StorageException::class, fn() => ExecutionContext::run('plugin:acme', fn() => $m->define('reports', [])), 'plugin disk names are prefixed');
        $this->assertThrows(StorageException::class, fn() => ExecutionContext::run('plugin:other', fn() => $m->extend('memory', fn() => null)), 'no hijacking drivers');
        $this->assertThrows(StorageException::class, fn() => ExecutionContext::run('plugin:other', fn() => $m->extend('s3', fn() => null)), 'no replacing core drivers');
    }

    public function testSignedLocalUrls(): void
    {
        AppKey::set(str_repeat('a', 64));
        $url = SignedFileUrl::make('https://site.test', 'private', 'exports/r 1.csv', 60, ['download_name' => 'report.csv'], now: 1000);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
        $this->assertSame(['disk' => 'private', 'path' => 'exports/r 1.csv', 'name' => 'report.csv'], SignedFileUrl::verify($q, now: 1030));
        $this->assertNull(SignedFileUrl::verify($q, now: 1061), 'expired');
        $this->assertNull(SignedFileUrl::verify(['p' => 'exports/other.csv'] + $q, now: 1030), 'other path');
        $this->assertNull(SignedFileUrl::verify(['d' => 'backups'] + $q, now: 1030), 'other disk');
        $this->assertNull(SignedFileUrl::verify(['n' => 'evil.html'] + $q, now: 1030), 'other name');
        $this->assertNull(SignedFileUrl::verify(['e' => '99999'] + $q, now: 1030), 'extended expiry');
        AppKey::set(str_repeat('b', 64), [str_repeat('a', 64)]);
        $this->assertNotNull(SignedFileUrl::verify($q, now: 1030), 'still valid after key rotation');
    }

    public function testFileResponderHeaders(): void
    {
        $disk = new LocalDisk($this->tempDir() . '/serve');
        $disk->put('page.html', '<script>alert(1)</script>', ['visibility' => 'public']);
        $disk->put('photo.jpg', 'jpeg', ['visibility' => 'public']);
        $req = static fn(array $h = []) => new Request('GET', '/', '/', $h, [], [], [], [], []);

        $html = FileResponder::send($req(), $disk, 'page.html');
        $this->assertSame('application/octet-stream', $html->headers()['Content-Type']);
        $this->assertContains('attachment', $html->headers()['Content-Disposition']);
        $this->assertSame('nosniff', $html->headers()['X-Content-Type-Options']);
        $this->assertContains('sandbox', $html->headers()['Content-Security-Policy']);

        $jpg = FileResponder::send($req(), $disk, 'photo.jpg');
        $this->assertSame('image/jpeg', $jpg->headers()['Content-Type']);
        $this->assertContains('inline', $jpg->headers()['Content-Disposition']);
        $this->assertSame('jpeg', stream_get_contents($jpg->bodyStream()));
        $this->assertSame(304, FileResponder::send($req(['If-None-Match' => $jpg->headers()['ETag']]), $disk, 'photo.jpg')->statusCode());

        $accel = FileResponder::send($req(), $disk, 'photo.jpg', 'my "photo".jpg', ['sendfile' => 'x-accel-redirect', 'sendfile_prefix' => '/internal']);
        $this->assertSame('/internal/serve/photo.jpg', $accel->headers()['X-Accel-Redirect']);
        $this->assertContains('attachment; filename="my photo.jpg"', $accel->headers()['Content-Disposition']);
    }

    public function testSigV4MatchesAwsExamples(): void
    {
        $s = new SigV4('AKIAIOSFODNN7EXAMPLE', 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY', 'us-east-1');
        $t = gmmktime(0, 0, 0, 5, 24, 2013);
        $h = $s->sign('GET', 'https://examplebucket.s3.amazonaws.com/test.txt', ['Range' => 'bytes=0-9'], SigV4::EMPTY_SHA256, $t);
        $this->assertContains('Signature=f0e8bdb87c964420e857bd35b5d6ed310bd44f0170aba48dd91039c6036bdb41', $h['Authorization']);
        $this->assertContains('SignedHeaders=host;range;x-amz-content-sha256;x-amz-date', $h['Authorization']);
        $put = $s->sign('PUT', 'https://examplebucket.s3.amazonaws.com/test%24file.text', [
            'Date' => 'Fri, 24 May 2013 00:00:00 GMT', 'x-amz-storage-class' => 'REDUCED_REDUNDANCY',
        ], hash('sha256', 'Welcome to Amazon S3.'), $t);
        $this->assertContains('Signature=98ad721746da40c64f1a55b78f14c238d841ea1380cd77a1b5971af0ece108bd', $put['Authorization']);
        $list = $s->sign('GET', 'https://examplebucket.s3.amazonaws.com/?max-keys=2&prefix=J', [], SigV4::EMPTY_SHA256, $t);
        $this->assertContains('Signature=34b48302e7b5fa45bde8084f4b7868a86f0a534bc59db6670ed5711ef69dc6f7', $list['Authorization']);
        $url = $s->presign('GET', 'https://examplebucket.s3.amazonaws.com/test.txt', 86400, $t);
        $this->assertTrue(str_ends_with($url, 'X-Amz-Signature=aeeed9bbccd4d02ee5c0109b86d86835f995330da4c265957d157751f604d404'));
    }

    public function testS3RequestShapes(): void
    {
        $fake = new FakeTransport();
        $fake->on('PUT https://media.s3.eu-central-1.amazonaws.com/2026/a%20b.jpg', ['status' => 200]);
        $fake->on('HEAD https://media.s3.eu-central-1.amazonaws.com/gone.txt', ['status' => 404]);
        $fake->on('GET https://media.s3.eu-central-1.amazonaws.com/gone.txt', ['status' => 404, 'body' => '<Error><Code>NoSuchKey</Code></Error>']);
        $fake->on('GET https://media.s3.eu-central-1.amazonaws.com/denied.txt', ['status' => 403, 'body' => '<Error><Code>AccessDenied</Code><Message>Nope</Message></Error>']);
        $fake->on('GET https://media.s3.eu-central-1.amazonaws.com/', fn(PendingRequest $r) => new HttpResponse(200, [], str_contains($r->url, 'continuation-token')
            ? '<ListBucketResult><IsTruncated>false</IsTruncated><Contents><Key>c.txt</Key><Size>3</Size><LastModified>2026-11-01T00:00:00Z</LastModified></Contents></ListBucketResult>'
            : '<ListBucketResult><IsTruncated>true</IsTruncated><NextContinuationToken>t1</NextContinuationToken><Contents><Key>a.txt</Key><Size>1</Size><LastModified>2026-11-01T00:00:00Z</LastModified></Contents></ListBucketResult>', $r->url));
        $http = new HttpClient($fake, static fn() => ['52.219.0.10']);
        $disk = new S3Disk(['bucket' => 'media', 'region' => 'eu-central-1', 'key' => 'AK', 'secret' => 'SK', 'acl' => true, 'url' => 'https://cdn.example.com'], $http);

        $disk->put('2026/a b.jpg', 'jpeg', ['visibility' => 'public', 'cache_control' => 'max-age=60']);
        $sent = $fake->sent[0];
        $this->assertContains('AWS4-HMAC-SHA256 Credential=AK/', $sent->headers['Authorization']);
        $this->assertContains('/eu-central-1/s3/aws4_request', $sent->headers['Authorization']);
        $this->assertSame('public-read', $sent->headers['x-amz-acl']);
        $this->assertSame('image/jpeg', $sent->headers['Content-Type']);
        $this->assertSame(hash('sha256', 'jpeg'), $sent->headers['x-amz-content-sha256']);

        $this->assertFalse($disk->exists('gone.txt'));
        $this->assertThrows(FileNotFound::class, fn() => $disk->get('gone.txt'));
        $e = $this->assertThrows(StorageException::class, fn() => $disk->get('denied.txt'));
        $this->assertContains('AccessDenied: Nope', $e->getMessage());
        $this->assertSame(['a.txt', 'c.txt'], array_map(static fn($f) => $f->path, iterator_to_array($disk->list(), false)), 'follows continuation tokens');
        $this->assertSame('https://cdn.example.com/2026/a%20b.jpg', $disk->url('2026/a b.jpg'));
        $this->assertContains('https://media.s3.eu-central-1.amazonaws.com/x.pdf?response-content-disposition=attachment', $disk->temporaryUrl('x.pdf', 60, ['download_name' => 'x.pdf']));
        $this->assertContains('X-Amz-SignedHeaders=content-type%3Bhost', $disk->temporaryUploadUrl('up.jpg', 60, ['content_type' => 'image/jpeg']));

        $minio = new S3Disk(['bucket' => 'b', 'key' => 'k', 'secret' => 's', 'endpoint' => 'http://minio:9000', 'path_style' => true]);
        $this->assertContains('http://minio:9000/b/f.txt?', $minio->temporaryUrl('f.txt', 60));
        $this->assertThrows(StorageException::class, fn() => $minio->url('f.txt'), 'private bucket without a public URL');
        $this->assertThrows(StorageException::class, fn() => new S3Disk(['bucket' => 'b', 'key' => 'k']), 'secret required');
    }
}
