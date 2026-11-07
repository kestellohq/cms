<?php

declare(strict_types=1);

namespace Kestello\Tests\Package;

use Kestello\Cms\Backup\DatabaseDump;
use Kestello\Cms\Content\ContentModule;
use Kestello\Cms\Extension\ArrayStateStore;
use Kestello\Cms\Extension\ExtensionManager;
use Kestello\Cms\Extension\SuspensionList;
use Kestello\Cms\Package\PackageBuilder;
use Kestello\Cms\Package\PackageInstaller;
use Kestello\Framework\Database\Connection;
use Kestello\Framework\Database\DatabaseManager;
use Kestello\Framework\Database\MigrationRunner;
use Kestello\Framework\Database\Model;
use Kestello\Framework\Database\QueryBuilder;
use Kestello\Framework\Settings\Settings;
use Kestello\Tests\Auth\AuthTestCase;

/**
 * A plugin's whole life through the public extension points, with the fixture in
 * tests/fixtures/plugins/notebook: install from a zip, activate (migrations), use it,
 * deactivate (data stays), uninstall (nothing left). On SQLite and on every database in
 * KESTELLO_TEST_DATABASES.
 */
final class PluginLifecycleTest extends AuthTestCase
{
    private string $root;
    private ArrayStateStore $state;
    private ExtensionManager $extensions;

    protected function tearDown(): void
    {
        Model::resetRegistry();
        parent::tearDown();
    }

    public function testTheWholeLifecycleOnSqlite(): void
    {
        $this->lifecycle();
    }

    public function testTheWholeLifecycleOnMysqlAndPostgres(): void
    {
        $extra = json_decode((string) getenv('KESTELLO_TEST_DATABASES'), true);
        if (!is_array($extra) || $extra === []) {
            $this->assertTrue(true);
            return;      // only when KESTELLO_TEST_DATABASES is set
        }
        foreach ($extra as $config) {
            $this->db = new Connection($config);
            DatabaseManager::setConnection($this->db);
            Settings::destroy();
            (new DatabaseDump($this->db))->dropAll();
            (new MigrationRunner(self::corePath('database/migrations')))->migrate();
            $this->lifecycle();
            (new DatabaseDump($this->db))->dropAll();
        }
    }

    private function lifecycle(): void
    {
        $this->root = $this->cms->paths->root();
        $this->state = new ArrayStateStore();

        // install from a zip and activate: its migrations run
        $zip = PackageBuilder::build(dirname(__DIR__) . '/fixtures/plugins/notebook', $this->root . '/dist')['zip'];
        $this->request();
        $installed = (new PackageInstaller($this->cms))->install($zip, ['allow_unsigned' => true]);
        $this->assertSame('notebook', $installed['package']->slug());
        $this->extensions->activatePlugin('notebook');
        $this->assertTrue($this->db->schema()->hasTable('p_notebook_notes'), 'migrations ran');

        // the next request: it boots with the site, and its parts work
        $this->request();
        $this->createUser();
        $this->assertSame(200, $this->api('POST', '/auth/session', ['login' => 'ana', 'password' => self::PASSWORD])->statusCode());
        $types = array_column($this->json($this->api('GET', '/content/types'))['data'], null, 'name');
        $this->assertSame('plugin:notebook', $types['notebook-page']['owner'] ?? null);

        $topic = $this->json($this->api('POST', '/taxonomies/notebook-topic/terms', ['name' => 'Ideas']))['data'];
        $page = $this->api('POST', '/content/notebook-page/entries', ['title' => 'First', 'status' => 'published', 'data' => ['code' => 'NB-1'], 'terms' => ['notebook-topic' => [$topic['id']]]]);
        $this->assertSame(200, $page->statusCode(), $page->content());
        $id = $this->json($page)['data']['id'];
        $this->assertSame(422, $this->api('POST', '/content/notebook-page/entries', ['title' => 'No code', 'status' => 'published'])->statusCode(), 'core checks its required field');
        $this->assertSame(['First'], array_column($this->json($this->api('GET', '/content/notebook-page/entries?filter[code]=NB-1'))['data'], 'title'), 'its indexed field filters on every database');

        $note = $this->api('POST', "/notebook/pages/{$id}/notes", ['text' => 'Remember the milk']);
        $this->assertSame(200, $note->statusCode(), $note->content());
        $this->assertSame(1, $this->json($note)['data']['notes']);
        Settings::getInstance()->set('plugin.notebook.general.heading', 'Jottings');

        // deactivate: the plugin is gone, its data stays
        $this->extensions->deactivatePlugin('notebook');
        $this->request();
        $this->assertSame(200, $this->api('POST', '/auth/session', ['login' => 'ana', 'password' => self::PASSWORD])->statusCode());
        $this->assertArrayNotHasKey('notebook-page', array_column($this->json($this->api('GET', '/content/types'))['data'], null, 'name'));
        $this->assertSame(404, $this->api('POST', "/notebook/pages/{$id}/notes", ['text' => 'x'])->statusCode());
        $this->assertSame(1, QueryBuilder::table('entries')->where('type', 'notebook-page')->count(), 'pages wait for the plugin to come back');
        $this->assertTrue($this->db->schema()->hasTable('p_notebook_notes'));

        // uninstall: nothing of it is left
        $this->extensions->uninstallPlugin('notebook');
        $this->assertFalse($this->db->schema()->hasTable('p_notebook_notes'));
        $this->assertSame(0, QueryBuilder::table('entries')->where('type', 'notebook-page')->count());
        $this->assertSame(0, QueryBuilder::table('terms')->where('taxonomy', 'notebook-topic')->count());
        $this->assertSame(0, QueryBuilder::table('settings')->where('key', 'LIKE', 'plugin.notebook.%')->count());
        $this->assertFalse(is_dir($this->root . '/content/plugins/notebook'));
    }

    /** A fresh request on the same site: core modules, then the active plugins. */
    private function request(): void
    {
        Model::resetRegistry();
        $this->bootCms($this->root);
        $this->extensions = new ExtensionManager($this->cms, $this->state, new SuspensionList($this->root . '/suspended.json'), '0.1.0');
        $this->cms->setExtensions($this->extensions);
        $content = new ContentModule();
        $content->register($this->cms->container);
        $content->boot($this->cms);
        $this->extensions->boot();
        $this->assertSame([], $this->extensions->suspensions()->all(), 'the plugin booted');
        ContentModule::finalize($this->cms);
    }
}
