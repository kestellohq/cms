<?php

declare(strict_types=1);

namespace Kestello\Tests\Health;

use Kestello\Cms\Api\Schema;
use Kestello\Cms\Health\HealthModule;
use Kestello\Cms\Health\HealthResult;
use Kestello\Cms\Notifications\NotificationsModule;
use Kestello\Cms\Platform\ArrayPlatformStore;
use Kestello\Cms\Platform\DriverDefinition;
use Kestello\Cms\Platform\PlatformChanged;
use Kestello\Cms\Platform\PlatformConfig;
use Kestello\Cms\Platform\PlatformManager;
use Kestello\Cms\Platform\Subsystem;
use Kestello\Cms\System\SystemModule;
use Kestello\Contracts\Cache\Store;
use Kestello\Framework\Cache\ArrayStore;
use Kestello\Framework\Core\ExecutionContext;
use Kestello\Framework\Queue\ArrayQueue;
use Kestello\Framework\Security\AppKeySecretStore;
use Kestello\Tests\Auth\AuthTestCase;

final class SiteHealthTest extends AuthTestCase
{
    private ArrayStore $cache;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cache = new ArrayStore();
        $this->cms->container->instance(Store::class, $this->cache);
        $this->cms->notifications->wire(fn() => $this->cms->queue, $this->cms->events);
        (new NotificationsModule())->boot($this->cms);
    }

    public function testRegistryOrderFailuresOwnershipAndCache(): void
    {
        $h = $this->cms->health;
        $h->setCache(fn() => $this->cache);
        $runs = 0;
        $h->check('core.a', 'A', function () use (&$runs) {
            $runs++;
            return HealthResult::good('fine');
        });
        $h->check('core.b', 'B', static fn() => HealthResult::recommended('meh', ['x' => 1], ['Fix', '/admin/x']), 'security');
        $h->check('core.c', 'C', static fn() => throw new \RuntimeException('boom'), 'reliability');
        $h->check('core.d', 'D', static fn() => 'not a result');

        $r = $h->run();
        $this->assertSame('critical', $r['status']);
        $this->assertSame(['good' => 1, 'recommended' => 1, 'critical' => 2], $r['counts']);
        $this->assertSame(['core.d', 'core.c', 'core.b', 'core.a'], array_column($r['checks'], 'id'), 'worst first, then by category');
        $this->assertSame('The check itself failed: boom', $r['checks'][1]['message']);
        $this->assertSame(['label' => 'Fix', 'url' => '/admin/x'], $r['checks'][2]['action']);

        $h->run();
        $this->assertSame(1, $runs, 'cached');
        $h->run(fresh: true);
        $this->assertSame(2, $runs);
        $this->assertSame(['core.a'], array_column($h->run(only: ['core.a'])['checks'], 'id'));

        ExecutionContext::run('plugin:acme', fn() => $h->check('acme.backup', 'Backup', static fn() => HealthResult::good(ExecutionContext::current())));
        $this->assertSame('plugin:acme', array_values(array_filter($h->run(fresh: true)['checks'], static fn($c) => $c['id'] === 'acme.backup'))[0]['message'], 'runs as its owner');
        $this->assertThrows(\InvalidArgumentException::class, fn() => ExecutionContext::run('plugin:acme', fn() => $h->check('core.x', 'X', static fn() => HealthResult::good(''))));
        $this->assertThrows(\InvalidArgumentException::class, fn() => ExecutionContext::run('plugin:evil', fn() => $h->check('acme.backup', 'X', static fn() => HealthResult::good(''))));
        $this->assertThrows(\InvalidArgumentException::class, fn() => $h->check('core.y', 'Y', static fn() => HealthResult::good(''), 'vibes'));
    }

    public function testCoreChecks(): void
    {
        (new HealthModule())->boot($this->cms);
        $only = ['core.app-key', 'core.https', 'core.debug', 'core.queue', 'core.scheduler', 'core.database', 'core.writable', 'core.php-limits'];
        $status = fn() => array_column($this->cms->health->run(fresh: true, only: $only)['checks'], 'status', 'id');

        $this->cms->config->set('app.url', 'http://shop.example');
        $this->cms->config->set('app.debug', true);
        $s = $status();
        $this->assertSame('good', $s['core.app-key']);
        $this->assertSame('critical', $s['core.https']);
        $this->assertSame('critical', $s['core.debug']);
        $this->assertSame('critical', $s['core.scheduler'], 'never ran');
        $this->assertSame('good', $s['core.queue'], 'sync driver');
        $this->assertSame('good', $s['core.database']);

        $this->cms->config->set('app.url', 'http://kestello.test');
        $this->cache->put('schedule:heartbeat', ['at' => time() - 30, 'via' => 'cron'], 60);
        $s = $status();
        $this->assertSame('recommended', $s['core.https'], 'local development address');
        $this->assertSame('recommended', $s['core.debug']);
        $this->assertSame('good', $s['core.scheduler']);

        $queue = new ArrayQueue();
        $this->cms->queue->setBackend(static fn() => $queue);
        $queue->push('default', '{}');
        $this->db->table('failed_jobs')->insert(['uuid' => 'u1', 'queue' => 'default', 'job' => 'X', 'owner' => 'core', 'payload' => '{}', 'exception' => 'e', 'attempts' => 3, 'failed_at' => time()]);
        $this->assertSame('recommended', $status()['core.queue'], 'failed jobs');
    }

    public function testAdministratorsHearAboutProblemsAndChanges(): void
    {
        $admin = $this->createUser();
        $this->createUser(['username' => 'bo', 'email' => 'bo@example.test', 'role' => 'user']);
        (new HealthModule())->boot($this->cms);
        $broken = true;
        $this->cms->health->check('core.zz-test', 'Test check', static function () use (&$broken) {
            return $broken ? HealthResult::critical('It broke.') : HealthResult::good('ok');
        });
        $this->cms->config->set('app.url', 'https://kestello.example');

        $this->assertContains('Alerted about', HealthModule::alert($this->cms));
        $mine = fn() => $this->db->table('notifications')->where('user_id', $admin['id'])->where('type', 'site-health')->count();
        $this->assertSame(1, $mine());
        $this->assertSame(0, $this->db->table('notifications')->where('user_id', '!=', $admin['id'])->count(), 'only administrators');
        $this->assertContains('Test check: It broke.', implode("\n", array_column($this->mail, 'html')));

        HealthModule::alert($this->cms);
        $this->assertSame(1, $mine(), 'no repeat for a known problem');
        $broken = false;
        HealthModule::alert($this->cms);
        $broken = true;
        HealthModule::alert($this->cms);
        $this->assertSame(2, $mine(), 'alerts again after it recovered and broke again');

        $this->cms->notifications->setPreference((int) $admin['id'], 'platform-changed', 'mail', false);
        $this->mail = [];
        $this->cms->events->dispatch(new PlatformChanged('cache', 'file', 'redis', ['host'], (int) $admin['id']));
        $this->assertSame(1, $this->db->table('notifications')->where('type', 'platform-changed')->count());
        $this->assertCount(1, $this->mail, 'mandatory: preferences cannot silence it');
        $this->assertContains('ana changed the cache settings (file to redis).', $this->mail[0]['html']);
    }

    public function testSystemApi(): void
    {
        $this->createUser();
        $this->createUser(['username' => 'bo', 'email' => 'bo@example.test', 'role' => 'user']);
        $system = new SystemModule();
        $system->register($this->cms->container);
        $system->boot($this->cms);
        (new HealthModule())->boot($this->cms);
        $this->bindPlatform();
        $queue = new ArrayQueue();
        $this->cms->queue->setBackend(static fn() => $queue);

        $this->api('POST', '/auth/session', ['login' => 'bo', 'password' => self::PASSWORD]);
        $this->assertSame(403, $this->api('GET', '/system/health')->statusCode(), 'users cannot see system screens');
        $this->api('DELETE', '/auth/session');
        $this->api('POST', '/auth/session', ['login' => 'ana', 'password' => self::PASSWORD]);

        $res = $this->api('GET', '/system/health?refresh=1');
        $this->assertSame(200, $res->statusCode(), $res->content());
        $health = $this->json($res)['data'];
        $this->assertContains('core.database', array_column($health['checks'], 'id'));
        $res = $this->api('GET', '/system/info');
        $this->assertSame(200, $res->statusCode(), $res->content());
        $info = $this->json($res)['data'];
        $this->assertSame(PHP_VERSION, $info['php']['version']);
        $this->assertNotContains('secret', json_encode($info));

        // Platform: list, test, update (secrets never echoed), reset.
        $list = $this->json($this->api('GET', '/system/platform'))['data'];
        $this->assertSame('memory', $list[0]['driver']);
        $this->assertFalse($this->json($this->api('POST', '/system/platform/kv/test', ['driver' => 'remote', 'options' => ['host' => 'down']]))['data']['ok']);
        $this->assertTrue($this->json($this->api('POST', '/system/platform/kv/test', ['driver' => 'remote', 'options' => ['host' => 'up', 'password' => 'hunter2']]))['data']['ok']);
        $bad = $this->api('PUT', '/system/platform/kv', ['driver' => 'remote', 'options' => ['host' => 'down']]);
        $this->assertSame(422, $bad->statusCode(), $bad->content());
        $saved = $this->api('PUT', '/system/platform/kv', ['driver' => 'remote', 'options' => ['host' => 'up', 'password' => 'hunter2']]);
        $this->assertSame(200, $saved->statusCode(), $saved->content());
        $this->assertNotContains('hunter2', $saved->content());
        $this->assertSame(['set' => true], $this->json($saved)['data']['options']['password']);
        $this->assertSame(404, $this->api('PUT', '/system/platform/nope', ['driver' => 'x'])->statusCode());
        $this->assertSame(422, $this->api('PUT', '/system/platform/kv', ['driver' => 'remote', 'options' => ['host' => ['nested']]])->statusCode());
        $this->assertSame('memory', $this->json($this->api('DELETE', '/system/platform/kv'))['data']['driver']);
        $this->assertContains('platform.changed', $this->auditEvents());

        // Failed jobs.
        foreach (['a', 'b', 'c'] as $u) {
            $this->db->table('failed_jobs')->insert(['uuid' => $u, 'queue' => 'mail', 'job' => 'App\\Job', 'owner' => 'core', 'payload' => '{"job":"App\\\\Job","args":{"password":"p4ss","n":1}}', 'exception' => "RuntimeException: nope\n#0 trace", 'attempts' => 3, 'failed_at' => time()]);
        }
        $failed = $this->json($this->api('GET', '/system/failed-jobs?limit=2'));
        $this->assertCount(2, $failed['data']);
        $this->assertSame(3, $failed['meta']['total']);
        $this->assertTrue($failed['data'][0]['args']['password'] !== 'p4ss', 'secret-looking args are masked');
        $this->assertSame(['retried' => 1], $this->json($this->api('POST', '/system/failed-jobs/' . $failed['data'][0]['id'] . '/retry'))['data']);
        $this->assertCount(1, $queue->jobs['mail'] ?? $queue->jobs);
        $this->assertSame(['deleted' => 2], $this->json($this->api('DELETE', '/system/failed-jobs/all'))['data']);
        $this->assertSame(404, $this->api('DELETE', '/system/failed-jobs/999')->statusCode());
        $this->assertContains('queue.retried', $this->auditEvents());

        // Scheduler.
        $tasks = array_column($this->json($this->api('GET', '/system/schedule'))['data'], null, 'name');
        $this->assertTrue(isset($tasks['health.check']));
        $this->assertNull($tasks['health.check']['last']);
        $run = $this->json($this->api('POST', '/system/schedule/' . rawurlencode('health.check') . '/run'))['data'];
        $this->assertSame('ok', $run['status']);
        $this->assertSame(204, $this->api('PATCH', '/system/schedule/health.check', ['disabled' => true])->statusCode());
        $tasks = array_column($this->json($this->api('GET', '/system/schedule'))['data'], null, 'name');
        $this->assertTrue($tasks['health.check']['disabled']);
        $this->assertSame('ok', $tasks['health.check']['last']['status']);
        $this->assertSame(404, $this->api('POST', '/system/schedule/nope/run')->statusCode());
    }

    private function bindPlatform(): void
    {
        $drivers = $this->cms->drivers;
        $drivers->subsystem(new Subsystem('kv', 'Key-value', Store::class, default: 'memory', fallback: 'memory'));
        $drivers->register('kv', new DriverDefinition(name: 'memory', label: 'Memory', schema: Schema::object([]), factory: static fn() => new ArrayStore()));
        $drivers->register('kv', new DriverDefinition(
            name: 'remote',
            label: 'Remote',
            schema: Schema::object(['host' => Schema::string()->min(1), 'password' => Schema::string()->optional()]),
            factory: static fn(array $o) => $o['host'] === 'down' ? throw new \RuntimeException('connection refused') : new ArrayStore(),
            secrets: ['password'],
        ));
        $root = $this->tempDir();
        file_put_contents("{$root}/core.php", "<?php return ['kv' => ['driver' => 'memory']];");
        $config = new PlatformConfig(
            registry: $drivers,
            store: new ArrayPlatformStore(),
            secrets: new AppKeySecretStore(str_repeat('k', 32)),
            coreConfigFile: "{$root}/core.php",
            siteConfigFile: "{$root}/platform.php",
            compiledFile: "{$root}/compiled/platform.php",
            env: [],
        );
        $drivers->setConfigResolver($config->resolve(...));
        $this->cms->container->instance(PlatformManager::class, new PlatformManager($drivers, $config, $this->cms->events->dispatch(...)));
    }
}
