<?php

declare(strict_types=1);

namespace Kestello\Tests\Package;

use Kestello\Cms\Backup\BackupModule;
use Kestello\Cms\Content\ContentException;
use Kestello\Cms\Extension\ArrayStateStore;
use Kestello\Cms\Extension\ExtensionManager;
use Kestello\Cms\Extension\SuspensionList;
use Kestello\Cms\Package\PackageBuilder;
use Kestello\Cms\Package\PackageInstaller;
use Kestello\Cms\Package\PackageSignature;
use Kestello\Cms\Package\PackagesModule;
use Kestello\Framework\Core\Request;
use Kestello\Framework\Core\Response;
use Kestello\Support\Zip\Zip;
use Kestello\Tests\Auth\AuthTestCase;

/** Signed package zips and the Plugins screen's API. */
final class PackagesTest extends AuthTestCase
{
    private string $root;
    private string $src;
    /** @var array{id: string, public_key: string, secret_key: string} */
    private array $keys;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = $this->cms->paths->root();
        $extensions = new ExtensionManager($this->cms, new ArrayStateStore(), new SuspensionList($this->root . '/suspended.json'), '0.1.0');
        $extensions->boot();
        $this->cms->setExtensions($extensions);
        $this->cms->storage->configure($this->root . '/storage/app', []);
        foreach ([new BackupModule(), new PackagesModule()] as $module) {
            $module->register($this->cms->container);
            $module->boot($this->cms);
        }

        $this->keys = PackageSignature::generateKeys();
        $this->src = $this->root . '/src/stock';
        $this->plugin($this->src, 'stock', '1.0.0');
        $this->createUser();
        $this->assertSame(200, $this->api('POST', '/auth/session', ['login' => 'ana', 'password' => self::PASSWORD])->statusCode());
    }

    private function plugin(string $dir, string $slug, string $version, string $extra = ''): void
    {
        @mkdir($dir . '/database/migrations', 0777, true);
        file_put_contents($dir . '/plugin.json', json_encode(['slug' => $slug, 'name' => ucfirst($slug), 'version' => $version, 'requires' => ['core' => '^0.1'], 'author' => ['name' => 'Acme'], 'permissions' => ['http' => ['api.example.test']]]));
        file_put_contents($dir . '/database/migrations/2026_01_01_000001_items.php', <<<PHP
        <?php
        use Kestello\\Framework\\Database\\Blueprint;
        use Kestello\\Framework\\Database\\Migration;
        return new class extends Migration {
            public function up(): void { \$this->schema()->create('p_{$slug}_items', fn(Blueprint \$t) => \$t->id()); }
            public function down(): void { \$this->schema()->dropIfExists('p_{$slug}_items'); }
        };
        PHP);
        file_put_contents($dir . '/notes.php', "<?php\n// {$version}\n{$extra}\n");
    }

    private function built(bool $sign = true): string
    {
        $zip = PackageBuilder::build($this->src, $this->root . '/dist')['zip'];
        if ($sign) {
            PackageSignature::sign($zip, $this->keys['secret_key']);
        }

        return $zip;
    }

    private function trust(): void
    {
        $this->cms->config->set('app.packages.trusted_keys', [['name' => 'Acme Plugins', 'public_key' => $this->keys['public_key']]]);
    }

    private function upload(string $zip): Response
    {
        $copy = $this->root . '/upload-' . bin2hex(random_bytes(3)) . '.zip';
        copy($zip, $copy);
        $this->nextRequest();

        return $this->cms->routes->dispatch(new Request(
            'POST',
            '/api/v1/plugins/upload',
            '/api/v1/plugins/upload',
            [],
            [],
            [],
            [],
            ['file' => ['name' => basename($zip), 'type' => 'application/zip', 'tmp_name' => $copy, 'error' => UPLOAD_ERR_OK, 'size' => filesize($copy)]],
            ['REMOTE_ADDR' => '203.0.113.7'],
            '',
        ));
    }

    /** @return array<string, mixed> */
    private function data(Response $r): array
    {
        return $this->json($r)['data'] ?? [];
    }

    /** Copy a zip with one entry changed, added or removed. */
    private function rewrite(string $zip, ?string $change = null, ?string $add = null, ?string $remove = null): string
    {
        $in = Zip::open($zip);
        $path = $zip . '.edited.zip';
        @unlink($path);
        $out = Zip::create($path);
        foreach ($in->entries() as $e) {
            if ($e->isDirectory || $e->name === $remove) {
                continue;
            }
            $body = $in->read($e->name);
            $out->addString($e->name, $e->name === $change ? $body . "// changed\n" : $body);
        }
        if ($add !== null) {
            $out->addString($add, "<?php\n");
        }
        $out->close();

        return $path;
    }

    public function testSignaturesCoverEveryFile(): void
    {
        $trusted = PackageSignature::trustedKeys([['name' => 'Acme Plugins', 'public_key' => $this->keys['public_key']]]);
        $unsigned = $this->built(false);
        $this->assertSame('unsigned', PackageSignature::verify(Zip::open($unsigned), $trusted)['status']);

        $zip = $this->built();
        $this->assertSame(['status' => 'signed', 'key' => $this->keys['id'], 'name' => 'Acme Plugins'], PackageSignature::verify(Zip::open($zip), $trusted));
        $this->assertSame('untrusted', PackageSignature::verify(Zip::open($zip), [])['status']);
        $this->assertSame('tampered', PackageSignature::verify(Zip::open($zip), $trusted, PackageSignature::CORE)['status'], 'a package signature is not a core one');
        // another key with the same id claim can't pass
        $other = PackageSignature::generateKeys();
        $this->assertSame('tampered', PackageSignature::verify(Zip::open($zip), [$this->keys['id'] => ['name' => 'Impostor', 'public_key' => $other['public_key']]])['status']);

        foreach ([['change' => 'stock/notes.php'], ['add' => 'stock/src/Backdoor.php'], ['remove' => 'stock/notes.php']] as $edit) {
            $edited = $this->rewrite($zip, ...$edit);
            $this->assertSame('tampered', PackageSignature::verify(Zip::open($edited), $trusted)['status'], json_encode($edit));
        }
        // built zips leave out dotfiles
        file_put_contents($this->src . '/.env', 'SECRET=1');
        $this->assertFalse(Zip::open($this->built())->has('stock/.env'));
        $this->assertTrue(is_file($zip . '.sha256'));
    }

    public function testTheAdminInstallsOnlySignedPackages(): void
    {
        // unsigned: reviewed, but not installable from the admin
        $review = $this->data($this->upload($this->built(false)));
        $this->assertSame('stock', $review['package']['slug']);
        $this->assertSame('unsigned', $review['package']['signature']['status']);
        $this->assertSame(['http' => ['api.example.test']], $review['package']['permissions']);
        $this->assertFalse($review['allowed']);
        $this->assertSame(403, $this->api('POST', '/plugins/install', ['token' => $review['token']])->statusCode());

        // signed by a key the site doesn't trust yet: same
        $review = $this->data($this->upload($this->built()));
        $this->assertSame('untrusted', $review['package']['signature']['status']);
        $this->assertSame(403, $this->api('POST', '/plugins/install', ['token' => $review['token']])->statusCode());

        // trusted: installs, activates, runs its migration
        $this->trust();
        $review = $this->data($this->upload($this->built()));
        $this->assertTrue($review['allowed']);
        $this->assertSame('Acme Plugins', $review['package']['signature']['name']);
        $done = $this->api('POST', '/plugins/install', ['token' => $review['token'], 'activate' => true]);
        $this->assertSame(200, $done->statusCode(), $done->content());
        $this->assertTrue($this->data($done)['activated']);
        $this->assertTrue($this->db->schema()->hasTable('p_stock_items'));
        $row = array_values(array_filter($this->data($this->api('GET', '/plugins'))['plugins'], static fn(array $p): bool => $p['slug'] === 'stock'))[0];
        $this->assertSame(['active', '1.0.0', 'Acme'], [$row['status'], $row['version'], $row['author']]);
        // a token is used once
        $this->assertSame(422, $this->api('POST', '/plugins/install', ['token' => $review['token']])->statusCode());

        // tampered: refused at install even when trusted
        $tampered = $this->rewrite($this->built(), change: 'stock/notes.php');
        $review = $this->data($this->upload($tampered));
        $this->assertSame('tampered', $review['package']['signature']['status']);
        $this->assertFalse($review['allowed']);

        // the server owner may allow unsigned packages (development sites)
        $this->cms->config->set('app.packages.admin_unsigned', true);
        $this->plugin($this->src, 'stock', '1.1.0');
        $review = $this->data($this->upload($this->built(false)));
        $this->assertTrue($review['allowed']);
        $this->assertSame(['version' => '1.0.0', 'active' => true], $review['package']['installed']);
        $this->assertSame(409, $this->api('POST', '/plugins/install', ['token' => $review['token'], 'replace' => true])->statusCode(), 'active plugins are replaced by the update pipeline (P3)');

        $this->assertSame(200, $this->api('POST', '/plugins/stock/deactivate')->statusCode());
        $review = $this->data($this->upload($this->built(false)));
        $this->assertSame(409, $this->api('POST', '/plugins/install', ['token' => $review['token']])->statusCode(), 'replacing needs replace=true');
        $review = $this->data($this->upload($this->built(false)));
        $replaced = $this->data($this->api('POST', '/plugins/install', ['token' => $review['token'], 'replace' => true]));
        $this->assertTrue($replaced['replaced']);
        $this->assertStringContainsString('1.1.0', (string) file_get_contents($this->root . '/content/plugins/stock/notes.php'));

        // deleting removes its tables and files
        $this->assertSame(200, $this->api('DELETE', '/plugins/stock')->statusCode());
        $this->assertFalse(is_dir($this->root . '/content/plugins/stock'));
        $this->assertFalse($this->db->schema()->hasTable('p_stock_items'));
        $this->assertSame(404, $this->api('DELETE', '/plugins/stock')->statusCode());
    }

    public function testSecurityShowsWhoSignedEachPackage(): void
    {
        $this->trust();
        $review = $this->data($this->upload($this->built()));
        $this->assertSame(200, $this->api('POST', '/plugins/install', ['token' => $review['token'], 'activate' => true])->statusCode());
        $keyId = $review['package']['signature']['key'];

        $trust = $this->data($this->api('GET', '/security/packages'));
        $this->assertFalse($trust['admin_unsigned']);
        $this->assertSame([['id' => $keyId, 'name' => 'Acme Plugins']], $trust['publishers']);
        $this->assertTrue(is_array($trust['release_keys']));
        $stock = array_values(array_filter($trust['packages'], static fn(array $p): bool => $p['slug'] === 'stock'))[0];
        $this->assertSame(['plugin', 'Stock', '1.0.0', true, [['id' => $keyId, 'name' => 'Acme Plugins']]], [$stock['type'], $stock['name'], $stock['version'], $stock['active'], $stock['signed_by']]);

        // the server owner stops trusting the publisher: the package still names the key, without a name
        $this->cms->config->set('app.packages.trusted_keys', []);
        $this->cms->config->set('app.packages.admin_unsigned', true);
        $trust = $this->data($this->api('GET', '/security/packages'));
        $this->assertTrue($trust['admin_unsigned']);
        $this->assertSame([], $trust['publishers']);
        $stock = array_values(array_filter($trust['packages'], static fn(array $p): bool => $p['slug'] === 'stock'))[0];
        $this->assertSame([['id' => $keyId, 'name' => null]], $stock['signed_by']);
    }

    public function testUnsafeOrWrongPackagesAreRefused(): void
    {
        $this->trust();
        $this->cms->config->set('app.packages.admin_unsigned', true);
        $installer = $this->cms->container->make(PackageInstaller::class);

        // plugin:check errors stop the install, signed or not
        $this->plugin($this->src, 'stock', '1.0.0', 'eval($_GET["x"] ?? "");');
        try {
            $this->built();
            $this->fail('package:build built a plugin with eval()');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('eval()', $e->getMessage());
        }
        $zip = $this->root . '/evil.zip';
        $w = Zip::create($zip);
        $w->addDirectory($this->src, 'stock');
        $w->close();
        PackageSignature::sign($zip, $this->keys['secret_key']);
        try {
            $installer->install($zip, ['allow_unsigned' => true]);
            $this->fail('a plugin with eval() was installed');
        } catch (ContentException $e) {
            $this->assertStringContainsString('eval()', implode(' ', $e->errors['file'] ?? []));
        }
        $this->assertFalse(is_dir($this->root . '/content/plugins/stock'));

        // a theme zip on the plugins screen, junk, no manifest
        mkdir($this->root . '/src/look', 0777, true);
        file_put_contents($this->root . '/src/look/theme.json', json_encode(['slug' => 'look', 'name' => 'Look', 'version' => '1.0.0', 'requires' => ['core' => '^0.1']]));
        $theme = PackageBuilder::build($this->root . '/src/look', $this->root . '/dist')['zip'];
        $this->assertSame(422, $this->upload($theme)->statusCode());
        file_put_contents($this->root . '/junk.zip', 'not a zip');
        $this->assertSame(422, $this->upload($this->root . '/junk.zip')->statusCode());
        $empty = Zip::create($this->root . '/empty.zip');
        $empty->addString('readme.txt', 'hi');
        $empty->close();
        $this->assertSame(422, $this->upload($this->root . '/empty.zip')->statusCode());

        // a root-level zip (no folder) works too, whatever the zip is called
        $this->plugin($this->src, 'stock', '1.0.0');
        $flat = Zip::create($this->root . '/whatever.zip');
        $flat->addDirectory($this->src);
        $flat->close();
        $this->assertSame('stock', $installer->inspect($this->root . '/whatever.zip')['slug']);

        // editors can't manage plugins
        $this->api('DELETE', '/auth/session');
        $this->createUser(['username' => 'ed', 'email' => 'ed@example.test', 'role' => 'editor']);
        $this->assertSame(200, $this->api('POST', '/auth/session', ['login' => 'ed', 'password' => self::PASSWORD])->statusCode());
        $this->assertSame(403, $this->api('GET', '/plugins')->statusCode());
    }
}
