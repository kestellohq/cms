<?php

declare(strict_types=1);

namespace Kestello\Tests\Package;

use Kestello\Cms\Backup\BackupModule;
use Kestello\Cms\Events\RegistryRefreshed;
use Kestello\Cms\Extension\ArrayStateStore;
use Kestello\Cms\Extension\ExtensionManager;
use Kestello\Cms\Extension\SuspensionList;
use Kestello\Cms\Package\PackageBuilder;
use Kestello\Cms\Package\PackageSignature;
use Kestello\Cms\Package\PackagesModule;
use Kestello\Cms\Package\Registry\RegistryClient;
use Kestello\Cms\Package\Update\KeyPins;
use Kestello\Framework\Core\Response;
use Kestello\Framework\Http\Client\HttpClient;
use Kestello\Tests\Auth\AuthTestCase;

/**
 * Plugins → Add new: browsing the registry, one plugin in full, and installing it through the
 * same review and install steps as an uploaded zip.
 */
final class RegistryBrowseTest extends AuthTestCase
{
    private string $root;
    private RegistryFixture $registry;
    /** @var array{id: string, public_key: string, secret_key: string} */
    private array $acme;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = $this->cms->paths->root();
        $this->cms->storage->configure($this->root . '/storage/app', []);
        $ext = new ExtensionManager($this->cms, new ArrayStateStore(), new SuspensionList($this->root . '/suspended.json'), '0.1.0');
        $ext->boot();
        $this->cms->setExtensions($ext);
        foreach ([new BackupModule(), new PackagesModule()] as $module) {
            $module->register($this->cms->container);
            $module->boot($this->cms);
        }
        // signed packages only, and the site trusts no key of its own: only the registry vouches
        $this->cms->config->set('app.packages.admin_unsigned', false);
        $this->cms->config->set('app.packages.trusted_keys', []);

        $this->registry = new RegistryFixture();
        $this->acme = $this->registry->organisation('acme', 'Acme');
        $this->list('events', '1.0.0', ['name' => 'Events', 'summary' => 'Event listings with dates and places.', 'categories' => ['content']]);
        $this->list('forms-extra', '2.1.0', ['name' => 'Forms Extra', 'summary' => 'More fields for forms.', 'categories' => ['forms']]);
        $this->list('search-plus', '0.9.0', ['name' => 'Search Plus', 'summary' => 'Better site search for events and pages.', 'categories' => ['content', 'integrations']]);
        $this->list('shop', '1.0.0', ['name' => 'Shop', 'summary' => 'Sell things.', 'categories' => ['commerce'], 'paid' => true]);
        $this->registry->version('theme', 'aurora', 'acme', $this->zip('aurora', '1.0.0'), '1.0.0');
        $this->registry->publish();
        $this->cms->container->instance(RegistryClient::class, $this->registry->client($this->root . '/storage/registry'));

        $this->createUser();
        $this->assertSame(200, $this->api('POST', '/auth/session', ['login' => 'ana', 'password' => self::PASSWORD])->statusCode());
    }

    /** @param array<string, mixed> $meta */
    private function list(string $slug, string $version, array $meta, array $overrides = []): string
    {
        // the builder copies permissions from the manifest; the fixture is told them
        $zip = $this->zip($slug, $version);
        $this->registry->version('plugin', $slug, 'acme', $zip, $version, $overrides + ['permissions' => ['http' => ['api.acme.example']]]);
        $this->registry->describe($slug, $meta);

        return $zip;
    }

    private function zip(string $slug, string $version): string
    {
        $dir = $this->root . '/src/' . bin2hex(random_bytes(4)) . '/' . $slug;
        mkdir($dir, 0o777, true);
        file_put_contents("{$dir}/plugin.json", json_encode(['slug' => $slug, 'name' => ucwords(str_replace('-', ' ', $slug)), 'version' => $version, 'requires' => ['core' => '>=0.1.0-0'], 'permissions' => ['http' => ['api.acme.example']]]));
        file_put_contents("{$dir}/VERSION.txt", $version);
        $zip = PackageBuilder::build($dir, $this->root . '/dist/' . basename(dirname($dir)))['zip'];
        PackageSignature::sign($zip, $this->acme['secret_key']);

        return $zip;
    }

    /** @return array<string, mixed> */
    private function data(Response $r): array
    {
        $this->assertSame(200, $r->statusCode(), $r->content());

        return $this->json($r)['data'];
    }

    /** @return array<string, mixed> */
    private function browse(string $query = ''): array
    {
        return $this->data($this->api('GET', '/plugins/registry?refresh=1' . ($query !== '' ? "&{$query}" : '')));
    }

    public function testBrowsingListsTheRegistrysFreePluginsByName(): void
    {
        $page = $this->browse();
        $this->assertTrue($page['enabled']);
        $this->assertNull($page['status']['error']);
        $this->assertTrue($page['status']['usable']);
        $this->assertSame(['events', 'forms-extra', 'search-plus'], array_column($page['packages'], 'slug'), 'no theme, no paid plugin');
        $this->assertSame(3, $page['total']);
        $this->assertSame(['content' => 2, 'forms' => 1, 'integrations' => 1], $page['categories']);
        $this->assertSame('Shops and payments', $page['category_labels']['commerce'] ?? null, 'labels for every category, in the site\'s language');

        $events = $page['packages'][0];
        $this->assertSame(['id' => 'acme', 'name' => 'Acme', 'verified' => null], $events['organisation']);
        $this->assertSame('1.0.0', $events['version']);
        $this->assertTrue($events['compatible']);
        $this->assertNull($events['installed']);
        $this->assertFalse($events['update']);
    }

    public function testSearchAndCategoriesNarrowTheList(): void
    {
        $this->assertSame(['events', 'search-plus'], array_column($this->browse('q=events')['packages'], 'slug'), 'a summary match ranks below a name match');
        $this->assertSame(['search-plus'], array_column($this->browse('q=site+events')['packages'], 'slug'), 'every word must match');
        $this->assertSame([], $this->browse('q=nothing+like+this')['packages']);

        $content = $this->browse('category=content');
        $this->assertSame(['events', 'search-plus'], array_column($content['packages'], 'slug'));
        $this->assertSame(['content' => 2, 'forms' => 1, 'integrations' => 1], $content['categories'], 'counts ignore the chosen category');

        $this->assertSame(422, $this->api('GET', '/plugins/registry?category=Not+A+Category')->statusCode());
        $this->assertSame(422, $this->api('GET', '/plugins/registry?q=' . str_repeat('a', 101))->statusCode());
    }

    public function testTheSiteCopyIsRefreshedAtMostOnceAMinute(): void
    {
        $this->browse();
        $before = count($this->registry->requested());
        $this->browse();
        $this->data($this->api('GET', '/plugins/registry?q=events'));
        $this->assertSame($before, count($this->registry->requested()), 'opened again within the minute: the copy it has');
    }

    public function testATurnedOffRegistryListsNothingAndShowsNothing(): void
    {
        $this->cms->config->set('registry.enabled', false);
        $page = $this->browse();
        $this->assertFalse($page['enabled']);
        $this->assertSame([], $page['packages']);
        $this->assertSame(404, $this->api('GET', '/plugins/registry/events')->statusCode());
        $this->assertSame(404, $this->api('POST', '/plugins/registry/events/review', ['version' => '1.0.0'])->statusCode());
    }

    public function testOnePluginInFull(): void
    {
        $this->registry->describe('events', ['description' => "# Events\n\n![pixel](https://evil.example/p.gif)Dates and [places](https://acme.example/places)."]);
        $this->list('events', '2.0.0', [], ['requires' => ['core' => '^9.0']]);
        $this->registry->publish();
        $this->browse();

        $events = $this->data($this->api('GET', '/plugins/registry/events'));
        $this->assertSame('Events', $events['name']);
        $this->assertSame('Acme', $events['organisation']['name']);
        $this->assertStringNotContainsString('<img', $events['description_html']);
        $this->assertStringContainsString('nofollow', $events['description_html']);
        $this->assertSame('1.0.0', $events['offer']['version'], '2.0.0 needs another Kestello');
        $this->assertSame(['http' => ['api.acme.example']], $events['offer']['permissions']);
        $this->assertNull($events['needs_core'], 'only said when nothing can be offered');
        $this->assertSame(['2.0.0', '1.0.0'], array_column($events['versions'], 'version'));
        $this->assertNull($events['installed']);

        $this->assertSame(404, $this->api('GET', '/plugins/registry/aurora')->statusCode(), 'a theme');
        $this->assertSame(404, $this->api('GET', '/plugins/registry/shop')->statusCode(), 'a paid plugin');
        $this->assertSame(404, $this->api('GET', '/plugins/registry/nothing')->statusCode());
    }

    public function testAPluginNeedingAnotherKestelloSaysSo(): void
    {
        $this->list('future', '1.0.0', ['name' => 'Future'], ['requires' => ['core' => '^9.0']]);
        $this->registry->publish();
        $page = $this->browse('q=future');
        $this->assertFalse($page['packages'][0]['compatible']);

        $future = $this->data($this->api('GET', '/plugins/registry/future'));
        $this->assertNull($future['offer']);
        $this->assertSame(['version' => '1.0.0', 'requires' => '^9.0'], $future['needs_core']);
    }

    public function testInstallingGoesThroughTheSameReviewAsAnUpload(): void
    {
        $this->browse();
        $review = $this->data($this->api('POST', '/plugins/registry/events/review', ['version' => '1.0.0']));
        $this->assertTrue($review['allowed']);
        $this->assertSame('vouched', $review['package']['registry']['status']);
        $this->assertSame('signed', $review['package']['signature']['status'], "signed by Acme's certified key; the site trusts no key itself");
        $this->assertNull($review['update']);

        $done = $this->data($this->api('POST', '/plugins/install', ['token' => $review['token'], 'activate' => true]));
        $this->assertSame('events', $done['installed']);
        $this->assertTrue($done['activated']);
        $this->assertSame('acme', $this->cms->container->make(KeyPins::class)->organisation('plugin:events'), 'updates now come from the registry');

        $row = $this->browse('q=events')['packages'][0];
        $this->assertSame(['events', '1.0.0', false], [$row['slug'], $row['installed'], $row['update']]);
        $this->assertSame(['version' => '1.0.0', 'active' => true, 'withdrawn' => null], $this->data($this->api('GET', '/plugins/registry/events'))['installed']);
    }

    public function testAnUpdateIsOfferedAndReviewedAsAnUpdate(): void
    {
        $this->browse();
        $review = $this->data($this->api('POST', '/plugins/registry/events/review', ['version' => '1.0.0']));
        $this->data($this->api('POST', '/plugins/install', ['token' => $review['token'], 'activate' => true]));
        $this->list('events', '1.1.0', []);
        $this->registry->amend('events', '1.0.0', ['withdrawn' => ['at' => RegistryFixture::at($this->registry->now - 60), 'reason' => 'Loses data on save.']]);
        $this->registry->publish();
        $this->browse();
        $this->cms->container->make(RegistryClient::class)->refresh();

        $events = $this->data($this->api('GET', '/plugins/registry/events'));
        $this->assertTrue($events['update']);
        $this->assertSame('1.1.0', $events['offer']['version']);
        $this->assertSame('Loses data on save.', $events['installed']['withdrawn']);
        $this->assertTrue($this->browse('q=events')['packages'][0]['update']);

        $update = $this->data($this->api('POST', '/plugins/registry/events/review', ['version' => '1.1.0']));
        $this->assertSame(['from' => '1.0.0', 'problems' => []], $update['update']);
        $this->assertTrue($update['allowed']);
    }

    public function testAWithdrawnVersionUploadedByHandIsNotAllowed(): void
    {
        $zip = $this->list('events', '0.9.0', [], ['withdrawn' => ['at' => RegistryFixture::at($this->registry->now - 60), 'reason' => 'Loses data on save.']]);
        $this->registry->publish();
        $this->browse();
        // this site trusts Acme's key itself, so only the registry's word stops it
        $this->cms->config->set('app.packages.trusted_keys', [['name' => 'Acme', 'public_key' => $this->acme['public_key']]]);

        $copy = $this->root . '/upload-' . bin2hex(random_bytes(3)) . '.zip';
        copy($zip, $copy);
        $this->nextRequest();
        $review = $this->data($this->cms->routes->dispatch(new \Kestello\Framework\Core\Request(
            'POST',
            '/api/v1/plugins/upload',
            '/api/v1/plugins/upload',
            [],
            [],
            [],
            [],
            ['file' => ['name' => 'events-0.9.0.zip', 'type' => 'application/zip', 'tmp_name' => $copy, 'error' => UPLOAD_ERR_OK, 'size' => filesize($copy)]],
            ['REMOTE_ADDR' => '203.0.113.7'],
            '',
        )));
        $this->assertSame('refused', $review['package']['registry']['status']);
        $this->assertSame('signed', $review['package']['signature']['status'], "signed with Acme's key, which this site trusts");
        $this->assertFalse($review['allowed'], 'the registry withdrew it: it never installs');
        $this->assertSame(422, $this->api('POST', '/plugins/install', ['token' => $review['token']])->statusCode());
    }

    public function testOnlyVersionsTheSiteCanInstallAreDownloaded(): void
    {
        $this->list('events', '0.9.0', [], ['withdrawn' => ['at' => RegistryFixture::at($this->registry->now - 60), 'reason' => 'Broken.']]);
        $this->registry->publish();
        $this->browse();
        $this->assertSame(409, $this->api('POST', '/plugins/registry/events/review', ['version' => '0.9.0'])->statusCode(), 'withdrawn');
        $this->assertSame(409, $this->api('POST', '/plugins/registry/events/review', ['version' => '3.0.0'])->statusCode(), 'not listed');
        $this->assertSame(409, $this->api('POST', '/plugins/registry/aurora/review', ['version' => '1.0.0'])->statusCode(), 'a theme');
        $this->assertSame(422, $this->api('POST', '/plugins/registry/events/review', [])->statusCode());
    }

    public function testPluginsAndThemesReadTheRegistryThroughItsPublicReader(): void
    {
        $this->registry->describe('events', [
            'description' => 'Lists **events**. ![a tracker](https://acme.example/pixel.png) [Docs](https://acme.example/docs)',
            'icon' => 'img/events/icon.png',
            'screenshots' => [['image' => 'img/events/list.png', 'caption' => 'The list of events']],
            'homepage' => 'https://acme.example/events',
        ]);
        $this->registry->amend('events', '1.0.0', ['notes' => 'The **first** release.']);
        $at = fn(int $ago): string => RegistryFixture::at($this->registry->now - $ago);
        $this->registry->advisories[] = ['id' => 'KSA-2027-0001', 'type' => 'plugin', 'package' => 'events', 'affected' => '<1.0.0', 'fixed' => '1.0.0', 'severity' => 'high', 'title' => 'Stored XSS in titles', 'url' => 'https://kestello.com/advisories/KSA-2027-0001', 'published_at' => $at(7200)];
        $this->registry->advisories[] = ['id' => 'KSA-2027-0002', 'type' => 'plugin', 'package' => 'forms-extra', 'affected' => '<2.0.0', 'fixed' => '2.0.0', 'severity' => 'low', 'title' => 'Open redirect', 'url' => 'https://kestello.com/advisories/KSA-2027-0002', 'published_at' => $at(60)];
        $this->registry->publish();
        $registry = $this->cms->registry();

        $this->assertTrue($registry->enabled());
        $this->assertSame([], $registry->packages(), 'nothing until the site has a copy');
        $this->assertFalse($registry->status()['usable']);
        $this->assertTrue($registry->refresh()['usable']);
        $this->assertSame(5, $registry->status()['packages'], 'the index has five, the paid one included');

        $this->assertSame(['aurora', 'events', 'forms-extra', 'search-plus'], array_column($registry->packages(), 'slug'), 'by name; paid packages are left out');
        $this->assertSame(['events', 'forms-extra', 'search-plus'], array_column($registry->packages('plugin'), 'slug'));
        $this->assertSame(['aurora'], array_column($registry->packages('theme'), 'slug'));
        $events = $registry->packages('plugin')[0];
        $this->assertSame(['Events', 'acme', '1.0.0', RegistryFixture::BASE . 'img/events/icon.png'], [$events['name'], $events['organisation'], $events['stable'], $events['icon']]);

        $full = $registry->package('events');
        $this->assertNotNull($full);
        $this->assertStringContainsString('<strong>events</strong>', $full['description_html']);
        $this->assertStringNotContainsString('pixel.png', $full['description_html'], 'no images from a publisher\'s server');
        $this->assertStringContainsString('rel="nofollow ugc"', $full['description_html'], 'links from publishers are marked as theirs');
        $this->assertSame([['url' => RegistryFixture::BASE . 'img/events/list.png', 'caption' => 'The list of events']], $full['screenshots'], 'the registry\'s own copies');
        $this->assertSame('https://acme.example/events', $full['homepage']);
        $this->assertSame('1.0.0', $full['versions'][0]['version']);
        $this->assertStringContainsString('<strong>first</strong>', $full['versions'][0]['notes_html']);
        $this->assertSame(['http' => ['api.acme.example']], $full['versions'][0]['permissions']);
        $this->assertNull($full['versions'][0]['withdrawn']);
        $this->assertSame(['KSA-2027-0001'], array_column($full['advisories'], 'id'), 'only this package\'s');
        $this->assertNull($registry->package('shop'), 'paid packages are left out');
        $this->assertNull($registry->package('nope'));

        $this->assertSame([['id' => 'acme', 'name' => 'Acme', 'verified' => null]], $registry->organisations());
        $acme = $registry->organisation('acme');
        $this->assertSame(['Acme', ['aurora', 'events', 'forms-extra', 'search-plus']], [$acme['name'] ?? null, $acme['packages'] ?? null]);
        $this->assertNull($registry->organisation('nobody'));
        $this->assertSame(['KSA-2027-0002', 'KSA-2027-0001'], array_column($registry->advisories(), 'id'), 'newest first');

        $labels = $registry->categories();
        $this->assertSame(['Content', 'Forms and surveys', 'Themes'], [$labels['content'], $labels['forms'], $labels['theme']]);
        $this->registry->describe('aurora', ['categories' => ['seasonal']]);
        $this->registry->publish();
        $registry->refresh();
        $this->assertSame('seasonal', $registry->categories()['seasonal'] ?? null, 'a category Kestello doesn\'t know yet shows its id');

        $off = new \Kestello\Cms\Package\Registry\Registry(null);
        $this->assertFalse($off->enabled());
        $this->assertSame([[], [], [], null, null], [$off->packages(), $off->organisations(), $off->advisories(), $off->package('events'), $off->organisation('acme')]);
        $this->assertSame('Content', $off->categories()['content'], 'labels need no registry');
        $this->assertSame(['enabled' => false, 'usable' => false], array_intersect_key($off->refresh(), ['enabled' => 1, 'usable' => 1]));
    }

    public function testARefreshThatChangesTheRegistryTellsPluginsWhatChanged(): void
    {
        // the client PackagesModule builds, talking to the fixture registry
        $this->cms->config->set('registry.url', RegistryFixture::BASE);
        $this->cms->config->set('registry.root_keys', [['name' => 'Test root', 'public_key' => $this->registry->root['public_key']]]);
        $this->cms->container->instance(HttpClient::class, new HttpClient($this->registry->transport, static fn(string $host): array => ['104.18.1.1']));
        $this->cms->container->bind(RegistryClient::class, static fn() => null);   // drops the fixture's client
        (new PackagesModule())->register($this->cms->container);
        $seen = [];
        $this->cms->events->on(RegistryRefreshed::class, static function (RegistryRefreshed $e) use (&$seen): void {
            $seen[] = [$e->packages, $e->organisations, $e->advisories];
        });
        $registry = $this->cms->registry();

        $this->assertTrue($registry->refresh()['usable']);
        $this->assertSame([[['aurora', 'events', 'forms-extra', 'search-plus', 'shop'], ['acme'], true]], $seen, 'the first copy: everything');

        $registry->refresh();
        $this->assertCount(1, $seen, 'nothing new, nothing told');

        $this->registry->describe('events', ['summary' => 'Events, now with tickets.']);
        $this->registry->publish();
        $registry->refresh();
        $this->assertSame([['events'], [], false], $seen[1], 'only the package that changed');

        $this->registry->advisories[] = ['id' => 'KSA-2027-0003', 'type' => 'plugin', 'package' => 'events', 'affected' => '<1.0.0', 'fixed' => '1.0.0', 'severity' => 'medium', 'title' => 'CSRF in settings', 'url' => 'https://kestello.com/advisories/KSA-2027-0003', 'published_at' => RegistryFixture::at($this->registry->now - 60)];
        $this->registry->publish();
        $registry->refresh();
        $this->assertSame([[], [], true], $seen[2], 'only the advisories');

        $this->registry->transport->on('GET ' . RegistryFixture::BASE . 'keys.json', static fn(): array => ['status' => 503]);
        $this->assertNotNull($registry->refresh()['error']);
        $this->assertCount(3, $seen, 'a failed refresh tells nothing');
    }
}
