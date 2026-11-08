<?php

declare(strict_types=1);

namespace Kestello\Tests\Health;

use Kestello\Cms\Health\PrivateFolders;
use Kestello\Framework\Http\Client\FakeTransport;
use Kestello\Framework\Http\Client\HttpClient;
use Kestello\Framework\Http\Client\HttpException;
use Kestello\Framework\Http\Client\HttpResponse;
use Kestello\Framework\Http\Client\PendingRequest;
use Kestello\Framework\Testing\TestCase;

/** Browsers never read config/, storage/, core/ or content/, and Site Health notices when they can. */
final class PrivateFoldersTest extends TestCase
{
    private function client(FakeTransport $transport): HttpClient
    {
        return new HttpClient($transport, static fn(): array => ['127.0.0.1']);
    }

    private function site(): string
    {
        $root = $this->tempDir();
        foreach (PrivateFolders::FOLDERS as $folder) {
            mkdir("{$root}/{$folder}");
        }
        mkdir("{$root}/storage/framework");

        return $root;
    }

    public function testEveryPrivateFolderGetsADenyFileOnce(): void
    {
        $root = $this->site();
        file_put_contents("{$root}/content/.htaccess", "# the owner's own rules\n");

        $this->assertSame(['config', 'storage', 'core'], PrivateFolders::protect($root));
        $this->assertSame(PrivateFolders::DENY, file_get_contents("{$root}/storage/.htaccess"));
        $this->assertSame("# the owner's own rules\n", file_get_contents("{$root}/content/.htaccess"), 'an existing file is left alone');
        $this->assertSame([], PrivateFolders::protect($root));
    }

    public function testTheFilesShippedInTheRepositoryDenyEverything(): void
    {
        $repo = dirname(__DIR__, 2);
        foreach (PrivateFolders::FOLDERS as $folder) {
            $this->assertStringContainsString('Require all denied', (string) @file_get_contents("{$repo}/{$folder}/.htaccess"), "{$folder}/.htaccess");
        }
    }

    public function testTheCheckReadsAFreshSecretOverTheWeb(): void
    {
        $root = $this->site();
        $url = 'https://shop.test/storage/' . PrivateFolders::CANARY;

        // served as a file: whatever is in storage/ comes back
        $open = (new FakeTransport())->on('GET ' . $url, static fn(PendingRequest $r): HttpResponse => new HttpResponse(200, [], (string) file_get_contents($root . '/storage/' . PrivateFolders::CANARY)));
        $this->assertTrue(PrivateFolders::exposed($this->client($open), 'https://shop.test/', $root, $root . '/storage'));
        $this->assertFileDoesNotExist($root . '/storage/' . PrivateFolders::CANARY, 'the secret is removed after');

        // refused, or answered by Kestello's own 404 page
        $closed = new FakeTransport(['GET ' . $url => ['status' => 404, 'body' => '<h1>Not found</h1>']]);
        $this->assertFalse(PrivateFolders::exposed($this->client($closed), 'https://shop.test', $root, $root . '/storage'));

        // can't tell: no address, storage elsewhere, or the site can't reach itself
        $this->assertNull(PrivateFolders::exposed($this->client($closed), '', $root, $root . '/storage'));
        $this->assertNull(PrivateFolders::exposed($this->client($closed), 'https://shop.test', $root, '/var/private/storage'));
        $down = (new FakeTransport())->on('GET ' . $url, static fn(): HttpResponse => throw new HttpException('connection refused'));
        $this->assertNull(PrivateFolders::exposed($this->client($down), 'https://shop.test', $root, $root . '/storage'));
        $this->assertFileDoesNotExist($root . '/storage/' . PrivateFolders::CANARY);
    }
}
