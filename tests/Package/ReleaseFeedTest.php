<?php

declare(strict_types=1);

namespace Kestello\Tests\Package;

use Kestello\Cms\Package\Update\ReleaseFeed;
use Kestello\Framework\Testing\TestCase;

/** Kestello versions read from GitHub's releases API. */
final class ReleaseFeedTest extends TestCase
{
    /** @return array<string, mixed> one release as GitHub describes it */
    public static function release(string $version, array $overrides = [], string $repo = 'kestellohq/cms'): array
    {
        $base = "https://github.com/{$repo}/releases/download/v{$version}/";

        return $overrides + [
            'tag_name' => "v{$version}",
            'name' => "Kestello {$version}",
            'body' => "### Fixed\n\n- Something.",
            'draft' => false,
            'prerelease' => str_contains($version, '-'),
            'published_at' => '2026-11-06T10:00:00Z',
            'html_url' => "https://github.com/{$repo}/releases/tag/v{$version}",
            'assets' => [
                // the install zip comes first, as on a real release: updates must never take it
                ['name' => "kestello-{$version}.zip", 'browser_download_url' => $base . "kestello-{$version}.zip"],
                ['name' => "kestello-{$version}.zip.sha256", 'browser_download_url' => $base . "kestello-{$version}.zip.sha256"],
                ['name' => "kestello-core-{$version}.zip", 'browser_download_url' => $base . "kestello-core-{$version}.zip"],
                ['name' => "kestello-core-{$version}.zip.sha256", 'browser_download_url' => $base . "kestello-core-{$version}.zip.sha256"],
                ['name' => 'kestello.stub.php', 'browser_download_url' => $base . 'kestello.stub.php'],
            ],
        ];
    }

    public function testOnlyPublishedVersionedReleasesWithBothFilesCountNewestFirst(): void
    {
        $evil = self::release('9.0.0');
        $evil['assets'][2]['browser_download_url'] = 'https://evil.example/kestello-core-9.0.0.zip';
        $releases = ReleaseFeed::parse([
            self::release('1.2.0'),
            self::release('1.10.0'),
            self::release('2.0.0', ['draft' => true]),
            self::release('3.0.0', ['tag_name' => 'release-3']),
            self::release('3.1.0', ['tag_name' => 'v3.1']),
            self::release('4.0.0', ['assets' => []]),
            $evil,
            self::release('1.11.0-beta.1'),
            'not a release',
        ], 'kestellohq/cms');

        $this->assertSame(['1.11.0-beta.1', '1.10.0', '1.2.0'], array_column($releases, 'version'));
        $this->assertTrue($releases[0]['prerelease']);
        $this->assertSame('https://github.com/kestellohq/cms/releases/download/v1.10.0/kestello-core-1.10.0.zip', $releases[1]['zip']);
    }

    /** @return array<string, mixed> a release of one plugin in a repository holding several, tagged <slug>-v<version> */
    private static function pluginRelease(string $slug, string $version, string $repo = 'kestellohq/plugins'): array
    {
        $tag = "{$slug}-v{$version}";
        $base = "https://github.com/{$repo}/releases/download/{$tag}/";

        return [
            'tag_name' => $tag, 'name' => "{$slug} {$version}", 'body' => '', 'draft' => false, 'prerelease' => false,
            'published_at' => '2026-11-10T10:00:00Z', 'html_url' => "https://github.com/{$repo}/releases/tag/{$tag}",
            'assets' => [
                ['name' => "{$slug}-{$version}.zip", 'browser_download_url' => $base . "{$slug}-{$version}.zip"],
                ['name' => "{$slug}-{$version}.zip.sha256", 'browser_download_url' => $base . "{$slug}-{$version}.zip.sha256"],
            ],
        ];
    }

    public function testARepositoryOfSeveralPackagesTagsEachOneByName(): void
    {
        $json = [
            self::pluginRelease('meilisearch', '1.0.0'),
            self::pluginRelease('meilisearch', '1.1.0'),
            self::pluginRelease('typesense', '2.0.0'),
            self::pluginRelease('meilisearch-extra', '3.0.0'),
        ];
        $releases = ReleaseFeed::parse($json, 'kestellohq/plugins', 'meilisearch');
        $this->assertSame(['1.1.0', '1.0.0'], array_column($releases, 'version'), 'only its own tags, never typesense or meilisearch-extra');
        $this->assertSame('https://github.com/kestellohq/plugins/releases/download/meilisearch-v1.1.0/meilisearch-1.1.0.zip', $releases[0]['zip']);
        $this->assertSame(['2.0.0'], array_column(ReleaseFeed::parse($json, 'kestellohq/plugins', 'typesense'), 'version'));

        // the tag and the zip must name the same package
        $mixed = self::pluginRelease('typesense', '9.0.0');
        $mixed['assets'] = self::pluginRelease('meilisearch', '9.0.0')['assets'];
        $this->assertSame([], ReleaseFeed::parse([$mixed], 'kestellohq/plugins', 'meilisearch'));
    }

    public function testTheVersionATagNames(): void
    {
        foreach ([['v1.2.0', '1.2.0'], ['meilisearch-v1.2.0', '1.2.0'], ['meilisearch-v1.2.0-rc.1', '1.2.0-rc.1'], ['typesense-v1.2.0', null], ['meilisearch-1.2.0', null], ['meilisearch-v1.2', null], ['vmeilisearch-v1.2.0', null], ['meilisearch-v2-v1.0.0', null]] as [$tag, $version]) {
            $this->assertSame($version, ReleaseFeed::tagVersion($tag, 'meilisearch'), $tag);
        }
    }

    public function testUpdatesUseTheCoreZipNeverTheInstallZip(): void
    {
        $release = ReleaseFeed::parse([self::release('1.2.0')], 'kestellohq/cms')[0];
        $this->assertStringEndsWith('/kestello-core-1.2.0.zip', $release['zip']);
        $this->assertStringEndsWith('/kestello-core-1.2.0.zip.sha256', $release['sha256']);

        $installOnly = self::release('1.3.0');
        $installOnly['assets'] = array_slice($installOnly['assets'], 0, 2);
        $this->assertSame([], ReleaseFeed::parse([$installOnly], 'kestellohq/cms'), 'a release with only the install zip is no update');
    }

    public function testFilesFromAnotherRepositoryAreLeftOut(): void
    {
        $this->assertSame([], ReleaseFeed::parse([self::release('1.0.0', [], 'someone/fork')], 'kestellohq/cms'));
    }

    public function testAnAnswerThatIsNotAListIsAnError(): void
    {
        $this->assertThrows(\UnexpectedValueException::class, static fn() => ReleaseFeed::parse(['message' => 'Not Found'], 'kestellohq/cms'));
    }

    public function testTheNewestReleaseAboveTheRunningVersion(): void
    {
        $releases = ReleaseFeed::parse([self::release('1.3.0-rc.1'), self::release('1.2.0'), self::release('1.1.0')], 'kestellohq/cms');

        $this->assertSame('1.2.0', ReleaseFeed::newest($releases, '1.1.0', false)['version'] ?? null);
        $this->assertSame('1.3.0-rc.1', ReleaseFeed::newest($releases, '1.1.0', true)['version'] ?? null);
        $this->assertNull(ReleaseFeed::newest($releases, '1.2.0', false), 'up to date');
        $this->assertNull(ReleaseFeed::newest($releases, '2.0.0-dev', false), 'a development build ahead of every release');
    }

    public function testChecksumFiles(): void
    {
        $hash = str_repeat('ab', 32);
        $this->assertSame($hash, ReleaseFeed::checksum(strtoupper($hash) . "  kestello-core-1.0.0.zip\n"));
        $this->assertNull(ReleaseFeed::checksum('<html>Not found</html>'));
    }
}
