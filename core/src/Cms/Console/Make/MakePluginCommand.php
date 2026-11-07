<?php

declare(strict_types=1);

namespace Kestello\Cms\Console\Make;

use Kestello\Framework\Console\Command;
use Kestello\Framework\Core\Paths;
use Kestello\Kernel;

/**
 * make:plugin <slug> [--name="Display Name"] [--namespace=Vendor\\Name] [--admin]
 *
 * --admin adds a working admin side built the API-first way: a capability,
 * a token scope, an endpoint, an admin page, a dashboard widget, a settings
 * tab, and a vite.config.js on the Kestello preset. Build it with
 * `npm run build content/plugins/<slug>`.
 */
final class MakePluginCommand extends Command
{
    protected string $name = 'make:plugin';
    protected string $description = 'Scaffold a new plugin in content/plugins/';

    public function __construct(
        private readonly Paths $paths,
    ) {}

    public function handle(array $arguments, array $options): int
    {
        $slug = $this->argument($arguments, 0);

        if ($slug === null || preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug) !== 1) {
            $this->output->error('  Usage: make:plugin <slug>   (lowercase letters, digits and dashes)');
            return 1;
        }

        $dir = $this->paths->plugins() . '/' . $slug;
        if (file_exists($dir)) {
            $this->output->error("  content/plugins/{$slug} already exists.");
            return 1;
        }

        $studly = str_replace(' ', '', ucwords(str_replace('-', ' ', $slug)));
        $namespace = trim($this->option($options, 'namespace', "Plugins\\{$studly}") ?? '', '\\');
        $name = $this->option($options, 'name', ucwords(str_replace('-', ' ', $slug))) ?? $slug;
        $core = self::coreConstraint();
        $admin = $this->hasFlag($options, 'admin');

        foreach (['src', 'resources/views', 'database/migrations', 'assets'] as $sub) {
            mkdir("{$dir}/{$sub}", 0775, true);
        }

        $manifest = [
            'slug' => $slug,
            'name' => $name,
            'version' => '0.1.0',
            'description' => '',
            'requires' => ['core' => $core, 'php' => '>=8.4'],
            'namespace' => $namespace . '\\',
            'provider' => "{$namespace}\\{$studly}Provider",
            'permissions' => (object) [],
        ];
        file_put_contents("{$dir}/plugin.json", json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

        if ($admin) {
            $this->scaffoldAdmin($dir, $slug, $studly, $namespace, $name);
        } else {
            file_put_contents("{$dir}/src/{$studly}Provider.php", <<<PHP
        <?php

        declare(strict_types=1);

        namespace {$namespace};

        use Kestello\Cms\Cms;
        use Kestello\Cms\Extension\PluginProvider;
        use Kestello\Framework\Core\Container;

        final class {$studly}Provider extends PluginProvider
        {
            public function register(Container \$container): void
            {
                // Bind services; runs before anything boots.
            }

            public function boot(Cms \$cms): void
            {
                // Routes, event listeners, filters, template functions, commands…
                // \$cms->routes->get('/{$slug}', fn() => 'Hello from {$name}');
            }
        }

        PHP);
        }

        foreach (['resources/views', 'database/migrations', 'assets'] as $sub) {
            touch("{$dir}/{$sub}/.gitkeep");
        }

        $this->output->success("  Created content/plugins/{$slug}");
        if ($admin) {
            $this->output->muted("  Build its admin screens: npm run build content/plugins/{$slug}");
        }
        $this->output->muted("  Activate it: php bin/cms plugin:activate {$slug}");

        return 0;
    }

    private function scaffoldAdmin(string $dir, string $slug, string $studly, string $namespace, string $name): void
    {
        mkdir("{$dir}/src/Api", 0775, true);
        mkdir("{$dir}/resources/js/pages/admin", 0775, true);
        $preset = self::relativePath($dir, $this->paths->core('build/preset.mjs'));
        $php = static fn(string $s): string => addcslashes($s, "'\\");

        file_put_contents("{$dir}/src/{$studly}Provider.php", <<<PHP
        <?php

        declare(strict_types=1);

        namespace {$namespace};

        use Kestello\Cms\Api\Schema;
        use Kestello\Cms\Cms;
        use Kestello\Cms\Extension\PluginProvider;

        final class {$studly}Provider extends PluginProvider
        {
            public function boot(Cms \$cms): void
            {
                // Who may use the admin screens (administrators hold every capability).
                \$cms->auth->roles->capability('{$slug}.manage', 'Manage {$php($name)}', grantTo: ['admin']);

                // API first: the admin screens only call endpoints like this one,
                // so a script with a token can do everything they do.
                \$cms->api->scope('{$slug}:read', 'Read {$php($name)} data');
                \$cms->api->endpoint(Api\\Summary::class);

                // Screens (components live in resources/js/pages/admin/).
                \$cms->admin->page('', '@{$slug}/admin/Index', '{$php($name)}', '{$slug}.manage', ['label' => '{$php($name)}', 'icon' => 'puzzle-piece', 'group' => 'plugins']);
                \$cms->admin->widget('dashboard', '@{$slug}/admin/Widget', title: '{$php($name)}', size: 'quarter', capability: '{$slug}.manage');

                // Settings → {$php($name)}: rendered from the schema, stored as plugin.{$slug}.general.*
                // Read them with \$this->setting('general.greeting'); secrets with \$this->secret('general.api_key').
                \$cms->admin->settings('general', '{$php($name)}', Schema::object([
                    'greeting' => Schema::string()->max(100)->default('Hello from {$php($name)}'),
                    'api_key' => Schema::string()->max(500)->description('Stored encrypted; never shown again.'),
                ]), labels: ['greeting' => 'Greeting', 'api_key' => 'API key'], secrets: ['api_key'], capability: '{$slug}.manage');

                // More extension points: \$cms->admin->slot('users.edit.panels', …), ->column('users.list', …),
                // ->replace('users.avatar', …). See Kestello\Cms\Admin\AdminRegistry.
            }
        }

        PHP);

        file_put_contents("{$dir}/src/Api/Summary.php", <<<PHP
        <?php

        declare(strict_types=1);

        namespace {$namespace}\Api;

        use Kestello\Cms\Api\ApiRequest;
        use Kestello\Cms\Api\ApiRoute;
        use Kestello\Cms\Api\Endpoint;
        use Kestello\Framework\Settings\Settings;

        /** GET /api/v1/{$slug}/summary. Plugin endpoints live under /{$slug}/. */
        final class Summary extends Endpoint
        {
            public function route(): ApiRoute { return ApiRoute::get('/{$slug}/summary'); }
            public function capability(): ?string { return '{$slug}.manage'; }
            public function scopes(): array { return ['{$slug}:read']; }
            public function summary(): string { return '{$php($name)} at a glance'; }
            public function tags(): array { return ['{$slug}']; }

            public function handle(ApiRequest \$request): mixed
            {
                return [
                    'greeting' => (string) Settings::getInstance()->get('plugin.{$slug}.general.greeting', 'Hello from {$php($name)}'),
                    'checked_at' => gmdate('c'),
                ];
            }
        }

        PHP);

        $js = static fn(string $s): string => addcslashes($s, "'\\");
        file_put_contents("{$dir}/resources/js/pages/admin/Index.vue", <<<VUE
        <script setup>
        import { Head, Link } from '@kestello/app';
        import { useResource, __ } from '@kestello/sdk';
        import { KAlert, KCard, KPageHeader, KTime } from '@kestello/ui';

        defineOptions({ layout: 'Admin' });

        /** /admin/p/{$slug}: data comes from GET /api/v1/{$slug}/summary. */
        const summary = useResource('/{$slug}/summary');
        </script>

        <template>
            <Head :title="__('{$js($name)}')" />
            <KPageHeader :title="__('{$js($name)}')" :description="__('Built with make:plugin --admin. Edit resources/js/pages/admin/Index.vue.')" />
            <KAlert v-if="summary.error.value" tone="danger">{{ summary.error.value.message }}</KAlert>
            <div class="k-cells" style="--k-cols: 3">
                <KCard coord="A1" class="k-span-2" :title="__('Summary')">
                    <p v-if="summary.data.value">{{ summary.data.value.greeting }}</p>
                    <p v-if="summary.data.value" class="k-muted k-small">{{ __('Checked') }} <KTime :value="summary.data.value.checked_at" /></p>
                </KCard>
                <KCard coord="C1" :title="__('Settings')">
                    <Link href="/admin/settings?tab={$slug}.general" class="k-link">{{ __('Change the greeting') }}</Link>
                </KCard>
            </div>
        </template>

        VUE);

        file_put_contents("{$dir}/resources/js/pages/admin/Widget.vue", <<<VUE
        <script setup>
        import { Link } from '@kestello/app';
        import { useResource, __ } from '@kestello/sdk';

        /** Dashboard widget body; the admin draws the titled cell around it. */
        defineProps({ context: { type: Object, default: () => ({}) } });
        const summary = useResource('/{$slug}/summary');
        </script>

        <template>
            <p v-if="summary.data.value">{{ summary.data.value.greeting }}</p>
            <Link href="/admin/p/{$slug}" class="k-link k-small">{{ __('Open') }}</Link>
        </template>

        VUE);

        file_put_contents("{$dir}/vite.config.js", <<<JS
        // Dev-only: `npm run build content/plugins/{$slug}` compiles resources/js into
        // assets/build/ (ship that folder; Node is not needed in production).
        import { defineConfig } from 'vite';
        import { kestello } from '{$preset}';

        export default defineConfig(kestello({ root: import.meta.dirname }));

        JS);
    }

    /** Relative path from directory $from to file $to (both absolute). */
    private static function relativePath(string $from, string $to): string
    {
        $a = explode('/', trim(str_replace('\\', '/', $from), '/'));
        $b = explode('/', trim(str_replace('\\', '/', $to), '/'));
        while ($a !== [] && $b !== [] && $a[0] === $b[0]) {
            array_shift($a);
            array_shift($b);
        }

        return str_repeat('../', count($a)) . implode('/', $b);
    }

    public static function coreConstraint(): string
    {
        $version = Kernel::version();

        if (preg_match('/^(\d+)\.(\d+)/', $version, $m) !== 1) {
            return '*';
        }

        // 0.x releases may break between minors, so pin the minor until 1.0.
        return $m[1] === '0' ? "^0.{$m[2]}" : "^{$m[1]}.0";
    }
}
