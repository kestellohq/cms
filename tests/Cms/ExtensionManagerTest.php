<?php

declare(strict_types=1);

namespace Kestello\Tests\Cms;

use Kestello\Cms\Cms;
use Kestello\Cms\Extension\ArrayStateStore;
use Kestello\Cms\Extension\ExtensionManager;
use Kestello\Cms\Extension\PackageState;
use Kestello\Cms\Extension\PackageType;
use Kestello\Cms\Extension\SuspensionList;
use Kestello\Cms\View\PageRegistry;
use Kestello\Cms\View\Pages;
use Kestello\Framework\Core\ExecutionContext;
use Kestello\Framework\Testing\TestCase;

final class ExtensionManagerTest extends TestCase
{
    private string $root;
    private string $ns;
    private ArrayStateStore $state;
    private SuspensionList $suspensions;
    private Cms $cms;

    protected function setUp(): void
    {
        $this->root = $this->tempDir();
        // Unique namespace per test so fixture classes never collide within one process.
        $this->ns = 'Fixture' . bin2hex(random_bytes(4));
        $this->state = new ArrayStateStore();
        $this->suspensions = new SuspensionList($this->root . '/storage/framework/suspended.json');

        $this->cms = \Kestello\Tests\Support\TestCms::make($this->root);
        // Activation runs plugin migrations, which need a connection.
        \Kestello\Framework\Database\DatabaseManager::setConnection(\Kestello\Framework\Database\Connection::sqliteMemory());

        $this->plugin('good', provider: <<<'PHP'
            public function register(\Kestello\Framework\Core\Container $c): void { $c->instance('good.registered', true); }
            public function boot(\Kestello\Cms\Cms $cms): void {
                $cms->routes->get('/good', fn() => 'good');
                $cms->filters->add('greeting', fn(string $v) => $v . ' from good');
            }
        PHP);
        $this->plugin('broken', provider: <<<'PHP'
            public function register(\Kestello\Framework\Core\Container $c): void { throw new \RuntimeException('kaboom'); }
        PHP);
        $this->plugin('needs-missing', dependencies: ['not-installed' => '^1.0']);
        $this->plugin('fails-boot', provider: <<<'PHP'
            public function boot(\Kestello\Cms\Cms $cms): void { throw new \LogicException('bad boot'); }
        PHP);

        $this->theme('base');
        $this->theme('child', parent: 'base');
        $this->theme('orphan', parent: 'gone');

        foreach (['good', 'broken', 'needs-missing', 'fails-boot'] as $slug) {
            $this->state->save(new PackageState(PackageType::Plugin, $slug, true, '1.0.0'));
        }
    }

    protected function tearDown(): void
    {
        ExecutionContext::reset();
        \Kestello\Framework\Database\DatabaseManager::reset();
    }

    private function manager(): ExtensionManager
    {
        return new ExtensionManager($this->cms, $this->state, $this->suspensions, '0.1.0');
    }

    public function testBootLoadsGoodPluginsAndContainsBrokenOnes(): void
    {
        $m = $this->manager();
        $m->boot();

        $this->assertSame(['good'], array_keys($m->activePlugins()));
        $this->assertTrue($this->cms->container->make('good.registered'));
        $this->assertSame('hi from good', $this->cms->filters->apply('greeting', 'hi'));

        // Broken in register() and boot(): suspended, with the reason recorded.
        $this->assertTrue($this->suspensions->isSuspended('plugin:broken'));
        $this->assertContains('kaboom', (string) $this->suspensions->reason('plugin:broken'));
        $this->assertTrue($this->suspensions->isSuspended('plugin:fails-boot'));

        // Unmet dependency: not loaded, not suspended (nothing crashed).
        $this->assertContains('not-installed', implode(' ', $m->rejectedPlugins()['needs-missing']));
        $this->assertFalse($this->suspensions->isSuspended('plugin:needs-missing'));

        // Routes remember who registered them.
        $this->assertSame('plugin:good', $this->cms->routes->routes()[0]->owner);
    }

    public function testSuspendedPluginsStayOffUntilCleared(): void
    {
        $this->manager()->boot();
        $second = $this->manager();
        $second->boot();

        $this->assertContains('suspended', implode(' ', $second->rejectedPlugins()['broken']));
    }

    public function testRecoveryModeSkipsAllPlugins(): void
    {
        $m = $this->manager();
        $m->boot(recovery: true);

        $this->assertSame([], $m->activePlugins());
        $this->assertTrue($m->isRecoveryMode());
        $this->assertSame('base', $m->activeTheme()?->slug());
    }

    public function testChildThemeChainAndPagePrecedence(): void
    {
        $this->state->save(new PackageState(PackageType::Theme, 'child', true, '1.0.0'));
        $this->build('themes/base', ['pages/Page', 'pages/OnlyBase', 'layouts/Site']);
        $this->build('themes/child', ['pages/Page', 'pages/@good/Widget', 'pages/@core/auth/Login']);
        $this->build('plugins/good', ['pages/Widget', 'pages/Page', 'layouts/Site']);

        $m = $this->manager();
        $m->boot();

        $this->assertSame(['child', 'base'], array_map(fn($t) => $t->slug(), $m->themeChain()));

        $registry = new PageRegistry($this->cms->paths, $m, '0.1.0');
        $from = fn(string $name): ?string => preg_match('#^/assets/(?:themes|plugins)/([a-z-]+)/#', $registry->get($name)['url'] ?? '', $x) === 1 ? $x[1] : null;

        $this->assertSame('child', $from('Page'), 'child theme beats parent');
        $this->assertSame('base', $from('OnlyBase'), 'parent pages are inherited');
        $this->assertSame('base', $from('layouts/Site'), 'plugin layouts never become global');
        $this->assertSame('child', $from('@good/Widget'), 'themes override plugin pages');
        $this->assertSame('good', $from('@good/Page'), 'plugin pages are namespaced, so they cannot hijack theme pages');
        $this->assertSame('good', $from('@good/layouts/Site'));
        $this->assertSame('child', $from('@core/auth/Login'), 'themes override core pages');
        $this->assertFalse($registry->has('Widget'));
    }

    /**
     * Fake Vite output: a manifest with one entry per source.
     *
     * @param list<string> $sources e.g. "pages/Home"
     */
    private function build(string $package, array $sources): void
    {
        $dir = "{$this->root}/content/{$package}/assets/build/.vite";
        @mkdir($dir, 0777, true);
        $manifest = [];
        foreach ($sources as $src) {
            $manifest["resources/js/{$src}.vue"] = ['file' => "{$src}-abc.js", 'isEntry' => true, 'src' => "resources/js/{$src}.vue"];
        }
        file_put_contents("{$dir}/manifest.json", json_encode($manifest));
    }

    public function testThemeWithMissingParentFallsBackToBase(): void
    {
        $this->state->save(new PackageState(PackageType::Theme, 'orphan', true, '1.0.0'));

        $m = $this->manager();
        $m->boot();

        $this->assertSame('base', $m->activeTheme()?->slug());
    }

    public function testActivationRefusesPluginsThatCannotBoot(): void
    {
        $this->state->save(new PackageState(PackageType::Plugin, 'fails-boot', false, '1.0.0'));
        $m = $this->manager();
        $m->boot();

        $e = $this->assertThrows(\RuntimeException::class, fn() => $m->activatePlugin('fails-boot'));
        $this->assertContains('failed to boot: bad boot', $e->getMessage());
        $this->assertFalse($this->state->get(PackageType::Plugin, 'fails-boot')->active);
    }

    /** Plugins → Activate on a suspended plugin: it comes back once it boots, and stays suspended while it fails. */
    public function testActivatingASuspendedPluginTriesItAgain(): void
    {
        $this->suspensions->suspend('plugin:good', 'crashed once');
        $m = $this->manager();
        $m->boot();
        $this->assertSame(['suspended: crashed once'], $m->rejectedPlugins()['good']);
        $this->assertTrue($this->suspensions->isSuspended('plugin:fails-boot'), 'suspended by this boot');

        $m->activatePlugin('good');
        $this->assertFalse($this->suspensions->isSuspended('plugin:good'));
        $this->assertTrue($this->state->get(PackageType::Plugin, 'good')->active);

        $e = $this->assertThrows(\RuntimeException::class, fn() => $m->activatePlugin('fails-boot'));
        $this->assertContains('failed to boot: bad boot', $e->getMessage());
        $this->assertTrue($this->suspensions->isSuspended('plugin:fails-boot'), 'still suspended');

        $next = $this->manager();
        $next->boot();
        $this->assertContains('good', array_keys($next->activePlugins()));
    }

    /**
     * Activation boots the plugin into a request whose content types are
     * already final: its types are checked there (a clash is refused) and
     * declared for real on the next request.
     */
    public function testActivationChecksContentTypesInARunningRequest(): void
    {
        $this->plugin('shop', provider: <<<'PHP'
            public function boot(\Kestello\Cms\Cms $cms): void {
                $cms->content->type('shop-item')->label('Item', 'Items')->path('/items')
                    ->fields([\Kestello\Cms\Content\Field::number('price')->indexed()]);
            }
        PHP);
        $this->plugin('clash', provider: <<<'PHP'
            public function boot(\Kestello\Cms\Cms $cms): void {
                $cms->content->type('clash')->label('Clash', 'Clashes')->path('/items');
            }
        PHP);
        $m = $this->manager();
        $m->boot();
        $this->cms->content->finalize($this->cms->auth->roles);

        $m->activatePlugin('shop');
        $this->assertTrue($this->state->get(PackageType::Plugin, 'shop')->active);
        $this->assertFalse($this->cms->content->has('shop-item'), 'declared on the next request, not twice in this one');

        $next = \Kestello\Tests\Support\TestCms::make($this->root);
        $m2 = new ExtensionManager($next, $this->state, $this->suspensions, '0.1.0');
        $m2->boot();
        $next->content->finalize($next->auth->roles);
        $this->assertTrue($next->content->has('shop-item'));

        $e = $this->assertThrows(\RuntimeException::class, fn() => $m2->activatePlugin('clash'));
        $this->assertContains('share the path "/items"', $e->getMessage());
        $this->assertFalse($this->state->get(PackageType::Plugin, 'clash')?->active ?? false, 'refused, not activated to break the next request');
        $this->assertFalse($next->content->has('clash'));
    }

    // ── fixtures ────────────────────────────────────────────────────

    /** @param array<string, string> $dependencies */
    private function plugin(string $slug, ?string $provider = null, array $dependencies = []): void
    {
        $dir = "{$this->root}/content/plugins/{$slug}";
        mkdir("$dir/src", 0777, true);

        $class = str_replace(' ', '', ucwords(str_replace('-', ' ', $slug))) . 'Provider';
        $namespace = "{$this->ns}\\" . str_replace('-', '', ucwords($slug, '-'));

        $manifest = [
            'slug' => $slug, 'name' => $slug, 'version' => '1.0.0',
            'requires' => ['core' => '^0.1'],
            'namespace' => $namespace . '\\',
            'dependencies' => (object) $dependencies,
        ];

        if ($provider !== null) {
            $manifest['provider'] = "{$namespace}\\{$class}";
            file_put_contents("$dir/src/{$class}.php", "<?php\nnamespace {$namespace};\nfinal class {$class} extends \\Kestello\\Cms\\Extension\\PluginProvider {\n{$provider}\n}\n");
        }

        file_put_contents("$dir/plugin.json", json_encode($manifest));
    }

    private function theme(string $slug, ?string $parent = null): void
    {
        $dir = "{$this->root}/content/themes/{$slug}";
        mkdir($dir, 0777, true);
        file_put_contents("$dir/theme.json", json_encode(array_filter([
            'slug' => $slug, 'name' => $slug, 'version' => '1.0.0',
            'requires' => ['core' => '^0.1'], 'parent' => $parent,
        ])));
    }
}
