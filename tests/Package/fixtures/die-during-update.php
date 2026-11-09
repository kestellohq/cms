<?php

/*
 * Runs an update in its own PHP process and kills that process (SIGKILL)
 * in the middle of the health check, the way a host's time or memory limit
 * would: no catch, no finally, no shutdown function runs. Used by
 * UpdateRecoveryTest; not part of the site.
 *
 *   php die-during-update.php <core|plugin> <site root> <sqlite file> <zip> [running core version]
 */

declare(strict_types=1);

use Kestello\Cms\Backup\BackupModule;
use Kestello\Cms\Cms;
use Kestello\Cms\Extension\DatabaseStateStore;
use Kestello\Cms\Extension\ExtensionManager;
use Kestello\Cms\Extension\SuspensionList;
use Kestello\Cms\Package\PackagesModule;
use Kestello\Cms\Package\Update\CoreUpdater;
use Kestello\Cms\Package\Update\HealthProbe;
use Kestello\Cms\Package\Update\Updater;
use Kestello\Framework\Database\Connection;
use Kestello\Framework\Database\DatabaseManager;
use Kestello\Support\AppKey;
use Kestello\Tests\Support\TestCms;

[, $kind, $root, $database, $zip] = $argv;
$running = $argv[5] ?? '0.1.0';

require dirname(__DIR__, 3) . '/core/bootstrap.php';
$autoloader = new Kestello\Framework\Core\Autoloader();
$autoloader->addNamespace('Kestello\\Tests\\', dirname(__DIR__, 2));
$autoloader->register();

DatabaseManager::setConnection($db = new Connection(['driver' => 'sqlite', 'database' => $database]));
AppKey::set((string) getenv('KESTELLO_TEST_KEY'));
$cms = TestCms::make($root);
Cms::setInstance($cms);
$cms->container->instance(Cms::class, $cms);
$cms->storage->configure($root . '/storage/app', []);
$ext = new ExtensionManager($cms, new DatabaseStateStore(), new SuspensionList($root . '/suspended.json'), '0.1.0');
$ext->boot();
$cms->setExtensions($ext);
foreach ([new BackupModule(), new PackagesModule()] as $module) {
    $module->register($cms->container);
    $module->boot($cms);
}

// the new code changes the database, then PHP is gone
$cms->container->instance(HealthProbe::class, new class ($db) implements HealthProbe {
    public function __construct(private Connection $db) {}

    public function run(string $owner, string $bypass): array
    {
        $this->db->schema()->create('half_migrated', fn($t) => $t->id());
        posix_kill(posix_getpid(), 9);      // SIGKILL (pcntl, which names it, is often missing)
        sleep(30);

        return [];
    }
});

$c = $cms->container;
if ($kind === 'core') {
    $result = (new CoreUpdater($cms, $c->make(\Kestello\Cms\Backup\Backups::class), $c->make(HealthProbe::class), $running))->update($zip, ['allow_unsigned' => true]);
} else {
    $result = $c->make(Updater::class)->update($zip, ['allow_unsigned' => true]);
}
fwrite(STDERR, 'The update finished: the process was not killed. ' . json_encode($result) . "\n");
exit(3);
