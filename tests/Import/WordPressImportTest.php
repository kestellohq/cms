<?php

declare(strict_types=1);

namespace Kestello\Tests\Import;

use Kestello\Cms\Blocks\BlocksModule;
use Kestello\Cms\Content\ContentModule;
use Kestello\Cms\Content\Entry;
use Kestello\Cms\Content\Term;
use Kestello\Cms\Import\ImportException;
use Kestello\Cms\Import\ImportModule;
use Kestello\Cms\Import\WordPress\WordPressImports;
use Kestello\Cms\Media\Media;
use Kestello\Cms\Media\MediaModule;
use Kestello\Cms\Menus\Menus;
use Kestello\Cms\Redirects\Redirects;
use Kestello\Cms\Redirects\RedirectsModule;
use Kestello\Contracts\Auth\Principal;
use Kestello\Framework\Cache\ArrayStore;
use Kestello\Framework\Database\Model;
use Kestello\Framework\Http\Client\FakeTransport;
use Kestello\Framework\Http\Client\HttpClient;
use Kestello\Tests\Auth\AuthTestCase;

/** A whole WordPress site imported, then imported again. */
final class WordPressImportTest extends AuthTestCase
{
    private const string FIXTURE = __DIR__ . '/../fixtures/wordpress/site.xml';

    private FakeTransport $http;
    private Principal $ana;

    protected function setUp(): void
    {
        parent::setUp();
        Model::resetRegistry();
        $cache = new ArrayStore();
        $this->cms->storage->configure($this->tempDir(), [], cache: static fn() => $cache);
        $this->cms->container->instance(\Kestello\Contracts\Cache\Store::class, $cache);
        foreach ([new \Kestello\Cms\Storage\StorageModule(), new ContentModule(), new MediaModule(), new BlocksModule(), new RedirectsModule(), new ImportModule()] as $module) {
            $module->register($this->cms->container);
            $module->boot($this->cms);
        }
        ContentModule::finalize($this->cms);

        $this->http = new FakeTransport([
            'GET https://old.example.com/wp-content/uploads/2024/06/studio.jpg' => ['body' => self::jpeg(), 'headers' => ['Content-Type' => 'image/jpeg']],
            'GET https://old.example.com/wp-content/uploads/2024/06/poster-scaled.jpg' => ['body' => self::jpeg(), 'headers' => ['Content-Type' => 'image/jpeg']],
            'GET https://old.example.com/wp-content/uploads/2023/01/not-exported.png' => ['body' => self::png(), 'headers' => ['Content-Type' => 'image/png']],
            'GET https://old.example.com/wp-content/uploads/2024/06/song.mp3' => ['status' => 404],
        ]);
        $this->cms->container->instance(HttpClient::class, new HttpClient($this->http, static fn(): array => ['93.184.216.34']));

        $user = $this->createUser();
        $this->ana = $this->cms->auth->principalFor($user, 'cli');
    }

    protected function tearDown(): void
    {
        Model::resetRegistry();
        parent::tearDown();
    }

    private static function jpeg(): string
    {
        $image = imagecreatetruecolor(40, 30);
        ob_start();
        imagejpeg($image);

        return (string) ob_get_clean();
    }

    private static function png(): string
    {
        $image = imagecreatetruecolor(20, 20);
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    private function imports(): WordPressImports
    {
        return $this->cms->container->make(WordPressImports::class);
    }

    /**
     * Analyze and run $file to the end.
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function import(string $file = self::FIXTURE, array $options = []): array
    {
        $import = $this->imports()->analyze($file, $this->ana);
        $this->imports()->start($import['id'], $options, $this->ana);

        return $this->imports()->runToEnd($import['id']);
    }

    private function entry(int $wordpressId): Entry
    {
        $id = $this->db->table('import_sources')->where('kind', 'entry')->where('source_key', (string) $wordpressId)->value('target_id');
        $entry = $id === null ? null : Entry::query()->find((int) $id);
        $this->assertInstanceOf(Entry::class, $entry, "post {$wordpressId}");

        return $entry;
    }

    private function mediaFor(int $wordpressId): int
    {
        return (int) $this->db->table('import_sources')->where('kind', 'attachment')->where('source_key', (string) $wordpressId)->value('target_id');
    }

    public function testTheAnalysisSaysWhatWillHappenAndWritesNothing(): void
    {
        $import = $this->imports()->analyze(self::FIXTURE, $this->ana);

        $this->assertSame('ready', $import['status']);
        $this->assertSame(['title' => 'Old Studio', 'url' => 'https://old.example.com'], $import['site']);
        $this->assertSame(['ana' => 1, 'guest' => null], array_column(array_map(static fn(array $a) => ['l' => $a['login'], 'm' => $a['match']], $import['summary']['authors']), 'm', 'l'), 'matched by address');
        $this->assertSame(1, $import['summary']['comments']);
        $this->assertSame(['entry:post' => 7, 'entry:page' => 3, 'entry:album' => 1], array_intersect_key($import['summary']['counts'], array_flip(['entry:album', 'entry:page', 'entry:post'])));
        $this->assertSame(0, $this->db->table('entries')->count());
        $this->assertSame(0, $this->db->table('media')->count());

        $reasons = array_column($import['skipped'], 'reason');
        $this->assertContains('In the WordPress trash.', $reasons);
        $this->assertContains('This site has no content type "album".', $reasons);
        $this->assertContains('This site has no taxonomy "genre".', $reasons);
    }

    public function testASiteComesOverWithItsLinksDatesAndFiles(): void
    {
        $report = $this->import();

        $this->assertSame('done', $report['status']);
        $this->assertSame($report['progress']['total'], $report['progress']['done']);

        // posts: blocks, dates, terms, featured image, links that point here
        $hello = $this->entry(41);
        $this->assertSame(['published', 'Hello from the new studio', 'hello-studio'], [$hello->status, $hello->title, $hello->slug]);
        $this->assertSame('2024-06-03 10:30:00', (string) $this->db->table('entries')->where('id', $hello->getKey())->value('published_at'));
        $this->assertSame(['2024-06-03 10:30:00', '2024-06-10 06:00:00'], array_values($this->db->table('entries')->select('created_at', 'updated_at')->where('id', $hello->getKey())->first()));
        $this->assertSame('We moved to a bigger place.', $hello->excerpt);
        $this->assertSame($this->mediaFor(40), $hello->data['image']);
        $body = $hello->data['body'];
        $this->assertSame('core/text', $body[0]['type']);
        $this->assertStringContainsString('href="/about"', $body[0]['props']['text'], 'a link to a page imported later');
        $this->assertStringContainsString('href="/about/team"', $body[0]['props']['text'], '?page_id= links too');
        $this->assertContains('core/image', array_column($body, 'type'));
        $terms = $this->db->table('entry_term')->join('terms', 'terms.id', '=', 'entry_term.term_id')->where('entry_term.entry_id', $hello->getKey())->pluck('slug');
        sort($terms);
        $this->assertSame(['design', 'new-tag', 'news', 'releases'], $terms);
        $this->assertSame((int) Term::query()->where('slug', 'news')->value('id'), (int) Term::query()->where('slug', 'releases')->value('parent_id'), 'category parents');

        // authors: matched by address, else the person importing
        $this->assertSame((int) $this->ana->userId, (int) $this->entry(30)->author_id);

        // statuses
        $this->assertSame('draft', $this->entry(32)->status);
        $this->assertSame('scheduled', $this->entry(33)->status);
        $this->assertSame('private', $this->entry(34)->status);
        $this->assertSame('zdravo', $this->entry(31)->slug, 'Kestello addresses are ASCII; the old one redirects');

        // pages keep their tree; a reserved address gets a new one
        $this->assertSame((int) $this->entry(50)->getKey(), (int) $this->entry(60)->parent_id);
        $this->assertSame('/about/team', $this->entry(60)->path());
        $this->assertSame('admin-2', $this->entry(61)->slug, '/admin belongs to Kestello');
        $notices = array_column($report['notices'], 'message', 'label');
        $this->assertSame('Its address became "admin-2": "admin" is taken by this site.', $notices['Admin'] ?? null);
        $this->assertSame('Password-protected in WordPress; private here, since Kestello has no post passwords.', $notices['Members only'] ?? null);

        // files: attachments with alt text, an image only content used, a file the old site lost
        $studio = Media::query()->find($this->mediaFor(40));
        $this->assertSame(['Studio at night', 'The studio windows lit at night', 'Our studio, lit at night'], [$studio->title, $studio->alt, $studio->caption]);
        $this->assertSame(3, $this->db->table('media')->count());
        $failed = array_column(array_filter($report['problems'], static fn(array $p) => $p['result'] === 'failed'), 'message', 'label');
        $this->assertSame(['song.mp3' => 'The old site no longer has this file (404).'], $failed);
        $logo = (int) Media::query()->where('filename', 'not-exported.png')->value('id');
        $older = $this->entry(30)->data['body'];
        $this->assertSame(['core/text', 'core/image', 'core/image', 'core/heading', 'core/text', 'core/gallery', 'core/text', 'core/image', 'core/text'], array_column($older, 'type'));
        $this->assertSame(['image' => $logo, 'alt' => 'Old logo', 'align' => 'center'], array_diff_key($older[7]['props'], ['scale' => 1, 'ratio' => 1, 'corners' => 1, 'lightbox' => 1]), 'an image the export did not list, fetched from the old uploads folder');
        $this->assertStringContainsString('song.mp3', $older[6]['props']['text'], 'a file the old site lost stays a link');

        // old addresses redirect
        $redirects = $this->cms->container->make(Redirects::class);
        $this->assertSame($hello->path(), $redirects->resolve('/2024/06/hello-studio/')['target'] ?? null);
        $this->assertSame($this->entry(31)->path(), $redirects->resolve('/2024/04/%D0%B7%D0%B4%D1%80%D0%B0%D0%B2%D0%BE/')['target'] ?? null, 'non-Latin addresses as browsers send them');
        $this->assertSame('/category/releases', $redirects->resolve('/category/news/releases/')['target'] ?? null);
        $this->assertSame($studio->url(), $redirects->resolve('/wp-content/uploads/2024/06/studio.jpg')['target'] ?? null);
        $this->assertNull($redirects->resolve('/about'), 'the same address needs no redirect');

        // the menu
        $menus = $this->cms->container->make(Menus::class);
        $menu = $menus->all()[0];
        $this->assertSame('Main menu', $menu['name']);
        $this->assertSame([
            ['kind' => 'url', 'label' => 'Home', 'url' => '/'],
            ['kind' => 'entry', 'ref' => (int) $this->entry(50)->getKey(), 'children' => [['kind' => 'entry', 'label' => 'Our team', 'ref' => (int) $this->entry(60)->getKey()]]],
            ['kind' => 'term', 'ref' => (int) Term::query()->where('slug', 'news')->value('id')],
        ], $menu['items']);

        // what could not come over is named
        $this->assertSame(1, $report['notes']['block:jetpack/contact-form'] ?? null);
        $this->assertSame(1, $report['notes']['frame:evil.example'] ?? null);
        $this->assertContains(['key' => 'embed:twitter.com', 'count' => 1, 'text' => 'An embed from twitter.com became a link.'], $report['conversion']);
        $dropped = array_values(array_filter($report['conversion'], static fn(array $n) => $n['key'] === 'dropped'));
        $this->assertCount(1, $dropped, 'blocks left out are one line');
        $this->assertStringEndsWith(': latest posts, more.', $dropped[0]['text']);
        $this->assertSame([], array_filter(array_column($report['conversion'], 'key'), static fn(string $k) => str_starts_with($k, 'dropped:')));
        $this->assertSame(0, $this->db->table('import_items')->where('state', 'pending')->count());
        $this->assertFalse(is_dir($this->cms->storage->root() . '/imports/' . $report['id']), 'the export is removed when done');
    }

    public function testWhatIsAlreadyHereIsUsedNotCopied(): void
    {
        $terms = $this->cms->container->make(\Kestello\Cms\Content\Terms::class);
        $made = $terms->create($this->cms->content->getTaxonomy('tag'), ['name' => 'Design (ours)', 'slug' => 'design'], $this->ana);

        $report = $this->import();

        $this->assertSame(1, $this->db->table('terms')->where('slug', 'design')->count());
        $this->assertSame('Design (ours)', Term::query()->find((int) $made->getKey())?->name, 'left as it was');
        $this->assertSame(1, $report['counts']['term']['matched']);
        $this->assertSame(1, $this->db->table('entry_term')->where('term_id', $made->getKey())->count(), 'the post is tagged with it');
    }

    public function testRunningItAgainUpdatesInsteadOfDuplicating(): void
    {
        $this->import();
        $counts = [$this->db->table('entries')->count(), $this->db->table('terms')->count(), $this->db->table('media')->count(), $this->db->table('redirects')->count(), $this->db->table('menus')->count()];
        $downloads = count($this->http->sent);

        $again = $this->import();

        $this->assertSame($counts, [$this->db->table('entries')->count(), $this->db->table('terms')->count(), $this->db->table('media')->count(), $this->db->table('redirects')->count(), $this->db->table('menus')->count()]);
        $this->assertSame(0, $again['counts']['entry']['created']);
        $this->assertSame(9, $again['counts']['entry']['unchanged']);
        $this->assertSame($downloads + 1, count($this->http->sent), 'only the file that failed is fetched again');
    }

    public function testChangesInWordPressUpdateButEditsHereAreKept(): void
    {
        $this->import();
        $edited = $this->entry(30);
        $this->cms->container->make(\Kestello\Cms\Content\Entries::class)->update($edited, ['title' => 'Edited here'], $this->ana);

        $newer = $this->tempDir() . '/newer.xml';
        file_put_contents($newer, str_replace(['<![CDATA[An older post]]>', '<![CDATA[We are a small studio.]]>', '<p>We are a small studio.</p>'], ['<![CDATA[Older, renamed]]>', '', '<p>We are a bigger studio now.</p>'], (string) file_get_contents(self::FIXTURE)));
        $report = $this->import($newer);

        $this->assertSame('Edited here', $this->entry(30)->title, 'an edit made here wins');
        $this->assertSame(['kind' => 'entry', 'label' => 'Older, renamed', 'result' => 'kept'], array_intersect_key(array_values(array_filter($report['problems'], static fn(array $p) => $p['result'] === 'kept'))[0], array_flip(['kind', 'label', 'result'])));
        $this->assertStringContainsString('bigger studio', json_encode($this->entry(50)->data['body']) ?: '');
        $this->assertSame(1, $report['counts']['entry']['updated']);
    }

    public function testFilesCanComeFromAFolderWhenTheOldSiteIsGone(): void
    {
        $uploads = $this->tempDir();
        mkdir($uploads . '/2024/06', 0777, true);
        file_put_contents($uploads . '/2024/06/studio.jpg', self::jpeg());
        $this->http = new FakeTransport([]);
        $this->cms->container->instance(HttpClient::class, new HttpClient($this->http, static fn(): array => ['93.184.216.34']));

        $report = $this->import(options: ['uploads_dir' => $uploads, 'redirects' => false]);

        $this->assertSame('The studio windows lit at night', Media::query()->find($this->mediaFor(40))?->alt);
        $this->assertSame(0, $this->db->table('redirects')->count());
        $this->assertSame(0, count(array_filter($this->http->sent, static fn($r) => str_contains($r->url, 'studio.jpg'))), 'read from the folder');
        $this->assertSame(3, $report['counts']['attachment']['failed'] + $report['counts']['file']['failed'], 'the rest are named as failed');
    }

    public function testAQueueWorkerCarriesItToTheEndOneSliceAfterAnother(): void
    {
        $queue = new \Kestello\Framework\Queue\ArrayQueue();
        $this->cms->queue->setBackend(static fn() => $queue);
        $import = $this->imports()->analyze(self::FIXTURE, $this->ana);
        $this->imports()->start($import['id'], [], $this->ana);
        $queue->clear();
        $this->cms->queue->push(new \Kestello\Cms\Import\WordPress\RunImport($import['id'], seconds: 0.0));

        // another step has the slice: the job looks again later instead of giving up
        $lock = \Kestello\Cms\System\CoreTasks::cache($this->cms)->lock('import-' . $import['id'], 60);
        $this->assertTrue($lock->acquire());
        $this->cms->queue->process($queue->reserve(['default'], 600));
        $this->assertSame(['pending' => 0, 'delayed' => 1, 'reserved' => 0], array_intersect_key($queue->stats()['default'], ['pending' => 0, 'delayed' => 0, 'reserved' => 0]), 'one job waits, a little later');
        $lock->release();

        // slices too short for the whole import: each job queues the next one, and only one
        $queue->clear();
        $this->cms->queue->push(new \Kestello\Cms\Import\WordPress\RunImport($import['id'], seconds: 0.0));
        $jobs = 0;
        while (($job = $queue->reserve(['default'], 600)) !== null && $jobs < 100) {
            $this->cms->queue->process($job);
            $jobs++;
            $s = $queue->stats()['default'] ?? ['pending' => 0, 'delayed' => 0, 'reserved' => 0];
            $this->assertTrue($s['pending'] + $s['delayed'] + $s['reserved'] <= 1, 'the chain never grows');
        }

        $this->assertSame('done', $this->imports()->find($import['id'])['status']);
        $this->assertGreaterThanOrEqual(2, $jobs, 'a slice that ended early queued the next one');
    }

    /** The same export with some text replaced; @param array<string, string> $changes */
    private function changed(array $changes): string
    {
        $file = $this->tempDir() . '/changed.xml';
        $xml = (string) file_get_contents(self::FIXTURE);
        foreach ($changes as $from => $to) {
            $this->assertStringContainsString($from, $xml);
            $xml = str_replace($from, $to, $xml);
        }
        file_put_contents($file, $xml);

        return $file;
    }

    private function serveStudio(bool $up): void
    {
        $this->http = new FakeTransport([
            'GET https://old.example.com/wp-content/uploads/2024/06/studio.jpg' => $up ? ['body' => self::jpeg(), 'headers' => ['Content-Type' => 'image/jpeg']] : ['status' => 503],
            'GET https://old.example.com/wp-content/uploads/2024/06/poster-scaled.jpg' => ['body' => self::jpeg(), 'headers' => ['Content-Type' => 'image/jpeg']],
            'GET https://old.example.com/wp-content/uploads/2023/01/not-exported.png' => ['body' => self::png(), 'headers' => ['Content-Type' => 'image/png']],
            'GET https://old.example.com/wp-content/uploads/2024/06/song.mp3' => ['status' => 404],
        ]);
        $this->cms->container->instance(HttpClient::class, new HttpClient($this->http, static fn(): array => ['93.184.216.34']));
    }

    public function testTryAgainBringsWhatFailedIntoThePostsThatUseIt(): void
    {
        $this->serveStudio(up: false);
        $report = $this->import();
        $this->assertSame('The old site answered 503 instead of the file.', array_column($report['problems'], 'message', 'label')['Studio at night'] ?? null);
        $this->assertNull($this->entry(41)->data['image'] ?? null);
        $this->assertStringContainsString('old.example.com/wp-content/uploads/2024/06/studio', (string) json_encode($this->entry(41)->data['body'], JSON_UNESCAPED_SLASHES));

        $this->serveStudio(up: true);
        $this->imports()->retry($report['id'], $this->ana);
        $again = $this->imports()->runToEnd($report['id']);

        $this->assertSame(0, $again['counts']['attachment']['failed']);
        $studio = $this->mediaFor(40);
        $this->assertSame($studio, $this->entry(41)->data['image'], 'the featured image is filled in');
        $this->assertStringNotContainsString('old.example.com/wp-content/uploads/2024/06/studio', (string) json_encode($this->entry(41)->data['body'], JSON_UNESCAPED_SLASHES), 'posts point at the new file');
        $this->assertContains($studio, array_column(array_column($this->entry(41)->data['body'], 'props'), 'image'));
    }

    public function testEditsMadeHereSurviveTheNextImport(): void
    {
        $this->import();
        // a redirect pointed somewhere else by hand
        $this->db->table('redirects')->where('source', '/2024/06/hello-studio')->update(['target' => '/somewhere-else']);
        // a post the scheduler published since: that is no edit
        $scheduled = $this->entry(33);
        $scheduled->forceFill(['status' => 'published', 'version' => (int) $scheduled->getAttribute('version') + 1])->save();
        // a term this site had before the first import
        $this->db->table('terms')->where('slug', 'news')->update(['description' => 'Ours']);
        $this->db->table('import_sources')->where('kind', 'term')->where('source_key', 'category:news')->update(['target_type' => 'found']);

        $report = $this->import($this->changed(['Future news.' => 'Future news, rewritten.', 'What is new at the studio' => 'Changed in WordPress']));

        $this->assertSame('/somewhere-else', $this->db->table('redirects')->where('source', '/2024/06/hello-studio')->value('target'));
        $this->assertStringContainsString('rewritten', (string) json_encode($this->entry(33)->data['body']), 'updated, not reported as edited');
        $this->assertSame('Ours', $this->db->table('terms')->where('slug', 'news')->value('description'), 'a term that was here first is never changed');
        $this->assertSame(['/2024/06/hello-studio'], array_column(array_filter($report['problems'], static fn(array $p) => $p['kind'] === 'redirect'), 'label'));
    }

    public function testAnItemThatKeepsTakingTheServerDownIsGivenUp(): void
    {
        $import = $this->imports()->analyze(self::FIXTURE, $this->ana);
        $this->db->table('import_items')->where('import_id', $import['id'])->where('kind', 'entry')->where('source_key', '41')->update(['attempts' => 3]);
        $this->imports()->start($import['id'], [], $this->ana);

        $report = $this->imports()->runToEnd($import['id']);

        $this->assertSame('done', $report['status']);
        $this->assertSame('Importing this stopped the server each time it was tried (it may be too large for this server).', array_column($report['problems'], 'message', 'label')['Hello from the new studio'] ?? null);
    }

    public function testAnImportThatFailsAsAWholeStopsAndSaysWhy(): void
    {
        $import = $this->imports()->analyze(self::FIXTURE, $this->ana);
        $this->imports()->start($import['id'], [], $this->ana);
        $this->cms->container->bind(\Kestello\Cms\Media\MediaLibrary::class, static fn() => throw new \RuntimeException('The disk is full.'));

        $state = $this->imports()->step($import['id'], 1.0);

        $this->assertFalse($state['busy']);
        $this->assertSame('cancelled', $state['status']);
        $this->assertSame('The disk is full.', $state['error']);
    }

    public function testAStepWhileAnotherWorksWaitsItsTurn(): void
    {
        $import = $this->imports()->analyze(self::FIXTURE, $this->ana);
        $this->imports()->start($import['id'], [], $this->ana);
        $lock = \Kestello\Cms\System\CoreTasks::cache($this->cms)->lock('import-' . $import['id'], 60);
        $this->assertTrue($lock->acquire());

        $state = $this->imports()->step($import['id'], 1.0);
        $lock->release();

        $this->assertTrue($state['busy']);
        $this->assertSame(0, $this->db->table('import_items')->where('import_id', $import['id'])->where('attempts', '>', 0)->count(), 'nothing was worked on');
        $this->assertSame('running', $state['status']);
    }

    public function testTheReportNamesTheAddressAPageReallyGot(): void
    {
        $page = $this->cms->content->get('page');
        $this->assertNotNull($page);
        $this->cms->container->make(\Kestello\Cms\Content\Entries::class)->create($page, ['title' => 'Ours', 'slug' => 'admin-2', 'status' => 'published'], $this->ana);

        $report = $this->import();

        $this->assertSame('admin-3', $this->entry(61)->slug);
        $this->assertSame('Its address became "admin-3": "admin" is taken by this site.', array_column($report['notices'], 'message', 'label')['Admin'] ?? null);
    }

    public function testASiteInstalledInAFolderWithFilesOnACdn(): void
    {
        $this->http = new FakeTransport([
            'GET https://cdn.example.net/2024/06/studio.jpg' => ['body' => self::jpeg(), 'headers' => ['Content-Type' => 'image/jpeg']],
            'GET https://old.example.com/wp-content/uploads/2024/06/poster-scaled.jpg' => ['body' => self::jpeg(), 'headers' => ['Content-Type' => 'image/jpeg']],
            'GET https://old.example.com/wp-content/uploads/2023/01/not-exported.png' => ['body' => self::png(), 'headers' => ['Content-Type' => 'image/png']],
            'GET https://old.example.com/wp-content/uploads/2024/06/song.mp3' => ['body' => "ID3\x03\x00\x00\x00\x00\x00\x00" . str_repeat("\xff\xfb\x90\x00", 64), 'headers' => ['Content-Type' => 'audio/mpeg']],
        ]);
        $this->cms->container->instance(HttpClient::class, new HttpClient($this->http, static fn(): array => ['93.184.216.34']));
        // another site's import already redirects one of these addresses
        $this->db->table('redirects')->insert(['source' => '/2024/06/hello-studio', 'target' => '/theirs', 'status' => 301, 'match' => 'exact', 'origin' => 'import', 'hits' => 0, 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00']);

        $report = $this->import($this->changed([
            '<wp:base_site_url>https://old.example.com</wp:base_site_url>' => '<wp:base_site_url>https://old.example.com/wp</wp:base_site_url>',
            'https://old.example.com/wp-content/uploads/2024/06/studio.jpg]]></wp:attachment_url>' => 'https://cdn.example.net/2024/06/studio.jpg]]></wp:attachment_url>',
            'https://old.example.com/wp-content/uploads/2024/06/studio-1024x683.jpg' => 'https://cdn.example.net/2024/06/studio-1024x683.jpg',
        ]));

        $this->assertSame('https://old.example.com', $report['site']['url'], 'the posts live at the home address, not the install folder');
        $this->assertSame(0, $report['counts']['attachment']['failed']);
        $body = (string) json_encode($this->entry(41)->data['body'], JSON_UNESCAPED_SLASHES);
        $this->assertStringNotContainsString('cdn.example.net', $body, 'the CDN copy became the imported file');
        $this->assertContains($this->mediaFor(40), array_column(array_column($this->entry(41)->data['body'], 'props'), 'image'));

        // a file link is rewritten once: its new address is not read as an old one on a second pass
        $song = (string) json_encode(Entry::query()->where('data', 'like', '%song%')->get()->map(static fn(Entry $e) => $e->data)->all(), JSON_UNESCAPED_SLASHES);
        $this->assertStringNotContainsString('old.example.com/uploads', $song);
        $this->assertStringNotContainsString('old.example.com/wp-content/uploads/2024/06/song.mp3', $song);

        $this->assertSame('/theirs', $this->db->table('redirects')->where('source', '/2024/06/hello-studio')->value('target'), 'another site\'s redirect is theirs');
    }

    public function testOldPlainAddressesLeadToWhatCameOver(): void
    {
        $this->import();
        $links = new \Kestello\Cms\Import\WordPress\OldWordPressLinks();
        $visit = static fn(string $uri) => $links->handle(
            new \Kestello\Framework\Core\Request('GET', $uri, (string) parse_url($uri, PHP_URL_PATH), [], [], [], [], [], []),
            static fn() => \Kestello\Framework\Core\Response::html('home'),
        );

        $response = $visit('/?p=41&utm_source=news');
        $this->assertSame(301, $response->statusCode());
        $this->assertSame($this->entry(41)->path(), $response->headers()['Location'] ?? null);
        $this->assertSame($this->entry(50)->path(), \Kestello\Cms\Import\WordPress\OldWordPressLinks::target('page_id=50'));
        $this->assertNotNull(\Kestello\Cms\Import\WordPress\OldWordPressLinks::target('attachment_id=40'));
        $this->assertNull(\Kestello\Cms\Import\WordPress\OldWordPressLinks::target('p=32'), 'a draft is not public');
        $this->assertNull(\Kestello\Cms\Import\WordPress\OldWordPressLinks::target('p=4x1'));
        $this->assertSame(200, $visit('/?s=hello')->statusCode(), 'anything else is the home page as before');
        $this->assertSame(200, $visit('/blog?p=41')->statusCode(), 'only the home page');

        // the same id from a second imported WordPress site: no guessing
        $this->db->table('import_sources')->insert(['site_key' => str_repeat('b', 40), 'kind' => 'entry', 'source_key' => '41', 'target_type' => 'entry', 'target_id' => 1, 'checksum' => str_repeat('0', 40), 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00']);
        $this->assertSame(200, $visit('/?p=41')->statusCode());
    }

    public function testSomeoneElseRunningItAgainDoesNotTakeOverThePosts(): void
    {
        $this->import();
        $first = (int) $this->entry(30)->getAttribute('author_id');
        $this->assertSame($this->ana->userId, $first, 'a WordPress author with no account here: credited to the person importing');

        $zoe = $this->createUser(['username' => 'zoe', 'email' => 'zoe@example.test', 'role' => 'admin']);
        $byZoe = $this->cms->auth->principalFor($zoe, 'cli');
        $import = $this->imports()->analyze(self::FIXTURE, $byZoe);
        $this->imports()->start($import['id'], [], $byZoe);
        $again = $this->imports()->runToEnd($import['id']);

        $this->assertSame($first, (int) $this->entry(30)->getAttribute('author_id'), 'kept');
        $this->assertSame(0, $again['counts']['entry']['updated'], 'nothing changed in WordPress, so nothing here');

        $import = $this->imports()->analyze(self::FIXTURE, $byZoe);
        $this->imports()->start($import['id'], ['authors' => ['guest' => (int) $zoe['id']]], $byZoe);
        $this->imports()->runToEnd($import['id']);
        $this->assertSame((int) $zoe['id'], (int) $this->entry(30)->getAttribute('author_id'), 'unless the import says whose they are');
    }

    public function testOldPlainAddressesWorkWhenAPageIsTheHomePage(): void
    {
        $this->import();
        $get = fn(string $uri) => $this->cms->routes->dispatch(new \Kestello\Framework\Core\Request('GET', $uri, (string) parse_url($uri, PHP_URL_PATH), [], [], [], [], [], ['REMOTE_ADDR' => '203.0.113.7'], ''));
        $this->cms->routes->get('/', static fn() => new \Kestello\Framework\Core\Response('theme home'));
        \Kestello\Framework\Settings\Settings::getInstance()?->set('front_page', (string) $this->entry(50)->getKey(), 'reading');

        $this->assertSame('About', $get('/')->payload()->props['title'] ?? null, 'the chosen page answers at /');
        $moved = $get('/?p=41');
        $this->assertSame([301, $this->entry(41)->path()], [$moved->statusCode(), $moved->headers()['Location'] ?? null], 'the old address still goes to its post');
    }

    public function testOldPlainAddressesNeverShowWhatIsNotPublicYet(): void
    {
        $this->import();
        $post = $this->entry(41);
        $post->forceFill(['published_at' => '2099-01-01 00:00:00'])->save();
        $this->assertNull(\Kestello\Cms\Import\WordPress\OldWordPressLinks::target('p=41'), 'scheduled');

        $team = $this->entry(60);
        $this->assertSame($team->path(), \Kestello\Cms\Import\WordPress\OldWordPressLinks::target('page_id=60'));
        $about = $this->entry(50);
        $about->forceFill(['status' => 'draft'])->save();
        $this->assertNull(\Kestello\Cms\Import\WordPress\OldWordPressLinks::target('page_id=60'), 'its parent page is a draft');

        // ?p= finds a file too, and a later key is tried when the first finds nothing
        $this->assertNotNull(\Kestello\Cms\Import\WordPress\OldWordPressLinks::target('p=40'));
        $this->assertNotNull(\Kestello\Cms\Import\WordPress\OldWordPressLinks::target('p=999&attachment_id=40'));
    }

    public function testALinkToAPostNotHereYetIsLookedAtAgainWhereverItAppears(): void
    {
        $map = new \Kestello\Cms\Import\WordPress\SourceMap(str_repeat('c', 40));
        $import = $this->imports()->analyze(self::FIXTURE, $this->ana);
        // nothing imported yet: every post is pending
        $links = new \Kestello\Cms\Import\WordPress\ImportLinks($import['id'], new \Kestello\Cms\Import\WordPress\OldSite('https://old.example.com'), $map, $this->cms->content);

        $links->reset();
        $this->assertSame('https://old.example.com/about', $links->url('/about'));
        $this->assertTrue($links->needsLinks());
        $links->reset();
        $this->assertSame('https://old.example.com/about', $links->url('https://old.example.com/about'), 'the same address in another post');
        $this->assertTrue($links->needsLinks(), 'is flagged too, so it is relinked');
        $this->assertTrue($links->isOld('https://old.example.com/about'));
    }

    public function testOnlyAdministratorsImportAndOneAtATime(): void
    {
        $editor = $this->cms->auth->principalFor($this->createUser(['username' => 'ed', 'email' => 'ed@example.test', 'role' => 'user']), 'cli');
        $this->assertThrows(ImportException::class, fn() => $this->imports()->analyze(self::FIXTURE, $editor));

        $first = $this->imports()->analyze(self::FIXTURE, $this->ana);
        $second = $this->imports()->analyze(self::FIXTURE, $this->ana);
        $this->imports()->start($first['id'], [], $this->ana);
        $e = $this->assertThrows(ImportException::class, fn() => $this->imports()->start($second['id'], [], $this->ana));
        $this->assertStringContainsString('Another import is running', $e->getMessage());

        $this->assertSame('ready', $this->imports()->cancel($second['id'], $this->ana)['status'], 'only a running import stops');
        $this->assertSame(0, $this->db->table('import_items')->where('import_id', $second['id'])->where('result', 'failed')->count());
        $stopped = $this->imports()->cancel($first['id'], $this->ana);
        $this->assertSame('cancelled', $stopped['status']);
        $third = $this->imports()->analyze(self::FIXTURE, $this->ana);
        $this->imports()->start($third['id'], [], $this->ana);
        $e = $this->assertThrows(ImportException::class, fn() => $this->imports()->retry($first['id'], $this->ana));
        $this->assertStringContainsString('Another import is running', $e->getMessage());
        $this->imports()->cancel($third['id'], $this->ana);
        $retried = $this->imports()->retry($first['id'], $this->ana);
        $this->assertSame('running', $retried['status']);
        $this->assertSame('done', $this->imports()->runToEnd($first['id'])['status']);
    }

    private function upload(string $file): \Kestello\Framework\Core\Response
    {
        $copy = $this->tempDir() . '/export.xml';
        copy($file, $copy);
        $this->nextRequest();

        return $this->cms->routes->dispatch(new \Kestello\Framework\Core\Request(
            'POST',
            '/api/v1/imports',
            '/api/v1/imports',
            [],
            [],
            [],
            [],
            ['file' => ['name' => 'export.xml', 'type' => 'text/xml', 'tmp_name' => $copy, 'error' => UPLOAD_ERR_OK, 'size' => filesize($copy)]],
            ['REMOTE_ADDR' => '203.0.113.7'],
            '',
        ));
    }

    public function testTheAdminScreenDrivesItThroughTheApi(): void
    {
        $this->assertSame(200, $this->api('POST', '/auth/session', ['login' => 'ana', 'password' => self::PASSWORD])->statusCode());

        $uploaded = $this->upload(self::FIXTURE);
        $this->assertSame(200, $uploaded->statusCode(), $uploaded->content());
        $id = $this->json($uploaded)['data']['id'];
        $this->assertSame('ready', $this->json($uploaded)['data']['status']);
        $this->assertSame([['id' => (int) $this->ana->userId, 'username' => 'ana']], $this->json($uploaded)['data']['people'], 'who posts can be credited to');

        $bad = $this->upload(__FILE__);
        $this->assertSame(409, $bad->statusCode());
        $this->assertStringContainsString('not a WordPress export', $bad->content());

        $started = $this->api('POST', "/imports/{$id}/start", ['media' => false, 'redirects' => true]);
        $this->assertSame(200, $started->statusCode(), $started->content());
        $this->assertSame('running', $this->json($started)['data']['status']);
        $this->assertFalse($this->json($started)['data']['worker'], 'no queue worker: the screen drives it');

        for ($i = 0; $i < 50 && ($state = $this->json($this->api('POST', "/imports/{$id}/step"))['data'])['status'] === 'running'; $i++);
        $this->assertSame('done', $state['status']);
        $this->assertSame(0, $this->db->table('media')->count(), 'files were left out');

        $this->assertSame([$id], array_column($this->json($this->api('GET', '/imports'))['data']['imports'], 'id'));
        $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d(\.\d+)?(Z|\+00:00)$/', (string) $state['finished_at'], 'times in UTC, with their zone');
        $this->assertSame(200, $this->api('DELETE', "/imports/{$id}")->statusCode());
        $this->assertSame(404, $this->api('GET', "/imports/{$id}")->statusCode());
        $this->assertSame(9, $this->db->table('entries')->count(), 'forgetting an import keeps what it imported');

        $this->createUser(['username' => 'ed', 'email' => 'ed@example.test', 'role' => 'user']);
        $this->api('DELETE', '/auth/session');
        $this->assertSame(200, $this->api('POST', '/auth/session', ['login' => 'ed', 'password' => self::PASSWORD])->statusCode());
        $this->assertSame(403, $this->api('GET', '/imports')->statusCode());
    }
}
