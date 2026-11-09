<?php

declare(strict_types=1);

namespace Kestello\Tests\Cms;

use InvalidArgumentException;
use Kestello\Cms\Api\Schema;
use Kestello\Cms\Platform\ArrayPlatformStore;
use Kestello\Cms\Platform\DriverDefinition;
use Kestello\Cms\Platform\DriverRegistry;
use Kestello\Cms\Platform\PlatformChanged;
use Kestello\Cms\Platform\PlatformConfig;
use Kestello\Cms\Platform\PlatformException;
use Kestello\Cms\Platform\PlatformManager;
use Kestello\Cms\Platform\Subsystem;
use Kestello\Contracts\Cache\Store;
use Kestello\Framework\Cache\ArrayStore;
use Kestello\Framework\Core\Container;
use Kestello\Framework\Security\AppKeySecretStore;
use Kestello\Framework\Testing\TestCase;
use RuntimeException;

final class PlatformTest extends TestCase
{
    /** Long and unlikely, so "no plaintext" checks never match random sealed bytes by chance (as "pw1" once did). */
    private const string SECRET = 'plaintext-secret-7f3a9c';

    private const string KEY = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';

    private string $root;
    private DriverRegistry $registry;
    private ArrayPlatformStore $store;
    private AppKeySecretStore $secrets;
    /** @var list<object> */
    private array $events = [];
    /** @var list<array<string, mixed>> options each "remote" instance was built with */
    private array $built = [];

    protected function setUp(): void
    {
        $this->root = $this->tempDir();
        mkdir($this->root . '/config', 0777, true);
        file_put_contents($this->root . '/core.php', "<?php return ['cache' => ['driver' => 'local']];");

        $this->registry = new DriverRegistry(new Container(), static fn() => null);
        $this->registry->subsystem(new Subsystem('cache', 'Cache', Store::class, default: 'local', fallback: 'local'));
        $this->registry->register('cache', new DriverDefinition(
            name: 'local',
            label: 'Local',
            schema: Schema::object([]),
            factory: static fn() => new ArrayStore(),
        ));
        $this->registry->register('cache', new DriverDefinition(
            name: 'remote',
            label: 'Remote',
            schema: Schema::object([
                'host' => Schema::string()->min(1),
                'port' => Schema::int()->min(1)->max(65535)->default(6379),
                'password' => Schema::string()->optional(),
                'prefix' => Schema::string()->default('t:'),
            ]),
            factory: function (array $o): Store {
                $this->built[] = $o;
                if ($o['host'] === 'down') {
                    throw new RuntimeException('connection refused');
                }
                return new ArrayStore();
            },
            secrets: ['password'],
            health: static function (Store $c): void {
                // health inspects nothing here; failures come from the factory
            },
        ));

        $this->store = new ArrayPlatformStore();
        $this->secrets = new AppKeySecretStore(self::KEY);
    }

    /** @param array<string, string> $env */
    private function manager(array $env = [], ?string $siteFile = null): PlatformManager
    {
        if ($siteFile !== null) {
            file_put_contents($this->root . '/config/platform.php', $siteFile);
        }
        $config = new PlatformConfig(
            registry: $this->registry,
            store: $this->store,
            secrets: $this->secrets,
            coreConfigFile: $this->root . '/core.php',
            siteConfigFile: $this->root . '/config/platform.php',
            compiledFile: $this->root . '/storage/framework/platform.php',
            env: $env,
        );
        $this->registry->setConfigResolver($config->resolve(...));

        return new PlatformManager($this->registry, $config, function (object $e): void {
            $this->events[] = $e;
        });
    }

    // ── Secret store ────────────────────────────────────────────────

    public function testSecretsRoundTripAndAreBoundToContext(): void
    {
        $sealed = $this->secrets->seal('hunter2', 'platform.cache.password');

        $this->assertTrue($this->secrets->isSealed($sealed));
        $this->assertFalse(str_contains($sealed, 'hunter2'));
        $this->assertSame('hunter2', $this->secrets->open($sealed, 'platform.cache.password'));
        $this->assertTrue($sealed !== $this->secrets->seal('hunter2', 'platform.cache.password'), 'random nonce');

        $this->assertThrows(RuntimeException::class, fn() => $this->secrets->open($sealed, 'platform.mail.password'), 'other context');
        $tampered = substr($sealed, 0, -2) . (str_ends_with($sealed, 'A') ? 'BB' : 'AA');
        $this->assertThrows(RuntimeException::class, fn() => $this->secrets->open($tampered, 'platform.cache.password'), 'tampered');
        $this->assertThrows(InvalidArgumentException::class, fn() => new AppKeySecretStore('short'));
    }

    public function testRotatedKeysStillOpenOldSecrets(): void
    {
        $sealed = $this->secrets->seal('s3cret', 'ctx');
        $newKey = bin2hex(random_bytes(32));

        $this->assertThrows(RuntimeException::class, fn() => (new AppKeySecretStore($newKey))->open($sealed, 'ctx'));

        $rotated = new AppKeySecretStore($newKey, [self::KEY]);
        $this->assertSame('s3cret', $rotated->open($sealed, 'ctx'));
        $this->assertFalse($rotated->isCurrent($sealed));
        $resealed = $rotated->reseal($sealed, 'ctx');
        $this->assertTrue($rotated->isCurrent($resealed));
        $this->assertSame('s3cret', (new AppKeySecretStore($newKey))->open($resealed, 'ctx'));
    }

    // ── Registry ────────────────────────────────────────────────────

    public function testPluginDriverNamesAreNamespacedAndCannotReplaceCore(): void
    {
        $def = fn(string $name) => new DriverDefinition($name, $name, Schema::object([]), static fn() => new ArrayStore());

        $this->registry->register('cache', $def('acme'), owner: 'acme');
        $this->registry->register('cache', $def('acme-fast'), owner: 'acme');
        $this->assertThrows(InvalidArgumentException::class, fn() => $this->registry->register('cache', $def('redis'), owner: 'acme'));
        $this->assertThrows(InvalidArgumentException::class, fn() => $this->registry->register('cache', $def('local'), owner: 'core'), 'no replacing');
        $this->assertThrows(InvalidArgumentException::class, fn() => $this->registry->register('cache', $def('Bad Name')));
        $this->assertThrows(InvalidArgumentException::class, fn() => $this->registry->register('nope', $def('x')));
        $this->assertThrows(InvalidArgumentException::class, fn() => new DriverDefinition('x', 'x', Schema::object([]), static fn() => null, secrets: ['missing']));
    }

    public function testDriverMustImplementTheContractAndRequirementsAreChecked(): void
    {
        $this->registry->register('cache', new DriverDefinition('wrong', 'Wrong', Schema::object([]), static fn() => new \stdClass()));
        $this->registry->register('cache', new DriverDefinition('needs-ext', 'Ext', Schema::object([]), static fn() => new ArrayStore(), requires: ['ext' => ['kestello_nonexistent']]));

        $this->assertThrows(PlatformException::class, fn() => $this->registry->make('cache', 'wrong', []));
        $this->assertThrows(PlatformException::class, fn() => $this->registry->make('cache', 'needs-ext', []));
        $this->assertContains('kestello_nonexistent', (string) $this->registry->driver('cache', 'needs-ext')->unavailableReason());
    }

    // ── Layers ──────────────────────────────────────────────────────

    public function testLayerPrecedenceAndSources(): void
    {
        $this->store->save('cache', ['driver' => 'remote', 'options' => ['host' => 'admin-host', 'prefix' => 'admin:']]);
        $manager = $this->manager(
            env: ['KESTELLO_CACHE_PORT' => '7000'],
            siteFile: "<?php return ['cache' => ['driver' => 'remote', 'prefix' => 'file:']];",
        );

        $e = $manager->config()->effective('cache');
        $this->assertSame('remote', $e['driver']);
        $this->assertSame('file', $e['driverSource']);
        $this->assertSame(['host' => 'admin-host', 'port' => 7000, 'prefix' => 'file:'], $e['options'], 'env coerced to int');
        $this->assertSame(['host' => 'admin', 'prefix' => 'file', 'port' => 'env'], $e['sources']);
        $this->assertSame(['driver', 'prefix', 'port'], $manager->config()->lockedKeys('cache'));
    }

    public function testLayerForAnotherDriverContributesNothing(): void
    {
        $this->store->save('cache', ['driver' => 'remote', 'options' => ['host' => 'admin-host']]);
        $manager = $this->manager(env: ['KESTELLO_CACHE_DRIVER' => 'local']);

        $e = $manager->config()->effective('cache');
        $this->assertSame(['local', 'env', []], [$e['driver'], $e['driverSource'], $e['options']]);
    }

    public function testHostLockMakesEverythingReadOnly(): void
    {
        $manager = $this->manager(env: ['KESTELLO_PLATFORM_LOCKED' => '1']);
        $this->assertTrue($manager->config()->isLocked());
        $this->assertThrows(PlatformException::class, fn() => $manager->update('cache', 'remote', ['host' => 'h']));

        $this->assertTrue($this->manager(siteFile: "<?php return ['admin_editable' => false];")->config()->isLocked());
    }

    // ── Updates ─────────────────────────────────────────────────────

    public function testUpdateValidatesTestsSealsAndHidesSecrets(): void
    {
        $manager = $this->manager();

        $invalid = $this->assertThrows(PlatformException::class, fn() => $manager->update('cache', 'remote', ['port' => 'x']));
        $this->assertTrue(isset($invalid->errors['host']), 'required host');

        $this->assertThrows(PlatformException::class, fn() => $manager->update('cache', 'remote', ['host' => 'down']), 'failing test blocks save');
        $this->assertNull($this->store->load()['cache'] ?? null);

        $manager->update('cache', 'remote', ['host' => 'h1', 'password' => self::SECRET], userId: 7);

        $stored = $this->store->load()['cache'];
        $this->assertSame('remote', $stored['driver']);
        $this->assertTrue($this->secrets->isSealed($stored['options']['password']), 'sealed at rest');
        $this->assertFalse(isset($stored['options']['port']), 'defaults are not stored');
        $this->assertFalse(str_contains((string) file_get_contents($this->root . '/storage/framework/platform.php'), self::SECRET), 'compiled file has no plaintext');

        $described = $manager->describe('cache');
        $this->assertSame(['set' => true], $described['options']['password']);
        $this->assertFalse(str_contains(json_encode($described), self::SECRET));

        $event = end($this->events);
        $this->assertInstanceOf(PlatformChanged::class, $event);
        $this->assertSame(['local', 'remote', 7], [$event->previousDriver, $event->driver, $event->userId]);

        // The resolved driver gets the decrypted secret.
        $this->built = [];
        $this->registry->resolve('cache');
        $this->assertSame(self::SECRET, $this->built[0]['password']);
    }

    public function testBlankSecretKeepsTheStoredOne(): void
    {
        $manager = $this->manager();
        $manager->update('cache', 'remote', ['host' => 'h1', 'password' => self::SECRET]);
        $sealed = $this->store->load()['cache']['options']['password'];

        $manager->update('cache', 'remote', ['host' => 'h2', 'password' => '']);
        $this->assertSame($sealed, $this->store->load()['cache']['options']['password'], 'same ciphertext');
        $this->assertSame(['host'], end($this->events)->changed);
        $this->assertSame(self::SECRET, $manager->config()->effective('cache')['options']['password']);
    }

    public function testPinnedKeysCannotBeChangedButAreUsedForTesting(): void
    {
        $manager = $this->manager(siteFile: "<?php return ['cache' => ['driver' => 'remote', 'host' => 'pinned']];");

        $e = $this->assertThrows(PlatformException::class, fn() => $manager->update('cache', 'remote', ['host' => 'other']));
        $this->assertTrue(isset($e->errors['host']));
        $this->assertThrows(PlatformException::class, fn() => $manager->update('cache', 'local', []), 'driver pinned');

        $manager->update('cache', 'remote', ['prefix' => 'p:']);
        $this->assertSame(['prefix' => 'p:'], $this->store->load()['cache']['options'], 'pinned host not copied into the admin layer');
        $this->assertSame('pinned', $manager->config()->effective('cache')['options']['host']);
    }

    public function testBrokenConfigFallsBackInsteadOfFailing(): void
    {
        $this->store->save('cache', ['driver' => 'remote', 'options' => ['host' => 'down']]);
        $manager = $this->manager();

        $this->assertInstanceOf(ArrayStore::class, $this->registry->resolve('cache'));
        $this->assertSame('local', $this->registry->degraded()['cache']['driver']);
        $this->assertSame('local', $manager->describe('cache')['degraded']['driver']);

        // An undecryptable secret is reported, not fatal.
        $this->store->save('cache', ['driver' => 'remote', 'options' => ['host' => 'h', 'password' => 'kst1:deadbeef:AAAA']]);
        $this->registry->forget();
        $manager = $this->manager();
        $manager->config()->compile(); // the store was written behind its back
        $this->assertTrue(isset($manager->config()->effective('cache')['errors']['password']));
        $this->assertInstanceOf(ArrayStore::class, $this->registry->resolve('cache'));
    }

    public function testResetAndCompiledFileRecovery(): void
    {
        $manager = $this->manager();
        $manager->update('cache', 'remote', ['host' => 'h1']);
        $compiled = $this->root . '/storage/framework/platform.php';
        $this->assertTrue(is_file($compiled));

        // A corrupt compiled file is rebuilt from the store.
        file_put_contents($compiled, '<?php this is not php');
        $this->assertSame('remote', $this->manager()->config()->effective('cache')['driver']);
        $this->assertSame('remote', (require $compiled)['cache']['driver']);

        $manager = $this->manager();
        $manager->reset('cache');
        $this->assertSame('local', $manager->config()->effective('cache')['driver']);
        $this->assertTrue(end($this->events)->reset);
        $this->assertSame([], require $compiled);
    }
}
