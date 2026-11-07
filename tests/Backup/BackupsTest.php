<?php

declare(strict_types=1);

namespace Kestello\Tests\Backup;

use Kestello\Cms\Backup\Backups;
use Kestello\Cms\Content\ContentException;
use Kestello\Cms\Extension\ArrayStateStore;
use Kestello\Cms\Extension\ExtensionManager;
use Kestello\Cms\Extension\SuspensionList;
use Kestello\Cms\System\Maintenance;
use Kestello\Framework\Database\Connection;
use Kestello\Framework\Database\DatabaseManager;
use Kestello\Framework\Settings\Settings;
use Kestello\Support\Zip\Zip;
use Kestello\Tests\Auth\AuthTestCase;

/** Backups, restores, and moving a site between database engines. */
final class BackupsTest extends AuthTestCase
{
    private string $root;
    private ArrayStateStore $state;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = $this->cms->paths->root();
        $this->cms->storage->configure($this->root . '/storage/app', []);

        // a plugin with its own table
        mkdir("{$this->root}/content/plugins/notes/database/migrations", 0777, true);
        file_put_contents("{$this->root}/content/plugins/notes/plugin.json", json_encode(['slug' => 'notes', 'name' => 'Notes', 'version' => '1.0.0', 'requires' => ['core' => '^0.1']]));
        file_put_contents("{$this->root}/content/plugins/notes/database/migrations/2026_01_01_000001_create_items.php", <<<'PHP'
        <?php
        use Kestello\Framework\Database\Blueprint;
        use Kestello\Framework\Database\Migration;
        return new class extends Migration {
            public function up(): void
            {
                $this->schema()->create('p_notes_items', function (Blueprint $t): void {
                    $t->id();
                    $t->string('title', 80);
                    $t->boolean('done')->default(false);
                    $t->json('meta')->nullable();
                    $t->double('weight')->nullable();
                });
                // a seeding migration: on restore its row must give way to the backup's, not clash with it
                \Kestello\Framework\Database\QueryBuilder::table('p_notes_items')->insert(['title' => 'seeded', 'done' => 0]);
            }
            public function down(): void { $this->schema()->dropIfExists('p_notes_items'); }
        };
        PHP);
        $this->state = new ArrayStateStore();
        $extensions = new ExtensionManager($this->cms, $this->state, new SuspensionList($this->root . '/suspended.json'), '0.1.0');
        $extensions->boot();
        $this->cms->setExtensions($extensions);
        $extensions->activatePlugin('notes');

        $this->createUser();
        Settings::getInstance()->set('site_name', 'Enamel & Co');
        $this->db->table('p_notes_items')->insert(['title' => 'Ünïcödé — "quoted"', 'done' => 1, 'meta' => '{"a":[1,2]}', 'weight' => 1.25]);
        $this->db->table('p_notes_items')->insert(['title' => 'second', 'done' => 0, 'meta' => null, 'weight' => null]);
        mkdir("{$this->root}/storage/app/media/2026", 0777, true);
        file_put_contents("{$this->root}/storage/app/media/2026/plate.txt", 'enamel');
        mkdir("{$this->root}/content/uploads", 0777, true);
        file_put_contents("{$this->root}/content/uploads/old.txt", 'legacy');
    }

    private function backups(?Connection $db = null): Backups
    {
        return new Backups($this->cms, $db);
    }

    public function testBackupAndRestoreRoundTrip(): void
    {
        file_put_contents("{$this->root}/storage/app/media/2026/secret.txt", 'private');
        chmod("{$this->root}/storage/app/media/2026/secret.txt", 0600);
        // what visitors attached to a form: the submissions come back, so must their files
        @mkdir("{$this->root}/storage/forms/7/2026/11", 0775, true);
        file_put_contents("{$this->root}/storage/forms/7/2026/11/cv.pdf", 'a visitor\'s file');
        $made = $this->backups()->create(['note' => 'before the redesign']);
        $this->assertSame('manual', $made['kind']);
        $this->assertTrue($made['files']);
        $this->assertSame('before the redesign', $made['note']);
        $this->assertTrue(str_starts_with($made['name'], 'kestello-backup-'));

        // what's inside: rows as JSON lines, files, a signature, never sessions
        $zip = Zip::open($this->backups()->localPath($made['name']));
        $this->assertTrue($zip->has('database/users.jsonl'));
        $this->assertTrue($zip->has('database/p_notes_items.jsonl'));
        $this->assertTrue($zip->has('files/storage/app/media/2026/plate.txt'));
        $this->assertTrue($zip->has('files/content/plugins/notes/plugin.json'));
        $this->assertTrue($zip->has('files/storage/forms/7/2026/11/cv.pdf'), 'form uploads are backed up');
        $this->assertTrue($zip->has('backup.sig'));
        $this->assertFalse($zip->has('database/sessions.jsonl'));
        $this->assertFalse($zip->has('database/migrations.jsonl'));
        $manifest = json_decode($zip->read('backup.json'), true);
        $this->assertSame(3, $manifest['tables']['p_notes_items']['rows']);
        $this->assertContains(['migration' => '2026_01_01_000001_create_items.php', 'owner' => 'plugin:notes'], $manifest['migrations']);

        // the site changes after the backup
        $this->db->table('users')->delete();
        $this->db->table('p_notes_items')->where('title', 'second')->delete();
        Settings::getInstance()->set('site_name', 'Changed');
        file_put_contents("{$this->root}/storage/app/media/2026/plate.txt", 'rust');
        file_put_contents("{$this->root}/storage/app/media/2026/new.txt", 'added later');
        unlink("{$this->root}/storage/forms/7/2026/11/cv.pdf");

        $report = $this->backups()->restore($this->backups()->localPath($made['name']));
        $this->assertFalse($report['skipped_code']);
        $this->assertSame([], $report['missing_tables']);
        $this->assertNotNull($report['safety']);

        $this->assertSame('ana', $this->db->table('users')->first()['username'] ?? null);
        $this->assertSame(3, $this->db->table('p_notes_items')->count(), 'the seeded row once, from the backup');
        $item = $this->db->table('p_notes_items')->where('title', 'Ünïcödé — "quoted"')->first();
        $this->assertSame(['a' => [1, 2]], json_decode((string) $item['meta'], true));
        $this->assertSame('Enamel & Co', Settings::getInstance()->get('site_name'));
        $this->assertSame('enamel', file_get_contents("{$this->root}/storage/app/media/2026/plate.txt"));
        $this->assertFalse(is_file("{$this->root}/storage/app/media/2026/new.txt"), 'the folder is the archive\'s');
        $this->assertSame('a visitor\'s file', file_get_contents("{$this->root}/storage/forms/7/2026/11/cv.pdf"), 'and come back with a restore');
        $this->assertNull((new Maintenance($this->root . '/storage'))->current(), 'maintenance is off again');
        clearstatcache();
        $this->assertSame(0600, fileperms("{$this->root}/storage/app/media/2026/secret.txt") & 0777, 'a private file comes back private');
        // new rows still get fresh ids
        $id = $this->db->table('p_notes_items')->insert(['title' => 'third', 'done' => 0]);
        $this->assertTrue((int) $id > 2);

        // the safety copy is listed; retention keeps the newest automatic ones and every manual one
        $this->assertContains('before-restore', array_column($this->backups()->all(), 'kind'));
        sleep(1);
        $this->backups()->create(['files' => false, 'kind' => 'scheduled']);
        $this->assertSame(0, $this->backups()->prune(1), 'one of each kind is within the limit');
        $kinds = array_column($this->backups()->all(), 'kind');
        sort($kinds);
        $this->assertSame(['before-restore', 'manual', 'scheduled'], $kinds);
        $this->assertSame(0, $this->backups()->prune(0), 'the last automatic backup is always kept');
        foreach ($this->backups()->all() as $b) {
            $this->backups()->delete($b['name']);
        }
        $this->assertSame([], $this->backups()->all());
    }

    public function testDatabaseOnlyBackupsLeaveFilesAlone(): void
    {
        $made = $this->backups()->create(['files' => false]);
        $this->assertFalse($made['files']);
        file_put_contents("{$this->root}/storage/app/media/2026/new.txt", 'kept');
        $this->backups()->restore($this->backups()->localPath($made['name']), ['safety' => false]);
        $this->assertSame('kept', file_get_contents("{$this->root}/storage/app/media/2026/new.txt"));
    }

    public function testDamagedArchivesAreRefusedAndTheSiteStaysAsItWas(): void
    {
        $made = $this->backups()->create();
        $source = $this->backups()->localPath($made['name']);

        // a row changed after the backup was made: the checksum catches it, the safety copy puts things back
        $tampered = $this->rewrite($source, ['database/users.jsonl' => static fn(string $s): string => str_replace('"ana"', '"eve"', $s)]);
        Settings::getInstance()->set('site_name', 'Current');
        try {
            $this->backups()->restore($tampered);
            $this->fail('a damaged archive restored');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('checksum', $e->getMessage());
            $this->assertStringContainsString('put back as it was', $e->getMessage());
        }
        $this->assertSame('Current', Settings::getInstance()->get('site_name'));
        $this->assertSame('ana', $this->db->table('users')->first()['username'] ?? null);
        $this->assertSame('enamel', file_get_contents("{$this->root}/storage/app/media/2026/plate.txt"));
        $this->assertNull((new Maintenance($this->root . '/storage'))->current());

        // files the archive doesn't list, paths outside the sections, newer cores
        $stray = $this->rewrite($source, [], ['extra.php' => '<?php echo 1;']);
        $this->assertInvalid(fn() => $this->backups()->inspect($stray), 'does not list');
        $outside = $this->rewrite($source, [], [], static function (array $m): array {
            $m['entries']['files/config/app.php'] = str_repeat('a', 64);
            return $m;
        });
        $this->assertInvalid(fn() => $this->backups()->inspect($outside), 'may not contain');
        $newer = $this->rewrite($source, [], [], static fn(array $m): array => ['core' => '9.0.0'] + $m);
        $this->assertInvalid(fn() => $this->backups()->inspect($newer), 'newer than this site');
        $this->assertInvalid(fn() => $this->backups()->inspect(__FILE__), 'not a Kestello backup');
    }

    public function testARestoreThatBreaksTheDatabaseKeepsVisitorsOut(): void
    {
        $made = $this->backups()->create();
        $hash = '';
        // a valid archive whose rows no longer fit the tables: fails after the database was emptied
        $broken = $this->rewrite($this->backups()->localPath($made['name']), ['database/users.jsonl' => static function (string $s) use (&$hash): string {
            $first = strtok($s, "\n");
            $s = $first . "\n" . $s;          // the same row twice: a primary key clash on import
            $hash = hash('sha256', $s);
            return $s;
        }], [], static function (array $m) use (&$hash): array {
            $m['entries']['database/users.jsonl'] = $hash;
            return $m;
        });
        try {
            $this->backups()->restore($broken, ['safety' => false]);
            $this->fail('the broken restore went through');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('stays in maintenance mode', $e->getMessage());
        }
        $state = (new Maintenance($this->root . '/storage'))->current();
        $this->assertSame(Maintenance::ALL, $state['mode'] ?? null, 'visitors never see the emptied database');
        (new Maintenance($this->root . '/storage'))->off();
    }

    public function testArchivesFromElsewhereDontBringCodeUnlessAsked(): void
    {
        $made = $this->backups()->create();
        // any change to backup.json breaks the signature: it now looks like another site's archive
        $foreign = $this->rewrite($this->backups()->localPath($made['name']), [], [], static fn(array $m): array => ['note' => 'from elsewhere'] + $m);
        $this->assertFalse($this->backups()->inspect($foreign)['signed']);
        $this->assertTrue($this->backups()->inspect($this->backups()->localPath($made['name']))['signed']);

        file_put_contents("{$this->root}/content/plugins/notes/local.txt", 'this site\'s code');
        $report = $this->backups()->restore($foreign, ['safety' => false]);
        $this->assertTrue($report['skipped_code']);
        $this->assertTrue(is_file("{$this->root}/content/plugins/notes/local.txt"), 'plugin folders untouched');
        $this->assertSame('enamel', file_get_contents("{$this->root}/storage/app/media/2026/plate.txt"), 'data files restored');

        $report = $this->backups()->restore($foreign, ['safety' => false, 'code' => true]);
        $this->assertFalse($report['skipped_code']);
        $this->assertFalse(is_file("{$this->root}/content/plugins/notes/local.txt"));
    }

    public function testASiteMovesBetweenDatabaseEngines(): void
    {
        $extra = json_decode((string) getenv('KESTELLO_TEST_DATABASES'), true);
        if (!is_array($extra) || $extra === []) {
            $this->assertTrue(true);
            return;      // MySQL and Postgres only when KESTELLO_TEST_DATABASES is set
        }
        $chain = [$this->db];
        foreach ($extra as $config) {
            $chain[] = new Connection($config);
        }
        $chain[] = Connection::sqliteMemory();
        // rows that point at users from tables that sort before "users": a restore
        // that loads tables alphabetically breaks on engines that enforce foreign keys
        $ana = (int) $this->db->table('users')->where('username', 'ana')->first()['id'];
        $this->db->table('user_identities')->insert(['user_id' => $ana, 'provider' => 'google', 'subject' => 'g-1', 'created_at' => time()]);
        $this->db->table('mfa_methods')->insert(['user_id' => $ana, 'type' => 'totp', 'secret' => 'sealed', 'created_at' => time(), 'confirmed_at' => time()]);

        $expect = $this->snapshot($this->db);
        $previous = $this->db;
        foreach (array_slice($chain, 1) as $target) {
            DatabaseManager::setConnection($previous);
            Settings::destroy();
            $made = $this->backups()->create(['files' => false]);
            $file = $this->backups()->localPath($made['name']);

            DatabaseManager::setConnection($target);
            Settings::destroy();
            $report = $this->backups()->restore($file, ['safety' => false]);
            $this->assertSame([], $report['missing_tables'], $target->driver());
            $this->assertSame($expect, $this->snapshot($target), "{$previous->driver()} → {$target->driver()}");
            $this->assertSame('Enamel & Co', Settings::getInstance()->get('site_name'));
            if ($target->driver() === 'pgsql') {
                $keys = (int) $target->select("SELECT COUNT(*) AS n FROM pg_constraint WHERE contype = 'f' AND connamespace = current_schema()::regnamespace")[0]['n'];
                $this->assertTrue($keys >= 5, "foreign keys are back after the restore ({$keys})");
            }
            $this->backups()->delete($made['name']);
            $previous = $target;
        }
        DatabaseManager::setConnection($this->db);
        Settings::destroy();
        foreach (array_slice($chain, 1, -1) as $c) {
            (new \Kestello\Cms\Backup\DatabaseDump($c))->dropAll();
        }
    }

    public function testBackupsOnARemoteDiskLeaveNoCopiesBehind(): void
    {
        // a disk that isn't local (as S3 is): archives are fetched to storage/tmp to be read
        $this->cms->storage->extend('far-away', fn(array $c, $m) => new \Kestello\Framework\Storage\ScopedDisk(new \Kestello\Framework\Storage\LocalDisk($this->root . '/far'), 'b'));
        $this->cms->storage->define('remote', ['driver' => 'far-away']);
        $remote = new Backups($this->cms, null, 'remote');
        $made = $remote->create(['files' => false]);
        $tmp = $this->root . '/storage/tmp';
        $leftovers = static fn() => glob($tmp . '/download-*') ?: [];

        $seen = $remote->withLocalCopy($made['name'], static fn(string $path) => is_file($path) ? filesize($path) : 0);
        $this->assertTrue($seen > 0, 'the copy is there while it is used');
        $this->assertSame([], $leftovers(), 'and gone after');

        $remote->restoreNamed($made['name'], ['safety' => false]);
        $this->assertSame([], $leftovers(), 'a restore cleans up too');

        [$stream, $size] = $remote->open($made['name']);
        $this->assertSame($seen, $size);
        $this->assertSame($seen, strlen((string) stream_get_contents($stream)), 'a download streams from the disk');
        fclose($stream);
        $this->assertSame([], $leftovers());
    }

    public function testRetentionKeepsEnoughOfEachKind(): void
    {
        $b = $this->backups();
        foreach (['scheduled', 'scheduled', 'scheduled', 'manual'] as $kind) {
            $b->create(['files' => false, 'kind' => $kind]);
        }
        // an evening of plugin updates, each with its own backup first
        foreach (range(1, 4) as $_) {
            $b->create(['files' => false, 'kind' => 'before-update']);
        }
        $b->prune(2);
        $kinds = array_count_values(array_column($b->all(), 'kind'));
        $this->assertSame(2, $kinds['scheduled'] ?? 0, 'updates do not push out the nightly backups');
        $this->assertSame(2, $kinds['before-update'] ?? 0);
        $this->assertSame(1, $kinds['manual'] ?? 0, 'manual backups stay');
    }

    public function testARollbackKeepsTheQueueAndWhoIsSignedIn(): void
    {
        $made = $this->backups()->create(['files' => false, 'kind' => 'before-update']);
        // after the backup: an email waits in the queue and someone signs in
        $this->db->table('jobs')->insert(['queue' => 'default', 'payload' => '{"job":"mail"}', 'attempts' => 0, 'available_at' => time(), 'created_at' => time()]);
        $this->db->table('sessions')->insert(['id' => str_repeat('a', 64), 'user_id' => null, 'ip' => '203.0.113.5', 'user_agent' => 'test', 'payload' => 'x', 'created_at' => time(), 'last_activity' => time()]);

        $this->backups()->restoreNamed($made['name'], ['safety' => false, 'keep' => Backups::KEEP_ON_ROLLBACK]);

        $this->assertSame(1, $this->db->table('jobs')->count(), 'the waiting email is still queued');
        $this->assertSame(1, $this->db->table('sessions')->count(), 'and nobody was signed out');
        $this->backups()->restoreNamed($made['name'], ['safety' => false]);
        $this->assertSame(0, $this->db->table('jobs')->count(), 'a full restore still starts them empty');
    }

    public function testTheApi(): void
    {
        $module = new \Kestello\Cms\Backup\BackupModule();
        $module->register($this->cms->container);
        $module->boot($this->cms);
        $data = fn(\Kestello\Framework\Core\Response $r): array => $this->json($r)['data'] ?? [];

        // editors don't get backups (they hold every row, password hashes included)
        $this->createUser(['username' => 'ed', 'email' => 'ed@example.test', 'role' => 'editor']);
        $this->assertSame(200, $this->api('POST', '/auth/session', ['login' => 'ed', 'password' => self::PASSWORD])->statusCode());
        $this->assertSame(403, $this->api('GET', '/backups')->statusCode());
        $this->api('DELETE', '/auth/session');
        $this->assertSame(200, $this->api('POST', '/auth/session', ['login' => 'ana', 'password' => self::PASSWORD])->statusCode());

        $list = $data($this->api('GET', '/backups'));
        $this->assertSame(['schedule' => 'daily', 'files' => false, 'keep' => 7], $list['settings']);
        $made = $data($this->api('POST', '/backups', ['files' => false, 'note' => 'api']));
        $name = $made['created'];
        $this->assertSame('api', $made['backups'][0]['note']);

        $settings = $data($this->api('PUT', '/backups/settings', ['schedule' => 'weekly', 'files' => true, 'keep' => 3]));
        $this->assertSame(['schedule' => 'weekly', 'files' => true, 'keep' => 3], $settings['settings']);
        $this->assertSame(422, $this->api('PUT', '/backups/settings', ['schedule' => 'hourly', 'files' => true, 'keep' => 3])->statusCode());

        $download = $this->api('GET', "/backups/{$name}/download");
        $this->assertSame(200, $download->statusCode());
        $this->assertSame('application/zip', $download->headers()['Content-Type'] ?? null);
        $this->assertSame(404, $this->api('GET', '/backups/kestello-backup-x-20260101-000000-abcdef.zip/download')->statusCode());

        // a backup keeper can make and download backups, but restoring one decides who the administrators are
        $this->cms->auth->roles->role('keeper', 'Backup keeper', ['read', 'admin.access', 'backups.manage']);
        $this->createUser(['username' => 'kim', 'email' => 'kim@example.test', 'role' => 'keeper']);
        $this->api('DELETE', '/auth/session');
        $this->assertSame(200, $this->api('POST', '/auth/session', ['login' => 'kim', 'password' => self::PASSWORD])->statusCode());
        $this->assertSame(200, $this->api('GET', '/backups')->statusCode());
        $this->assertSame(403, $this->api('POST', "/backups/{$name}/restore", ['confirm' => 'Kestello'])->statusCode());
        $this->api('DELETE', '/auth/session');
        $this->assertSame(200, $this->api('POST', '/auth/session', ['login' => 'ana', 'password' => self::PASSWORD])->statusCode());

        // restoring needs the site's name typed
        $this->assertSame(422, $this->api('POST', "/backups/{$name}/restore", ['confirm' => 'yes'])->statusCode());
        Settings::getInstance()->set('site_name', 'Enamel & Co');
        $this->db->table('p_notes_items')->delete();
        $restored = $this->api('POST', "/backups/{$name}/restore", ['confirm' => 'Enamel & Co']);
        $this->assertSame(200, $restored->statusCode(), $restored->content());
        $this->assertSame(3, $this->db->table('p_notes_items')->count());

        // an upload from another site lands next to this site's backups
        $this->assertSame(200, $this->api('POST', '/auth/session', ['login' => 'ana', 'password' => self::PASSWORD])->statusCode());
        $file = $this->backups()->localPath($name);
        $copy = $this->root . '/upload.zip';
        copy($file, $copy);
        $this->nextRequest();
        $upload = $this->cms->routes->dispatch(new \Kestello\Framework\Core\Request(
            'POST',
            '/api/v1/backups/upload',
            '/api/v1/backups/upload',
            [],
            [],
            [],
            [],
            ['file' => ['name' => 'site.zip', 'type' => 'application/zip', 'tmp_name' => $copy, 'error' => UPLOAD_ERR_OK, 'size' => filesize($copy)]],
            ['REMOTE_ADDR' => '203.0.113.7'],
            '',
        ));
        $this->assertSame(200, $upload->statusCode(), $upload->content());
        $this->assertTrue(str_contains($data($upload)['uploaded'], '-uploaded-'));
        file_put_contents($copy, 'not a zip');
        $this->nextRequest();
        $bad = $this->cms->routes->dispatch(new \Kestello\Framework\Core\Request(
            'POST',
            '/api/v1/backups/upload',
            '/api/v1/backups/upload',
            [],
            [],
            [],
            [],
            ['file' => ['name' => 'x.zip', 'type' => 'application/zip', 'tmp_name' => $copy, 'error' => UPLOAD_ERR_OK, 'size' => 9]],
            ['REMOTE_ADDR' => '203.0.113.7'],
            '',
        ));
        $this->assertSame(422, $bad->statusCode());

        $this->assertSame(200, $this->api('DELETE', "/backups/{$name}")->statusCode());
    }

    /** @return array<string, mixed> engine-neutral view of the rows that matter */
    private function snapshot(Connection $db): array
    {
        $items = array_map(static fn(array $r): array => [
            'id' => (int) $r['id'], 'title' => $r['title'], 'done' => (bool) $r['done'],
            'meta' => $r['meta'] === null ? null : json_decode((string) $r['meta'], true), 'weight' => $r['weight'] === null ? null : (float) $r['weight'],
        ], $db->table('p_notes_items')->orderBy('id')->get());
        $users = array_map(static fn(array $r): array => [(int) $r['id'], $r['username'], $r['email'], (bool) $r['is_active'], substr((string) $r['created_at'], 0, 19)], $db->table('users')->orderBy('id')->get());

        return [
            'items' => $items, 'users' => $users, 'roles' => $db->table('roles')->count(), 'settings' => $db->table('settings')->where('key', 'site_name')->first()['value'] ?? null,
            'linked' => array_map(static fn(array $r) => [(int) $r['user_id'], $r['provider']], $db->table('user_identities')->orderBy('id')->get()),
            'two_step' => $db->table('mfa_methods')->count(),
        ];
    }

    /**
     * Copy an archive, changing entries, adding some, or editing backup.json.
     *
     * @param array<string, callable(string): string> $change
     * @param array<string, string> $add
     * @param (callable(array<string, mixed>): array<string, mixed>)|null $manifest
     */
    private function rewrite(string $source, array $change = [], array $add = [], ?callable $manifest = null): string
    {
        $in = Zip::open($source);
        $path = $this->root . '/storage/tmp/edited-' . bin2hex(random_bytes(3)) . '.zip';
        @mkdir(dirname($path), 0777, true);
        $out = Zip::create($path);
        foreach ($in->entries() as $entry) {
            $body = $in->read($entry->name, 64 << 20);
            if ($entry->name === 'backup.json' && $manifest !== null) {
                $body = (string) json_encode($manifest(json_decode($body, true)));
            }
            $out->addString($entry->name, isset($change[$entry->name]) ? $change[$entry->name]($body) : $body);
        }
        foreach ($add as $name => $body) {
            $out->addString($name, $body);
        }
        $out->close();

        return $path;
    }

    private function assertInvalid(callable $fn, string $needle): void
    {
        try {
            $fn();
            $this->fail("expected a refusal mentioning \"{$needle}\"");
        } catch (ContentException $e) {
            $this->assertStringContainsString($needle, implode(' ', array_merge(...array_values($e->errors))));
        }
    }
}
