<?php

declare(strict_types=1);

namespace Kestello\Tests\Package;

use Kestello\Cms\Package\Update\UpdateChecker;
use Kestello\Framework\Http\Client\FakeTransport;
use Kestello\Framework\Http\Client\HttpClient;
use Kestello\Framework\Testing\TestCase;
use RuntimeException;

/** Asking GitHub for new Kestello versions and downloading one. */
final class UpdateCheckerTest extends TestCase
{
    private const string FEED = 'GET https://api.github.com/repos/kestellohq/cms/releases';

    private FakeTransport $transport;
    /** @var array<string, mixed>|null */
    private ?array $saved = null;
    private int $now = 1_800_000_000;
    private string $tmp;

    protected function setUp(): void
    {
        $this->transport = new FakeTransport();
        $this->saved = null;
        $this->tmp = $this->tempDir() . '/updates';
    }

    /** @param array<string, mixed> $config */
    private function checker(string $running = '1.0.0', array $config = []): UpdateChecker
    {
        return new UpdateChecker(
            new HttpClient($this->transport, static fn(): array => ['140.82.112.3']),
            $config + ['repository' => 'kestellohq/cms', 'check_every' => 12, 'prereleases' => false],
            $running,
            $this->tmp,
            fn(): ?array => $this->saved,
            function (array $result): void {
                $this->saved = $result;
            },
            fn(): int => $this->now,
        );
    }

    /** @param list<string> $versions */
    private function feed(array $versions): void
    {
        $this->transport->on(self::FEED, ['json' => array_map(static fn(string $v): array => ReleaseFeedTest::release($v), $versions)]);
    }

    private function asset(string $version, string $file, string $body, int $status = 200): void
    {
        $this->transport->on("GET https://github.com/kestellohq/cms/releases/download/v{$version}/{$file}", ['status' => $status, 'body' => $body]);
    }

    public function testACheckFindsTheNewestVersionAndRemembersIt(): void
    {
        $this->feed(['1.1.0', '1.0.0']);
        $result = $this->checker()->check();

        $this->assertSame(['1.1.0', null, $this->now], [$result['latest']['version'] ?? null, $result['error'], $result['checked_at']]);
        $this->assertSame($result, $this->saved);
        $this->assertSame('1.1.0', $this->checker()->last()['latest']['version'] ?? null);
        $request = $this->transport->sent[0];
        $this->assertSame('application/vnd.github+json', $request->headers['Accept']);
        $this->assertStringStartsWith('Kestello/1.0.0', $request->headers['User-Agent']);
    }

    public function testAFailedCheckSaysWhyAndKeepsTheLastAnswer(): void
    {
        $this->feed(['1.1.0']);
        $this->checker()->check();
        $this->transport->on(self::FEED, ['status' => 403, 'json' => ['message' => 'API rate limit exceeded']]);
        $this->now += 3600;

        $result = $this->checker()->check();
        $this->assertSame('1.1.0', $result['latest']['version'] ?? null);
        $this->assertStringContainsString('limiting requests', (string) $result['error']);
        $this->assertSame($this->now, $result['checked_at'], 'the next check waits its turn instead of hammering GitHub');
    }

    public function testAfterAnUpdateTheOldAnswerNoLongerOffersAVersion(): void
    {
        $this->feed(['1.1.0']);
        $this->checker('1.0.0')->check();

        $this->assertNull($this->checker('1.1.0')->last()['latest']);
    }

    public function testChecksAreDueOnlyAfterTheInterval(): void
    {
        $this->feed(['1.0.0']);
        $this->assertTrue($this->checker()->due(), 'never checked');
        $this->checker()->check();
        $this->assertFalse($this->checker()->due());
        $this->now += 12 * 3600;
        $this->assertTrue($this->checker()->due());
        $this->assertFalse($this->checker(config: ['check_every' => 0])->due(), '0 turns scheduled checks off');
    }

    public function testOnlyAGitHubRepositoryNameIsAccepted(): void
    {
        foreach (['', '../../evil', 'kestellohq/cms/../x', 'https://evil.example/x', 'a b/c'] as $repo) {
            $this->assertThrows(RuntimeException::class, fn() => $this->checker(config: ['repository' => $repo])->repository(), $repo);
        }
        $this->assertSame('kestellohq/cms', $this->checker()->repository());
    }

    public function testADownloadMatchingItsChecksumIsKept(): void
    {
        $this->feed(['1.1.0']);
        $zip = 'PK pretend zip';
        $this->asset('1.1.0', 'kestello-core-1.1.0.zip.sha256', hash('sha256', $zip) . "  kestello-core-1.1.0.zip\n");
        $this->asset('1.1.0', 'kestello-core-1.1.0.zip', $zip);

        $path = $this->checker()->download('1.1.0');
        $this->assertSame($zip, file_get_contents($path));
        unlink($path);
    }

    public function testADownloadThatDoesNotMatchItsChecksumIsThrownAway(): void
    {
        $this->feed(['1.1.0']);
        $this->asset('1.1.0', 'kestello-core-1.1.0.zip.sha256', str_repeat('0', 64) . "  kestello-core-1.1.0.zip\n");
        $this->asset('1.1.0', 'kestello-core-1.1.0.zip', 'PK swapped');

        $e = $this->assertThrows(RuntimeException::class, fn() => $this->checker()->download('1.1.0'));
        $this->assertStringContainsString('does not match its published checksum', $e->getMessage());
        $this->assertTrue(is_dir($this->tmp), 'the download was written there first');
        $this->assertSame([], glob($this->tmp . '/*') ?: []);
    }

    public function testOnlyVersionsGitHubListsAreDownloaded(): void
    {
        $this->feed(['1.1.0']);
        $e = $this->assertThrows(RuntimeException::class, fn() => $this->checker()->download('6.6.6'));
        $this->assertStringContainsString('is not among the releases of kestellohq/cms', $e->getMessage());
    }
}
